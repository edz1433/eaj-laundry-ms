@isset($billingBanner)
    <div class="mb-4 rounded-md border px-3 py-2 text-sm {{ ($billingBanner['type'] ?? '') === 'trial' ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
        {{ $billingBanner['message'] }}
    </div>
@endisset
