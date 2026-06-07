<?php

namespace App\Http\Controllers;

use App\Mail\NewSupportMessage;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class SupportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'body'     => ['required', 'string', 'max:4000'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        // Throttle: 5 messages per 10 min per IP. Stops drive-by spam.
        $key = 'support:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['error' => 'Too many messages, give us a few minutes.'], 429);
        }
        RateLimiter::hit($key, 600);

        $msg = SupportMessage::create([
            'user_id'    => Auth::id(),
            'email'      => $data['email'],
            'source'     => 'chat',
            'page_url'   => $data['page_url'] ?? $request->headers->get('referer'),
            'body'       => $data['body'],
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        // Forward to the support inbox. Queued so a slow SMTP doesn't stall
        // the request. A mail-driver misconfig must NEVER blow up the user
        // submission — the row is already saved.
        $notify = (string) config('services.support.notify_email');
        if ($notify) {
            try {
                Mail::to($notify)->queue(new NewSupportMessage($msg->fresh(['user'])));
            } catch (\Throwable $e) {
                Log::warning('Support mail queue failed: ' . $e->getMessage());
            }
        }

        return response()->json(['id' => $msg->id, 'ok' => true]);
    }
}
