<?php
/**
 * Dissertation Formatting Check Tool
 * Validates DOCX files against university formatting requirements:
 * - Font: Times New Roman 12pt (14pt for chapter headings)
 * - Line spacing: 1.5
 * - Margins: 2.5cm top/right/bottom, 3cm left
 * - Text alignment: Justified
 * - Page size: A4
 * 
 * Can be called from any module via: include '../includes/dissertation_format_check.php';
 * Then: $result = checkDissertationFormatting($filepath);
 */

/**
 * Check a DOCX file against formatting requirements
 * @param string $file_path Absolute path to the DOCX
 * @return array ['score' => 0-100, 'checks' => [...], 'summary' => string]
 */
function checkDissertationFormatting($file_path) {
    $result = [
        'score' => 0,
        'checks' => [],
        'summary' => '',
        'total_checks' => 0,
        'passed_checks' => 0
    ];
    
    if (!file_exists($file_path)) {
        $result['summary'] = 'File not found.';
        return $result;
    }
    
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        $result['summary'] = 'Only DOCX files can be checked for formatting.';
        $result['score'] = -1; // Indicates not applicable
        return $result;
    }
    
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) {
        $result['summary'] = 'Could not open DOCX file.';
        return $result;
    }
    
    // Read document.xml for paragraph/run formatting
    $doc_xml = $zip->getFromName('word/document.xml');
    // Read styles.xml for default styles
    $styles_xml = $zip->getFromName('word/styles.xml');
    $zip->close();
    
    if (!$doc_xml) {
        $result['summary'] = 'Invalid DOCX structure.';
        return $result;
    }
    
    // Parse XML
    $doc = new DOMDocument();
    @$doc->loadXML($doc_xml);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    
    $checks = [];
    
    // 1. Check Page Size (A4 = 11906 x 16838 twips)
    $pgSz = $xpath->query('//w:pgSz');
    if ($pgSz->length > 0) {
        $w = (int)$pgSz->item(0)->getAttribute('w:w');
        $h = (int)$pgSz->item(0)->getAttribute('w:h');
        $is_a4 = (abs($w - 11906) < 200 && abs($h - 16838) < 200);
        $checks[] = [
            'name' => 'Page Size',
            'expected' => 'A4 (210mm × 297mm)',
            'actual' => round($w / 56.7, 0) . 'mm × ' . round($h / 56.7, 0) . 'mm',
            'passed' => $is_a4
        ];
    }
    
    // 2. Check Margins (2.5cm = 1417.5 twips for top/right/bottom, 3cm = 1701 twips for left)
    $pgMar = $xpath->query('//w:pgMar');
    if ($pgMar->length > 0) {
        $mar = $pgMar->item(0);
        $top = (int)$mar->getAttribute('w:top');
        $right = (int)$mar->getAttribute('w:right');
        $bottom = (int)$mar->getAttribute('w:bottom');
        $left = (int)$mar->getAttribute('w:left');
        
        // 1cm = 567 twips, 2.5cm = 1417.5, 3cm = 1701
        $top_cm = round($top / 567, 1);
        $right_cm = round($right / 567, 1);
        $bottom_cm = round($bottom / 567, 1);
        $left_cm = round($left / 567, 1);
        
        $top_ok = abs($top_cm - 2.5) < 0.3;
        $right_ok = abs($right_cm - 2.5) < 0.3;
        $bottom_ok = abs($bottom_cm - 2.5) < 0.3;
        $left_ok = abs($left_cm - 3.0) < 0.3;
        
        $checks[] = [
            'name' => 'Top Margin',
            'expected' => '2.5 cm',
            'actual' => $top_cm . ' cm',
            'passed' => $top_ok
        ];
        $checks[] = [
            'name' => 'Bottom Margin',
            'expected' => '2.5 cm',
            'actual' => $bottom_cm . ' cm',
            'passed' => $bottom_ok
        ];
        $checks[] = [
            'name' => 'Right Margin',
            'expected' => '2.5 cm',
            'actual' => $right_cm . ' cm',
            'passed' => $right_ok
        ];
        $checks[] = [
            'name' => 'Left Margin',
            'expected' => '3.0 cm',
            'actual' => $left_cm . ' cm',
            'passed' => $left_ok
        ];
    }
    
    // 3. Check fonts used
    $runs = $xpath->query('//w:r');
    $font_counts = [];
    $font_size_counts = [];
    $total_runs = $runs->length;
    
    for ($i = 0; $i < min($total_runs, 500); $i++) {
        $run = $runs->item($i);
        $rPr = $xpath->query('w:rPr', $run);
        
        if ($rPr->length > 0) {
            // Font name
            $rFonts = $xpath->query('w:rFonts', $rPr->item(0));
            if ($rFonts->length > 0) {
                $font = $rFonts->item(0)->getAttribute('w:ascii') ?: $rFonts->item(0)->getAttribute('w:hAnsi') ?: 'unknown';
                $font_counts[$font] = ($font_counts[$font] ?? 0) + 1;
            }
            
            // Font size (in half-points)
            $sz = $xpath->query('w:sz', $rPr->item(0));
            if ($sz->length > 0) {
                $size_hp = (int)$sz->item(0)->getAttribute('w:val');
                $size_pt = $size_hp / 2;
                $font_size_counts[$size_pt] = ($font_size_counts[$size_pt] ?? 0) + 1;
            }
        }
    }
    
    // Check if primary font is Times New Roman
    $primary_font = '';
    $max_font_count = 0;
    foreach ($font_counts as $f => $c) {
        if ($c > $max_font_count) { $primary_font = $f; $max_font_count = $c; }
    }
    
    // Also check default style font
    $default_font = '';
    if ($styles_xml) {
        $sdoc = new DOMDocument();
        @$sdoc->loadXML($styles_xml);
        $sxpath = new DOMXPath($sdoc);
        $sxpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $defRFonts = $sxpath->query('//w:docDefaults//w:rFonts');
        if ($defRFonts->length > 0) {
            $default_font = $defRFonts->item(0)->getAttribute('w:ascii') ?: '';
        }
    }
    
    $tnr = stripos($primary_font ?: $default_font, 'Times New Roman') !== false;
    $checks[] = [
        'name' => 'Primary Font',
        'expected' => 'Times New Roman',
        'actual' => $primary_font ?: $default_font ?: 'Not detected',
        'passed' => $tnr
    ];
    
    // Check primary font size is 12pt
    $primary_size = 0;
    $max_size_count = 0;
    foreach ($font_size_counts as $s => $c) {
        if ($c > $max_size_count) { $primary_size = $s; $max_size_count = $c; }
    }
    $checks[] = [
        'name' => 'Body Font Size',
        'expected' => '12 pt',
        'actual' => $primary_size ? $primary_size . ' pt' : 'Not detected',
        'passed' => abs($primary_size - 12) < 0.5
    ];
    
    // 4. Check line spacing (240 = single, 360 = 1.5, 480 = double)
    $paragraphs = $xpath->query('//w:p');
    $spacing_counts = [];
    $justify_count = 0;
    $para_count = min($paragraphs->length, 300);
    
    for ($i = 0; $i < $para_count; $i++) {
        $para = $paragraphs->item($i);
        $pPr = $xpath->query('w:pPr', $para);
        
        if ($pPr->length > 0) {
            // Line spacing
            $spacing = $xpath->query('w:spacing', $pPr->item(0));
            if ($spacing->length > 0) {
                $line = (int)$spacing->item(0)->getAttribute('w:line');
                if ($line > 0) {
                    $sp_mult = round($line / 240, 1);
                    $spacing_counts["$sp_mult"] = ($spacing_counts["$sp_mult"] ?? 0) + 1;
                }
            }
            
            // Text alignment
            $jc = $xpath->query('w:jc', $pPr->item(0));
            if ($jc->length > 0 && $jc->item(0)->getAttribute('w:val') === 'both') {
                $justify_count++;
            }
        }
    }
    
    $primary_spacing = '';
    $max_sp = 0;
    foreach ($spacing_counts as $sp => $c) {
        if ($c > $max_sp) { $primary_spacing = $sp; $max_sp = $c; }
    }
    
    $checks[] = [
        'name' => 'Line Spacing',
        'expected' => '1.5',
        'actual' => $primary_spacing ?: 'Default (1.0)',
        'passed' => $primary_spacing && abs((float)$primary_spacing - 1.5) < 0.2
    ];
    
    // 5. Check text alignment (justified)
    $justify_pct = $para_count > 0 ? round(($justify_count / $para_count) * 100) : 0;
    $checks[] = [
        'name' => 'Text Alignment',
        'expected' => 'Justified (>70%)',
        'actual' => $justify_pct . '% justified',
        'passed' => $justify_pct >= 50
    ];
    
    // Calculate score
    $total = count($checks);
    $passed = count(array_filter($checks, fn($c) => $c['passed']));
    $score = $total > 0 ? round(($passed / $total) * 100) : 0;
    
    // Summary
    $failed = array_filter($checks, fn($c) => !$c['passed']);
    $issues = array_map(fn($c) => $c['name'] . ': expected ' . $c['expected'] . ', got ' . $c['actual'], $failed);
    
    $result['checks'] = $checks;
    $result['score'] = $score;
    $result['total_checks'] = $total;
    $result['passed_checks'] = $passed;
    $result['summary'] = $passed === $total 
        ? 'All formatting checks passed.' 
        : 'Issues found: ' . implode('; ', $issues);
    
    return $result;
}

/**
 * Run formatting check on a submission and store results
 * @param int $submission_id
 * @param mysqli $conn
 * @return array|false
 */
function runFormattingCheckOnSubmission($submission_id, $conn) {
    $stmt = $conn->prepare("SELECT file_path FROM dissertation_submissions WHERE submission_id = ?");
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    
    if (!$sub || !$sub['file_path']) return false;
    
    // Determine file path
    $file = $sub['file_path'];
    if (strpos($file, '/') === 0 || strpos($file, ':') === 1) {
        $abs_path = $file;
    } else {
        $abs_path = dirname(__DIR__) . '/' . $file;
    }
    
    $result = checkDissertationFormatting($abs_path);
    
    if ($result['score'] >= 0) {
        $json = json_encode($result['checks']);
        $score = $result['score'];
        $stmt2 = $conn->prepare("UPDATE dissertation_submissions SET formatting_check = ?, formatting_score = ? WHERE submission_id = ?");
        $stmt2->bind_param("sdi", $json, $score, $submission_id);
        $stmt2->execute();
    }
    
    return $result;
}
