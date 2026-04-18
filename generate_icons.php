<?php
// Generate PWA icons from Logo.png
$src = imagecreatefrompng(__DIR__ . '/assets/img/Logo.png');
if (!$src) { echo 'Failed to load logo'; exit(1); }
$w = imagesx($src); $h = imagesy($src);
echo "Logo size: {$w}x{$h}\n";

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$icon_dir = __DIR__ . '/assets/icons';
if (!is_dir($icon_dir)) mkdir($icon_dir, 0755, true);

foreach ($sizes as $s) {
    $dst = imagecreatetruecolor($s, $s);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $trans);
    
    // Center the logo maintaining aspect ratio
    $ratio = min($s / $w, $s / $h);
    $new_w = (int)($w * $ratio);
    $new_h = (int)($h * $ratio);
    $x = (int)(($s - $new_w) / 2);
    $y = (int)(($s - $new_h) / 2);
    
    imagecopyresampled($dst, $src, $x, $y, 0, 0, $new_w, $new_h, $w, $h);
    $path = "$icon_dir/icon-{$s}.png";
    imagepng($dst, $path);
    imagedestroy($dst);
    echo "Created icon-{$s}.png\n";
}
imagedestroy($src);
echo "Done!\n";
