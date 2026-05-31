@isset($billingBanner)
    <div
        x-data="{
            key: @js($billingBanner['key'] ?? null),
            dismissible: @js((bool) ($billingBanner['dismissible'] ?? false)),
            visible: true,
            init() {
                if (this.dismissible && this.key) {
                    this.visible = localStorage.getItem(this.key) !== 'dismissed';
                }
            },
            dismiss() {
                if (this.key) localStorage.setItem(this.key, 'dismissed');
                this.visible = false;
            }
        }"
        x-show="visible"
        x-transition
        class="mb-4 flex items-start justify-between gap-3 rounded-md border px-3 py-2 text-sm shadow-sm {{ ($billingBanner['type'] ?? '') === 'trial' ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-orange-200 bg-orange-50 text-orange-800 ring-1 ring-orange-100' }}"
    >
        <span>{{ $billingBanner['message'] }}</span>
        @if(! empty($billingBanner['dismissible']))
            <button type="button" @click="dismiss()" class="shrink-0 rounded px-1.5 text-lg leading-none hover:bg-black/5" aria-label="Dismiss billing notice">
                &times;
            </button>
        @endif
    </div>
@endisset
