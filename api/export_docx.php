<?php
/**
 * Document API — two endpoints:
 * 1) GET  ?file=path/to/file  → Convert document to HTML for in-system reading
 * 2) POST html_content+filename → Convert edited HTML back to DOCX for download
 *
 * Supported read formats: .docx, .doc, .odt, .rtf, .txt, .pdf (passthrough)
 * Uses PHPWord for Office/ODF formats, plain read for text
 */

// Buffer all output so PHP warnings/notices cannot corrupt the JSON body
ob_start();

// Suppress display of errors — log them instead (prevents notice pollution in JSON)
@ini_set('display_errors', '0');
error_reporting(E_ERROR); // Only fatal errors reach the error handler

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['vle_user_id'])) {
    ob_end_clean();
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

/**
 * Sanitize a DOCX file: replace unsupported images (EMF, WMF, TIFF, BMP)
 * with a tiny transparent PNG so PhpWord won't crash.
 * Returns path to a temp sanitized copy, or the original if no changes needed.
 */
function sanitizeDocx(string $abs): string {
    $zip = new ZipArchive();
    if ($zip->open($abs) !== true) {
        return $abs; // let PhpWord handle the error
    }

    // Unsupported image extensions that crash PhpWord
    $badExts = ['emf', 'wmf', 'tiff', 'tif', 'bmp'];
    $needsFix = false;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (stripos($name, 'word/media/') === 0) {
            $imgExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($imgExt, $badExts)) {
                $needsFix = true;
                break;
            }
        }
    }
    $zip->close();

    if (!$needsFix) {
        return $abs;
    }

    // Create a temp copy to modify
    $tmp = sys_get_temp_dir() . '/vle_docx_' . md5($abs . time()) . '.docx';
    copy($abs, $tmp);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        return $abs;
    }

    // 1x1 transparent PNG (89 bytes)
    $placeholder = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );

    $replacements = []; // old name => new name mapping

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (stripos($name, 'word/media/') === 0) {
            $imgExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($imgExt, $badExts)) {
                // Replace with PNG data
                $newName = preg_replace('/\.' . preg_quote($imgExt, '/') . '$/i', '.png', $name);
                $zip->deleteName($name);
                $zip->addFromString($newName, $placeholder);
                $replacements[$name] = $newName;
            }
        }
    }

    // Update content references in rels and document XML
    if (!empty($replacements)) {
        $xmlFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('/\.(xml|rels)$/i', $n)) {
                $xmlFiles[] = $n;
            }
        }
        foreach ($xmlFiles as $xmlFile) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) continue;
            $changed = false;
            foreach ($replacements as $old => $new) {
                // References use relative paths like media/image1.emf
                $oldRef = basename($old);
                $newRef = basename($new);
                if (strpos($content, $oldRef) !== false) {
                    $content = str_replace($oldRef, $newRef, $content);
                    $changed = true;
                }
                // Also fix content type for these extensions
                $oldExt = pathinfo($old, PATHINFO_EXTENSION);
                $content = str_replace(
                    'ContentType="image/x-' . $oldExt . '"',
                    'ContentType="image/png"',
                    $content
                );
                $content = str_replace(
                    'ContentType="image/' . $oldExt . '"',
                    'ContentType="image/png"',
                    $content
                );
                // EMF specific content type
                $content = str_replace(
                    'Extension="' . $oldExt . '" ContentType="image/x-emf"',
                    'Extension="png" ContentType="image/png"',
                    $content
                );
                $content = str_replace(
                    'Extension="' . $oldExt . '"',
                    'Extension="png"',
                    $content
                );
            }
            if ($changed || strpos($content, 'image/x-emf') !== false || strpos($content, 'image/x-wmf') !== false) {
                // Clean up any remaining emf/wmf content types
                $content = str_replace('image/x-emf', 'image/png', $content);
                $content = str_replace('image/x-wmf', 'image/png', $content);
                $zip->addFromString($xmlFile, $content);
            }
        }
    }

    $zip->close();
    return $tmp;
}

/**
 * Convert a PhpWord object to HTML body content.
 */
function phpWordToHtml($phpWord): string {
    $writer = WordIOFactory::createWriter($phpWord, 'HTML');
    ob_start();
    $writer->save('php://output');
    $html = ob_get_clean();
    if (preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $m)) {
        return $m[1];
    }
    return $html;
}

