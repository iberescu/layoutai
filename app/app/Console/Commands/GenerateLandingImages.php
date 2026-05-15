<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GenerateLandingImages extends Command
{
    protected $signature = 'landing:generate-images {--model=gemini-3.1-flash-image-preview}';
    protected $description = 'Generate landing-page artwork with Gemini Nano Banana 2.';

    /** @var array<int,array{slug:string,prompt:string,aspect:string}> */
    private array $jobs = [
        [
            'slug' => 'hero-saas',
            'aspect' => '4:5',
            'prompt' => 'Editorial-quality display ad creative for a B2B SaaS analytics product. Vibrant deep blue to purple gradient background. A clean 3D dashboard chart floats centered, with a subtle product UI screenshot tilted in perspective. Big white sans-serif headline "Smarter reporting." in the lower left. Premium, modern, soft cinematic lighting. No watermarks, no text artifacts, no logos. Square-ish portrait composition.',
        ],
        [
            'slug' => 'hero-coffee',
            'aspect' => '4:5',
            'prompt' => 'Premium display ad creative for a specialty coffee brand. Warm amber to deep brown gradient background. A glossy ceramic cup of latte art photographed from above-tilted angle, steam rising softly. Cream-colored bold sans-serif headline "Fresh roasted daily." floating in the upper portion. Cozy, artisanal mood, soft natural light. No watermarks, no extra logos, no garbled text.',
        ],
        [
            'slug' => 'hero-florist',
            'aspect' => '4:5',
            'prompt' => 'Elegant display ad for a high-end florist. Soft pink to rose gradient background. A lush bouquet of peonies and ranunculus arranged off-center. Bold white serif headline "Send a premium bouquet." in the lower third. Romantic, editorial, magazine-cover feel, golden hour lighting. No watermarks, no random text, clean composition.',
        ],
        [
            'slug' => 'hero-gym',
            'aspect' => '4:5',
            'prompt' => 'Athletic display ad creative for a premium fitness brand. Bold emerald green to teal gradient background. A close-up of an athlete mid-stride with motion blur captured cinematically. Big white condensed sans-serif headline "Train smarter all year." in the lower portion. Energetic, motivational, premium sportswear aesthetic. No watermarks, no extra logos.',
        ],
        [
            'slug' => 'hero-retail',
            'aspect' => '4:5',
            'prompt' => 'Lifestyle display ad creative for a modern direct-to-consumer brand. Sunset orange to coral gradient background. A flatlay of curated everyday objects (wallet, sunglasses, key) arranged minimally. Bold white sans-serif headline "Made for your everyday." in the lower left. Magazine cover styling, premium e-commerce vibe, soft directional light. No watermarks, no garbled text.',
        ],
        [
            'slug' => 'hero-finance',
            'aspect' => '4:5',
            'prompt' => 'Premium display ad creative for a fintech app. Deep navy to indigo gradient background. A glossy 3D credit card floating at a 30-degree tilt with subtle reflections, soft glow underneath. Bold white sans-serif headline "Crafted with care." in the lower right. Trustworthy, modern, polished. No watermarks, no extra logos.',
        ],
        [
            'slug' => 'example-rectangle',
            'aspect' => '5:4',
            'prompt' => 'Premium medium-rectangle display ad creative. Bold purple-to-pink gradient background. Clean white sans-serif headline "Generate ads instantly." in the upper portion. Minimal 3D paper airplane illustration in the lower right. Small white "Get Started" pill CTA in the lower left. Crisp ad-agency quality. No extra text, no watermarks.',
        ],
        [
            'slug' => 'example-leaderboard',
            'aspect' => '16:9',
            'prompt' => 'Cinematic billboard display ad creative. City skyline at twilight as background with deep navy blue overlay. Bold white sans-serif headline "Modern payments, made simple." centered-left. Small white "Learn more" pill CTA on the right. Editorial, premium. No extra text, no watermarks.',
        ],
        [
            'slug' => 'example-skyscraper',
            'aspect' => '9:16',
            'prompt' => 'Vertical skyscraper display ad creative. Dark indigo background with abstract glowing circuit-board lines artwork. Bold white sans-serif headline "Enterprise Power. Stripe Simple." mid-card. White "Get Started" pill CTA near bottom. Premium tech aesthetic. No extra text, no watermarks.',
        ],
        [
            'slug' => 'example-square',
            'aspect' => '1:1',
            'prompt' => 'Square display ad creative. Soft coral-to-orange gradient background. Bold white sans-serif headline "Made for your everyday." in the upper center. Minimal flatlay illustration of curated lifestyle objects below. White "Shop now" pill CTA at the bottom. Magazine-cover styling. No extra text, no watermarks.',
        ],
        [
            'slug' => 'example-portrait',
            'aspect' => '4:5',
            'prompt' => 'Portrait display ad creative for a fashion brand. Soft cream-to-blush gradient background. A model silhouette in the lower right. Bold white serif headline "Crafted with care." upper-left. White "Discover" pill CTA mid-left. High-fashion editorial styling. No extra text, no watermarks.',
        ],
    ];

    public function handle(): int
    {
        $key = (string) config('services.gemini.api_key');
        if (! $key) {
            $this->error('GEMINI_API_KEY not set');
            return self::FAILURE;
        }
        $model = (string) $this->option('model');
        $base = rtrim((string) config('services.gemini.base'), '/');
        $disk = Storage::disk('public');
        $disk->makeDirectory('landing');

        foreach ($this->jobs as $job) {
            $this->line("→ {$job['slug']} ({$job['aspect']})");
            if ($disk->exists("landing/{$job['slug']}.jpg") || $disk->exists("landing/{$job['slug']}.png")) {
                $this->line('  exists — skipping');
                continue;
            }
            $body = [
                'contents' => [['role' => 'user', 'parts' => [['text' => $job['prompt']]]]],
                'generationConfig' => [
                    'responseModalities' => ['IMAGE'],
                    'imageConfig' => ['aspectRatio' => $job['aspect']],
                ],
            ];
            $url = "{$base}/models/{$model}:generateContent?key={$key}";
            $res = Http::timeout(120)->retry(2, 1500)->post($url, $body);
            if (! $res->successful()) {
                $this->error("  failed {$res->status()}: " . substr($res->body(), 0, 300));
                continue;
            }
            $parts = data_get($res->json(), 'candidates.0.content.parts', []);
            $saved = false;
            foreach ($parts as $part) {
                if (isset($part['inlineData']['data'])) {
                    $mime = $part['inlineData']['mimeType'] ?? 'image/png';
                    $ext = str_contains($mime, 'jpeg') ? 'jpg' : 'png';
                    $path = "landing/{$job['slug']}.{$ext}";
                    $disk->put($path, base64_decode($part['inlineData']['data']));
                    $size = strlen($disk->get($path));
                    $this->info("  saved {$path} ({$size} bytes)");
                    $saved = true;
                    break;
                }
            }
            if (! $saved) {
                $this->warn('  no inlineData in response');
            }
        }
        return self::SUCCESS;
    }
}
