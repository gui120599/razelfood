<?php

/**
 * Gera os assets de marca RazelFood (favicons/ícones/lockup) em public/images/brand/
 * a partir dos arquivos-fonte em public/img/ (ver docs/identidade-visual-design-system.md, seção 4.3).
 *
 * Uso: vendor/bin/sail php resources/scripts/generate-brand-assets.php
 * Reexecutável — rodar de novo sempre que os arquivos de origem em public/img/ mudarem.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';

$sourceDir = public_path('img');
$destDir = public_path('images/brand');

if (! is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

function loadPng(string $path): GdImage
{
    $image = imagecreatefrompng($path);
    imagepalettetotruecolor($image);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    return $image;
}

function resizeToSquare(GdImage $src, int $size): GdImage
{
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

    return $dst;
}

function resizeToWidth(GdImage $src, int $width): GdImage
{
    $srcWidth = imagesx($src);
    $srcHeight = imagesy($src);
    $height = (int) round($srcHeight * ($width / $srcWidth));

    $dst = imagecreatetruecolor($width, $height);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);

    return $dst;
}

// Ícone/favicon a partir de RAZELFOOD.png (1080x1080, já quadrado)
$icon = loadPng($sourceDir.'/RAZELFOOD.png');
foreach ([16, 32, 180, 512] as $size) {
    $resized = resizeToSquare($icon, $size);
    imagepng($resized, $destDir."/razelfood-icon-{$size}.png", 9);
    imagedestroy($resized);
    echo "Gerado razelfood-icon-{$size}.png\n";
}
imagedestroy($icon);

// Lockup horizontal a partir de LOGO RAZEL FOOD.png (3766x1654), redimensionado para largura web
$lockup = loadPng($sourceDir.'/LOGO RAZEL FOOD.png');
$resizedLockup = resizeToWidth($lockup, 800);
imagepng($resizedLockup, $destDir.'/razelfood-lockup.png', 9);
imagedestroy($resizedLockup);
imagedestroy($lockup);
echo "Gerado razelfood-lockup.png\n";

echo "Assets de marca gerados em {$destDir}\n";
