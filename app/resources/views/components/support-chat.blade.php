{{-- Floating support chat bubble.

    Provider selection (config('services.support.provider')):
      - 'zendesk' : injects the Zendesk Web Widget script (requires SUPPORT_ZENDESK_KEY)
      - 'tawk'    : injects the Tawk.to widget (requires SUPPORT_TAWK_PROPERTY_ID and SUPPORT_TAWK_WIDGET_ID)
      - 'inapp'   : (default) renders our own bubble + form, POSTs to /support/message

    The "inapp" path is free and self-hosted — messages land in the
    support_messages table. The other providers are drop-in if/when the
    user signs up for them.
--}}
@php
    $provider = (string) config('services.support.provider', 'inapp');
@endphp

@if($provider === 'zendesk')
    @php $key = (string) config('services.support.zendesk_key'); @endphp
    @if($key)
        <script id="ze-snippet" src="https://static.zdassets.com/ekr/snippet.js?key={{ $key }}"></script>
    @endif
@elseif($provider === 'tawk')
    @php
        $propertyId = (string) config('services.support.tawk_property_id');
        $widgetId   = (string) config('services.support.tawk_widget_id', 'default');
    @endphp
    @if($propertyId)
        <script>
            var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
            (function(){
                var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
                s1.async=true;
                s1.src='https://embed.tawk.to/{{ $propertyId }}/{{ $widgetId }}';
                s1.charset='UTF-8';
                s1.setAttribute('crossorigin','*');
                s0.parentNode.insertBefore(s1,s0);
            })();
        </script>
    @endif
@else
    {{-- In-app chat bubble (default, no external service). --}}
    <div x-data="supportChat()" x-cloak
         class="fixed bottom-5 right-5 z-50 print:hidden"
         style="font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif;">

        {{-- Closed-state pill / button --}}
        <button type="button"
                x-show="!open"
                x-transition.duration.150ms
                @click="open = true; $nextTick(() => $refs.body.focus())"
                class="group flex items-center gap-2.5 pl-3.5 pr-4 py-3 rounded-full bg-ink text-white shadow-lg hover:shadow-xl hover:-translate-y-0.5 transition-all"
                aria-label="Open support chat">
            <span class="relative flex w-6 h-6 items-center justify-center rounded-full bg-gradient-to-br from-primary to-accent shrink-0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
                <span class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-success border border-ink"></span>
            </span>
            <span class="text-sm font-semibold">Chat with us</span>
        </button>

        {{-- Open-state panel --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-3 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
             x-transition:leave-end="opacity-0 translate-y-3 scale-95"
             class="w-[340px] max-w-[calc(100vw-2.5rem)] bg-surface rounded-2xl shadow-2xl border border-line overflow-hidden"
             role="dialog" aria-label="Support chat">

            {{-- Header --}}
            <div class="px-4 py-3.5 bg-gradient-to-br from-primary to-accent text-white flex items-center justify-between">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="relative flex w-9 h-9 rounded-full bg-white/15 items-center justify-center shrink-0">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                        </svg>
                        <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-success border-2 border-white"></span>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-bold leading-tight truncate">Layout.ai support</p>
                        <p class="text-[11px] text-white/70 truncate">Usually replies within a few hours</p>
                    </div>
                </div>
                <button type="button" @click="open = false" class="-mr-1 p-1.5 rounded-md text-white/80 hover:text-white hover:bg-white/10 transition" aria-label="Close chat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-4">
                <template x-if="!sent">
                    <form @submit.prevent="send()" class="space-y-2.5">
                        <p class="text-sm text-muted leading-snug">Have a question or hit an issue? Drop a note and we'll get back to you.</p>

                        <label class="block">
                            <span class="text-[11px] font-semibold text-muted uppercase tracking-wide">Your email</span>
                            <input type="email" required x-model="email"
                                   class="mt-1 w-full rounded-lg border-line text-sm focus:border-primary focus:ring-primary/20"
                                   placeholder="you@company.com">
                        </label>

                        <label class="block">
                            <span class="text-[11px] font-semibold text-muted uppercase tracking-wide">Message</span>
                            <textarea required x-model="body" rows="4" x-ref="body" maxlength="4000"
                                      class="mt-1 w-full rounded-lg border-line text-sm focus:border-primary focus:ring-primary/20 resize-none"
                                      placeholder="How can we help?"></textarea>
                        </label>

                        <p x-show="error" x-text="error" class="text-xs text-red-600"></p>

                        <button type="submit" :disabled="sending"
                                class="w-full rounded-lg bg-primary hover:bg-primary/90 disabled:opacity-60 text-white px-4 py-2.5 text-sm font-semibold transition flex items-center justify-center gap-2">
                            <svg x-show="sending" class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.2-8.55"/></svg>
                            <span x-text="sending ? 'Sending…' : 'Send message'"></span>
                        </button>
                    </form>
                </template>

                <template x-if="sent">
                    <div class="py-4 text-center">
                        <div class="mx-auto w-12 h-12 rounded-full bg-success/10 text-success flex items-center justify-center mb-3">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </div>
                        <p class="font-semibold text-sm">Got it — thanks!</p>
                        <p class="text-xs text-muted mt-1">We'll get back to you at <span x-text="email" class="font-medium"></span>.</p>
                        <button type="button" @click="reset()" class="mt-3 text-xs text-primary font-semibold hover:underline">Send another</button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        function supportChat() {
            return {
                open: false,
                email: @json(auth()->user()?->email ?? ''),
                body:  '',
                sending: false,
                sent: false,
                error: '',
                async send() {
                    this.error = '';
                    this.sending = true;
                    try {
                        const res = await fetch(@json(route('support.store')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                email:    this.email,
                                body:     this.body,
                                page_url: window.location.href,
                            }),
                        });
                        if (res.status === 429) {
                            this.error = 'Too many messages — give us a few minutes and try again.';
                        } else if (!res.ok) {
                            this.error = "Couldn't send right now. Email hello@layout.ai instead?";
                        } else {
                            this.sent = true;
                        }
                    } catch (e) {
                        this.error = "Network hiccup — try again in a moment?";
                    } finally {
                        this.sending = false;
                    }
                },
                reset() {
                    this.sent = false;
                    this.body = '';
                    this.error = '';
                },
            };
        }
    </script>
@endif
