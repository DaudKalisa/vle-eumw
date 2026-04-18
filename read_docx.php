<?php
// Read DOCX by extracting XML directly (avoids image processing issues)
$file = 'C:/Users/Daud Kalisa Phiri/Documents/DISSERTATION MODULE GUILINE FOR EXPLOITS UNIVERSITY.docx';

if (!file_exists($file)) {
    die("File not found: $file");
}

$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    die("Cannot open DOCX as ZIP");
}

$xml = $zip->getFromName('word/document.xml');
$zip->close();

if (!$xml) {
    die("Cannot read document.xml from DOCX");
}

// Strip XML tags but preserve paragraph breaks
$xml = str_replace('</w:p>', "\n", $xml);
$xml = str_replace('</w:tr>', "\n", $xml);
$xml = str_replace('<w:tab/>', "\t", $xml);
$text = strip_tags($xml);
$text = preg_replace('/\n{3,}/', "\n\n", $text);
$text = trim($text);

file_put_contents(__DIR__ . '/dissertation_guidelines_raw.txt', $text);
echo "Written " . strlen($text) . " bytes\n";
