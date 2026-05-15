<?php
// Trim transparent margins around a PNG that already has an alpha channel.
// Falls back to near-white detection for sources without alpha.
$src = __DIR__ . '/logo-source.png';
$dst = __DIR__ . '/logo.png';
$im = imagecreatefrompng($src);
if (! $im) {
    fwrite(STDERR, "cannot open $src\n");
    exit(1);
}
[$w, $h] = [imagesx($im), imagesy($im)];

// Detect whether the source actually uses its alpha channel.
$hasAlpha = false;
foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as $p) {
    $rgb = imagecolorat($im, $p[0], $p[1]);
    if ((($rgb >> 24) & 0x7F) > 0) { $hasAlpha = true; break; }
}

// "Visibility" of a pixel: 0 = fully erasable, 1 = solid.
$visAt = $hasAlpha
    ? function (int $rgb): float {
        $a127 = ($rgb >> 24) & 0x7F;
        return (127 - $a127) / 127.0; // 0 = transparent, 1 = opaque
    }
    : function (int $rgb): float {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return (255 - min($r, $g, $b)) / 255.0; // 0 = white, 1 = saturated/dark
    };

// Bounding box of visible pixels.
$bboxThreshold = 0.10;
$minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        if ($visAt(imagecolorat($im, $x, $y)) > $bboxThreshold) {
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
}
if ($maxX < 0) {
    fwrite(STDERR, "no visible ink found\n");
    exit(1);
}
$pad = 12;
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($w - 1, $maxX + $pad);
$maxY = min($h - 1, $maxY + $pad);
$cw = $maxX - $minX + 1;
$ch = $maxY - $minY + 1;

$out = imagecreatetruecolor($cw, $ch);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, $cw - 1, $ch - 1, $transparent);

if ($hasAlpha) {
    imagecopy($out, $im, 0, 0, $minX, $minY, $cw, $ch);
} else {
    for ($y = 0; $y < $ch; $y++) {
        for ($x = 0; $x < $cw; $x++) {
            $rgb = imagecolorat($im, $minX + $x, $minY + $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $ink = (255 - min($r, $g, $b)) / 255.0;
            if ($ink < 0.04) continue;
            $a127 = 127 - (int) round($ink * 127);
            $color = imagecolorallocatealpha($out, $r, $g, $b, $a127);
            imagesetpixel($out, $x, $y, $color);
        }
    }
}
imagepng($out, $dst, 9);
$s = getimagesize($dst);
echo 'mode=' . ($hasAlpha ? 'alpha' : 'white-key') . " saved {$dst} {$s[0]}x{$s[1]} (" . filesize($dst) . " bytes)\n";
