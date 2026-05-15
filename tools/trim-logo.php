<?php
// Trim white background, make near-white transparent, save as logo.png.
$src = __DIR__ . '/logo-source.png';
$dst = __DIR__ . '/logo.png';
$im = imagecreatefrompng($src);
[$w, $h] = [imagesx($im), imagesy($im)];

// Find tight bounding box around any pixel materially darker than near-white.
$minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        if ($r < 230 || $g < 230 || $b < 230) {
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
}
$pad = 8;
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);
$cw = $maxX - $minX + 1;
$ch = $maxY - $minY + 1;

// Produce a transparent-background PNG with white turned to alpha 0.
$out = imagecreatetruecolor($cw, $ch);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, $cw - 1, $ch - 1, $transparent);

for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
        $rgb = imagecolorat($im, $minX + $x, $minY + $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $alpha = 255 - (int) round((min($r, $g, $b) / 255) * 255);
        if ($alpha === 0) continue;
        $a127 = 127 - (int) round(($alpha / 255) * 127);
        $color = imagecolorallocatealpha($out, $r, $g, $b, $a127);
        imagesetpixel($out, $x, $y, $color);
    }
}
imagepng($out, $dst, 9);
$s = getimagesize($dst);
echo "saved {$dst} {$s[0]}x{$s[1]} (" . filesize($dst) . " bytes)\n";
