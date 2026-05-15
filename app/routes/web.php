<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CreateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PixelController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Public marketing + onboarding
Route::get('/', fn () => redirect()->route('create.index'));
Route::get('/create', [CreateController::class, 'index'])->name('create.index');
Route::post('/create/start', [OnboardingController::class, 'start'])->name('create.start');
Route::get('/create/{session}/processing', [OnboardingController::class, 'processing'])->name('create.processing');
Route::get('/create/{session}/status', [OnboardingController::class, 'status'])->name('create.status');
Route::get('/create/{session}/preview', [OnboardingController::class, 'preview'])->name('create.preview');
Route::get('/create/{session}/renders', [OnboardingController::class, 'renders'])->name('create.renders');
Route::get('/create/{session}/claim', [OnboardingController::class, 'claim'])->name('create.claim');
Route::post('/create/{session}/claim', [OnboardingController::class, 'storeAccount'])->name('create.claim.store');

// Pixel
Route::get('/pixel.js', [PixelController::class, 'script'])->name('pixel.script');
Route::post('/p/event', [PixelController::class, 'event'])->name('pixel.event')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);

// Health
Route::get('/healthz', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));

// Authenticated dashboard
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('/dashboard/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/dashboard/integrations/pixel', [IntegrationController::class, 'registerPixel'])->name('integrations.registerPixel');
    Route::post('/dashboard/integrations/pixel/verify', [IntegrationController::class, 'verifyPixel'])->name('integrations.verifyPixel');
    Route::post('/dashboard/integrations/feed', [IntegrationController::class, 'connectFeed'])->name('integrations.connectFeed');
    Route::get('/dashboard/reporting', [ReportingController::class, 'index'])->name('reporting.index');
    Route::get('/dashboard/settings', [SettingsController::class, 'index'])->name('settings.index');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect()->route('create.index');
    })->name('logout');
});
