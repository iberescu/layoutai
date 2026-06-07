<?php

/*
|--------------------------------------------------------------------------
| Brand font → closest Google Font
|--------------------------------------------------------------------------
|
| FontMatchingService detects the typefaces a brand actually uses (from its
| CSS / @font-face / Google Fonts <link>, or inferred from the logo) and maps
| each to the nearest free Google Font so generated ads echo the brand's
| typography. Keys are lowercased, stripped of quotes/weights. Anything not
| here falls through to a Gemini suggestion, then to the generic-family
| defaults below.
|
*/

return [

    // Already Google Fonts — pass through (keeps exact match when a site
    // already links them).
    'passthrough' => [
        'inter', 'roboto', 'open sans', 'lato', 'montserrat', 'poppins',
        'raleway', 'nunito', 'nunito sans', 'work sans', 'rubik', 'mulish',
        'manrope', 'dm sans', 'dm serif display', 'playfair display', 'lora',
        'merriweather', 'source sans pro', 'source sans 3', 'source serif pro',
        'oswald', 'bebas neue', 'archivo', 'archivo black', 'space grotesk',
        'sora', 'figtree', 'plus jakarta sans', 'outfit', 'epilogue', 'urbanist',
        'libre franklin', 'libre baskerville', 'pt sans', 'pt serif', 'karla',
        'josefin sans', 'quicksand', 'cabin', 'barlow', 'titillium web',
        'fira sans', 'ibm plex sans', 'ibm plex serif', 'ibm plex mono',
        'noto sans', 'noto serif', 'crimson text', 'cormorant garamond',
        'eb garamond', 'spectral', 'bitter', 'dosis', 'comfortaa', 'jost',
        'red hat display', 'albert sans', 'hanken grotesk', 'instrument sans',
    ],

    // Common proprietary / system faces → nearest Google equivalent.
    'aliases' => [
        // Grotesque / neo-grotesque sans
        'helvetica'           => 'Inter',
        'helvetica neue'      => 'Inter',
        'arial'               => 'Inter',
        'arial nova'          => 'Inter',
        'liberation sans'     => 'Inter',
        'segoe ui'            => 'Inter',
        'segoe'               => 'Inter',
        'tahoma'              => 'Inter',
        'verdana'             => 'Inter',
        'frutiger'            => 'Mulish',
        'univers'             => 'Inter',
        'din'                 => 'Archivo',
        'din next'            => 'Archivo',
        'akzidenz-grotesk'    => 'Space Grotesk',
        'neue haas grotesk'   => 'Inter',
        'aktiv grotesk'       => 'Hanken Grotesk',
        'circular'            => 'Manrope',
        'gilroy'              => 'Manrope',
        'gotham'              => 'Montserrat',
        'proxima nova'        => 'Montserrat',
        'avenir'              => 'Nunito Sans',
        'avenir next'         => 'Nunito Sans',
        'futura'              => 'Jost',
        'century gothic'      => 'Jost',
        'apple system'        => 'Inter',
        '-apple-system'       => 'Inter',
        'system-ui'           => 'Inter',
        'sf pro'              => 'Inter',
        'sf pro display'      => 'Inter',
        'sf pro text'         => 'Inter',
        'roboto flex'         => 'Roboto',
        'calibri'             => 'Carlito',
        'trebuchet ms'        => 'Fira Sans',

        // Humanist / rounded
        'myriad'              => 'Source Sans 3',
        'myriad pro'          => 'Source Sans 3',
        'gill sans'           => 'Cabin',
        'lucida grande'       => 'Cabin',
        'museo sans'          => 'Mulish',

        // Serif / transitional / old-style
        'times'               => 'PT Serif',
        'times new roman'     => 'PT Serif',
        'georgia'             => 'Lora',
        'garamond'            => 'EB Garamond',
        'adobe garamond'      => 'EB Garamond',
        'baskerville'         => 'Libre Baskerville',
        'caslon'              => 'Libre Caslon Text',
        'minion'              => 'Spectral',
        'minion pro'          => 'Spectral',
        'didot'               => 'Playfair Display',
        'bodoni'              => 'Playfair Display',
        'cambria'             => 'PT Serif',
        'palatino'            => 'Spectral',
        'freight'             => 'Bitter',
        'tiempos'             => 'Lora',

        // Slab / display
        'rockwell'            => 'Bitter',
        'museo slab'          => 'Bitter',
        'impact'              => 'Anton',
        'haettenschweiler'    => 'Anton',

        // Monospace
        'consolas'            => 'IBM Plex Mono',
        'menlo'               => 'IBM Plex Mono',
        'monaco'              => 'IBM Plex Mono',
        'courier'             => 'Roboto Mono',
        'courier new'         => 'Roboto Mono',
        'sf mono'             => 'JetBrains Mono',
    ],

    // Final fallbacks by CSS generic family, when nothing else matches.
    'defaults' => [
        'serif'      => 'Lora',
        'sans-serif' => 'Inter',
        'monospace'  => 'IBM Plex Mono',
        'display'    => 'Space Grotesk',
        'cursive'    => 'Caveat',
        'fallback'   => 'Inter',
    ],
];