// ─────────────────────────────────────────────────────
// GET: Convert uploaded document → HTML
// ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['file'])) {
    header('Content-Type: application/json; charset=utf-8');

    $relative = $_GET['file'];
    // Security: prevent path traversal beyond uploads/ or known dirs
    $relative = str_replace('\\', '/', $relative);
    $relative = ltrim($relative, './');
    // Strip any leading ../
    while (strpos($relative, '../') !== false) {
        $relative = str_replace('../', '', $relative);
    }

    $abs = realpath(__DIR__ . '/../' . $relative);
    if (!$abs || !file_exists($abs)) {
        http_response_code(404);
        exit(json_encode(['error' => 'File not found']));
    }

    // Must be within the project tree
    $project_root = realpath(__DIR__ . '/../');
    if (strpos($abs, $project_root) !== 0) {
        http_response_code(403);
        exit(json_encode(['error' => 'Access denied']));
    }

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

    // Build absolute URL (needed for Google Docs Viewer which requires a public URL)
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $base     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    $abs_url  = $scheme . '://' . $host . $base . $relative;

    // If PHPWord is not installed/available, fall back to Google Docs Viewer
    if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
        ob_end_clean();
        // PDF passthrough still works without PHPWord
        if ($ext === 'pdf') {
            echo json_encode(['format' => 'pdf', 'url' => $relative]);
        } else {
            echo json_encode(['format' => 'google_docs', 'url' => $relative, 'abs_url' => $abs_url]);
        }
        exit;
    }

    try {
        $html = '';
        $tempFile = null; // track temp files for cleanup

        if ($ext === 'docx') {
            $safeFile = sanitizeDocx($abs);
            if ($safeFile !== $abs) $tempFile = $safeFile;
            $reader = WordIOFactory::createReader('Word2007');
            $phpWord = $reader->load($safeFile);
            $html = phpWordToHtml($phpWord);
        } elseif ($ext === 'doc') {
            $reader = WordIOFactory::createReader('MsDoc');
            $phpWord = $reader->load($abs);
            $html = phpWordToHtml($phpWord);
        } elseif ($ext === 'odt') {
            $reader = WordIOFactory::createReader('ODText');
            $phpWord = $reader->load($abs);
            $html = phpWordToHtml($phpWord);
        } elseif ($ext === 'rtf') {
            $reader = WordIOFactory::createReader('RTF');
            $phpWord = $reader->load($abs);
            $html = phpWordToHtml($phpWord);
        } elseif ($ext === 'txt') {
            $content = file_get_contents($abs);
            $html = '<div style="white-space:pre-wrap; font-family:Consolas,monospace; line-height:1.7;">' .
                     htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        } elseif ($ext === 'pdf') {
            echo json_encode(['format' => 'pdf', 'url' => $relative]);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported format: .' . $ext . '. Supported: docx, doc, odt, rtf, txt, pdf']);
            exit;
        }

        // Cleanup temp file
        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        // Ensure valid UTF-8 to prevent json_encode returning false
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        ob_end_clean(); // Discard any stray PHP notice output
        $encoded = json_encode(
            ['html' => $html, 'format' => $ext],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to encode document: ' . json_last_error_msg()]);
        } else {
            echo $encoded;
        }

    } catch (\Throwable $e) {
        ob_end_clean();
        // Fall back to Google Docs Viewer rather than showing an error page
        echo json_encode(['format' => 'google_docs', 'url' => $relative, 'abs_url' => $abs_url, 'error_detail' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────
// POST: Convert edited HTML → DOCX download
// ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $html_content = $_POST['html_content'] ?? '';
    $filename = $_POST['filename'] ?? 'document.docx';

    if (empty($html_content)) {
        http_response_code(400);
        exit('No content provided');
    }

    // Sanitize filename
    $filename = preg_replace('/[^\w\s\-\.]/', '', $filename);
    if (!preg_match('/\.docx$/i', $filename)) {
        $filename = pathinfo($filename, PATHINFO_FILENAME) . '.docx';
    }
    if (empty(pathinfo($filename, PATHINFO_FILENAME))) {
        $filename = 'edited_document.docx';
    }

    try {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'marginTop'    => 720,
            'marginBottom' => 720,
            'marginLeft'   => 1080,
            'marginRight'  => 1080,
        ]);

        // Clean HTML for PhpWord
        $html_content = preg_replace('/<br\s*\/?>/i', '<br/>', $html_content);
        $html_content = preg_replace('/<p>\s*<\/p>/i', '<p>&nbsp;</p>', $html_content);
        $fullHtml = '<html><body>' . $html_content . '</body></html>';

        Html::addHtml($section, $fullHtml, false, false);

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save('php://output');
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        exit('Error generating document: ' . $e->getMessage());
    }
}

ob_end_clean();
http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
exit(json_encode(['error' => 'Method not allowed']));
