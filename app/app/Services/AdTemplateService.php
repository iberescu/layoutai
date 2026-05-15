<?php

namespace App\Services;

use App\Models\AdVariant;
use App\Models\BrandProfile;

class AdTemplateService
{
    public function buildHtml(AdVariant $variant, BrandProfile $brand, ?string $imageUrl, ?string $logoUrl): array
    {
        $primary   = $brand->primaryColor();
        $accent    = $brand->accentColor();
        $textColor = '#FFFFFF';
        $layout    = $variant->layout_type ?: 'image-background-with-card-overlay';
        $w = $variant->size_width;
        $h = $variant->size_height;

        $fontSize = max(12, min(28, (int) round(min($w, $h) * 0.10)));
        $subFontSize = max(10, (int) round($fontSize * 0.55));

        $headline    = e($variant->headline ?? '');
        $subheadline = e($variant->subheadline ?? '');
        $cta         = e($variant->cta ?? 'Learn more');

        $bgStyle = $imageUrl
            ? "background-image: url('" . e($imageUrl) . "'); background-size: cover; background-position: center;"
            : "background: linear-gradient(135deg, {$primary}, {$accent});";

        $css = <<<CSS
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: {$w}px; height: {$h}px; overflow: hidden;
            font-family: 'Inter', system-ui, -apple-system, Segoe UI, sans-serif; color: {$textColor}; }
.ad { position: relative; width: {$w}px; height: {$h}px; {$bgStyle} display: flex; }
.overlay { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(15,23,42,0.05) 0%, rgba(15,23,42,0.75) 100%); }
.content { position: relative; z-index: 2; padding: 12px; display: flex; flex-direction: column; justify-content: flex-end; width: 100%; gap: 4px; }
.logo { position: absolute; top: 10px; left: 10px; max-width: 30%; max-height: 18%; z-index: 3; }
.headline { font-size: {$fontSize}px; font-weight: 800; line-height: 1.05; letter-spacing: -0.01em; }
.subheadline { font-size: {$subFontSize}px; font-weight: 500; opacity: 0.9; }
.cta { display: inline-block; align-self: flex-start; margin-top: 6px; padding: 6px 12px; background: {$primary};
       color: #fff; font-weight: 700; font-size: {$subFontSize}px; border-radius: 999px; }
CSS;

        $logoTag = $logoUrl ? "<img class=\"logo\" src=\"" . e($logoUrl) . "\" alt=\"\">" : '';

        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>{$css}</style></head>
<body><div class="ad">{$logoTag}<div class="overlay"></div>
<div class="content">
    <div class="headline">{$headline}</div>
    <div class="subheadline">{$subheadline}</div>
    <span class="cta">{$cta}</span>
</div></div></body></html>
HTML;

        return ['html' => $html, 'css' => $css];
    }
}
