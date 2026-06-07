<?php

/*
|--------------------------------------------------------------------------
| Pre-built ad templates
|--------------------------------------------------------------------------
|
| 20 hand-built, screenshot-validated HTML ad templates filled deterministically
| by TemplateAdRenderer (no Gemini). Each renders at a fixed IAB/social size.
|
|   id          stable key referenced by jobs + QA commands
|   file        resources/ad-templates/<file>
|   width/height ad dimensions in px
|   kind        'brand' (always usable) | 'product' (needs product data)
|   needs_image true → only assign when real imagery is available
|   label       short human description
|
*/

return [
    // --- Medium rectangle 300x250 ---
    ['id' => 'bold-type-300x250',    'file' => 'bold-type-300x250.html',    'width' => 300, 'height' => 250, 'kind' => 'brand',   'needs_image' => false, 'label' => 'Bold type on gradient'],
    ['id' => 'image-overlay-300x250','file' => 'image-overlay-300x250.html','width' => 300, 'height' => 250, 'kind' => 'brand',   'needs_image' => true,  'label' => 'Image + gradient overlay'],
    ['id' => 'split-300x250',        'file' => 'split-300x250.html',        'width' => 300, 'height' => 250, 'kind' => 'brand',   'needs_image' => true,  'label' => 'Color panel + image split'],
    ['id' => 'card-300x250',         'file' => 'card-300x250.html',         'width' => 300, 'height' => 250, 'kind' => 'brand',   'needs_image' => false, 'label' => 'Clean white card'],

    // --- Large rectangle 336x280 ---
    ['id' => 'image-bottom-bar-336x280', 'file' => 'image-bottom-bar-336x280.html', 'width' => 336, 'height' => 280, 'kind' => 'brand', 'needs_image' => true, 'label' => 'Image with bottom CTA bar'],

    // --- Square 250x250 ---
    ['id' => 'centered-square-250x250', 'file' => 'centered-square-250x250.html', 'width' => 250, 'height' => 250, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Centered type square'],

    // --- Leaderboard 728x90 ---
    ['id' => 'leaderboard-728x90', 'file' => 'leaderboard-728x90.html', 'width' => 728, 'height' => 90, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Logo · headline · CTA row'],

    // --- Billboard 970x250 ---
    ['id' => 'billboard-970x250', 'file' => 'billboard-970x250.html', 'width' => 970, 'height' => 250, 'kind' => 'brand', 'needs_image' => true, 'label' => 'Text left, image right third'],

    // --- Wide skyscraper / skyscraper 160x600 ---
    ['id' => 'skyscraper-image-160x600', 'file' => 'skyscraper-image-160x600.html', 'width' => 160, 'height' => 600, 'kind' => 'brand', 'needs_image' => true,  'label' => 'Vertical image + copy'],
    ['id' => 'skyscraper-type-160x600',  'file' => 'skyscraper-type-160x600.html',  'width' => 160, 'height' => 600, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Vertical stacked type'],

    // --- Half page 300x600 ---
    ['id' => 'halfpage-image-300x600', 'file' => 'halfpage-image-300x600.html', 'width' => 300, 'height' => 600, 'kind' => 'brand', 'needs_image' => true,  'label' => 'Image top, content card'],
    ['id' => 'halfpage-type-300x600',  'file' => 'halfpage-type-300x600.html',  'width' => 300, 'height' => 600, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Editorial type, no image'],

    // --- Mobile banners ---
    ['id' => 'mobile-banner-320x50',  'file' => 'mobile-banner-320x50.html',  'width' => 320, 'height' => 50,  'kind' => 'brand', 'needs_image' => false, 'label' => 'Logo + headline + CTA inline'],
    ['id' => 'mobile-large-320x100',  'file' => 'mobile-large-320x100.html',  'width' => 320, 'height' => 100, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Large mobile banner'],

    // --- Social square 1080x1080 ---
    ['id' => 'social-image-1080x1080', 'file' => 'social-image-1080x1080.html', 'width' => 1080, 'height' => 1080, 'kind' => 'brand', 'needs_image' => true,  'label' => 'Social square, image hero'],
    ['id' => 'social-type-1080x1080',  'file' => 'social-type-1080x1080.html',  'width' => 1080, 'height' => 1080, 'kind' => 'brand', 'needs_image' => false, 'label' => 'Social square, editorial'],

    // --- Social portrait 1080x1350 ---
    ['id' => 'social-portrait-1080x1350', 'file' => 'social-portrait-1080x1350.html', 'width' => 1080, 'height' => 1350, 'kind' => 'brand', 'needs_image' => true, 'label' => 'Portrait, image + brand block'],

    // --- Story 1080x1920 ---
    ['id' => 'story-1080x1920', 'file' => 'story-1080x1920.html', 'width' => 1080, 'height' => 1920, 'kind' => 'brand', 'needs_image' => true, 'label' => 'Full-bleed story'],

    // --- FB share 1200x630 ---
    ['id' => 'share-split-1200x630', 'file' => 'share-split-1200x630.html', 'width' => 1200, 'height' => 630, 'kind' => 'brand', 'needs_image' => true, 'label' => 'Share card, text + image'],

    // --- Product-capable (also used by ecommerce phase) ---
    ['id' => 'product-card-300x250', 'file' => 'product-card-300x250.html', 'width' => 300, 'height' => 250, 'kind' => 'product', 'needs_image' => true, 'label' => 'Product card with price'],
];
