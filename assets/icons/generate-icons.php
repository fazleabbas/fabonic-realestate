<?php
/**
 * Generate PWA icons — Run once: php generate-icons.php
 * Creates solid-color icons with "RE" text for FIS Real Estate.
 */
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$bgColor = [26, 107, 74]; // #1a6b4a
$textColor = [255, 255, 255];

foreach ($sizes as $size) {
    $img = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
    $fg = imagecolorallocate($img, $textColor[0], $textColor[1], $textColor[2]);

    imagefill($img, 0, 0, $bg);

    // Draw "RE" text centered
    $fontSize = (int)($size * 0.35);
    $text = 'RE';

    // Use built-in font (1-5 scale)
    $fontScale = max(1, min(5, (int)($size / 40)));
    $charWidth = imagefontwidth($fontScale);
    $charHeight = imagefontheight($fontScale);
    $textWidth = $charWidth * strlen($text);
    $x = (int)(($size - $textWidth) / 2);
    $y = (int)(($size - $charHeight) / 2);
    imagestring($img, $fontScale, $x, $y, $text, $fg);

    $filename = __DIR__ . '/icon-' . $size . 'x' . $size . '.png';
    imagepng($img, $filename);
    imagedestroy($img);
    echo "Created: icon-{$size}x{$size}.png\n";
}

echo "Done! All icons generated.\n";
