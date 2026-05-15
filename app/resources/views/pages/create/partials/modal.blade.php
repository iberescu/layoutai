{{-- Create-campaign modal. Driven by Alpine createCampaignForm() data --}}
<div x-show="open" x-transition.opacity class="fixed inset-0 z-50 bg-ink/60 backdrop-blur-sm flex items-center justify-center p-4" @keydown.escape.window="open=false">
    <div @click.outside="open=false" class="bg-surface rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
        <header class="p-6 border-b border-line">
            <h2 class="text-xl font-bold">Create your first ads</h2>
            <p class="text-sm text-muted mt-1">We'll scan your site, learn your brand, and generate a preview of 30 ads.</p>
        </header>
        <form @submit.prevent="submit" enctype="multipart/form-data" class="p-6 space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1.5">Website URL</label>
                <input x-model="websiteUrl" type="url" required name="website_url"
                       placeholder="https://yourbusiness.com"
                       class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Logo upload</label>
                <div class="flex items-center gap-4">
                    <div class="w-20 h-20 rounded-xl border border-line bg-bgmain flex items-center justify-center overflow-hidden">
                        <template x-if="logoPreview"><img :src="logoPreview" class="w-full h-full object-contain"></template>
                        <template x-if="!logoPreview"><span class="text-xs text-muted">Preview</span></template>
                    </div>
                    <input x-ref="logoInput" name="logo" type="file" accept="image/png,image/jpeg,image/svg+xml" @change="previewLogo" class="text-sm flex-1">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Business location</label>
                    <input x-model="businessLocation" type="text" name="business_location" placeholder="Bucharest, Romania" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Campaign goal</label>
                    <select x-model="campaignGoal" name="campaign_goal" class="w-full rounded-xl border-line focus:ring-primary focus:border-primary">
                        <option value="awareness">Brand awareness</option>
                        <option value="traffic">Website traffic</option>
                        <option value="leads">Lead generation</option>
                        <option value="sales">Sales / e-commerce</option>
                    </select>
                </div>
            </div>
            <template x-if="error">
                <div class="text-sm text-red-600" x-text="error"></div>
            </template>
            <button :disabled="loading" class="w-full bg-primary disabled:bg-primary/60 text-white font-semibold py-3 rounded-xl">
                <span x-show="!loading">Generate ads</span>
                <span x-show="loading">Scanning website…</span>
            </button>
            <p class="text-xs text-muted text-center">No credit card required. Review every ad before anything runs.</p>
        </form>
    </div>
</div>
