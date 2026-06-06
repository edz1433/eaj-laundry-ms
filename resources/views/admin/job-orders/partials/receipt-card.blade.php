@php
    $settings ??= \App\Models\SystemSetting::current();
    $branchSetting ??= $order->branch?->setting;
    $receiptHeader = $branchSetting?->receipt_header ?: $settings?->receipt_header;
    $receiptFooter = $branchSetting?->receipt_footer ?: $settings?->receipt_footer;
    $totalPaid = $order->payments->sum('amount');
@endphp

<section class="receipt rounded-lg border border-border bg-white p-5 shadow-sm">
    <div class="text-center">
        <img src="{{ $appBusinessLogo }}" class="mx-auto mb-2 h-14 w-14 object-contain" alt="Logo">
        <h1 class="text-lg font-semibold">{{ $settings?->business_name ?? config('app.name') }}</h1>
        <p class="text-xs font-medium">{{ $order->branch?->name }}</p>
        <p class="text-xs text-muted">{{ $order->branch?->address ?: $settings?->business_address }}</p>
        <p class="text-xs text-muted">{{ $order->branch?->contact_number ?: $settings?->contact_number }} @if($settings?->business_email) - {{ $settings?->business_email }} @endif</p>
    </div>

    @if($receiptHeader)
        <p class="mt-4 rounded-md bg-smoke p-2 text-center text-xs text-muted">{{ $receiptHeader }}</p>
    @endif

    <div class="my-4 border-y border-dashed border-border py-3 text-xs">
        <div class="flex justify-between"><span>JO #</span><span class="font-medium">{{ $order->job_order_number }}</span></div>
        <div class="flex justify-between"><span>Date</span><span>{{ $order->created_at->format('M d, Y h:i A') }}</span></div>
        <div class="flex justify-between"><span>Branch</span><span>{{ $order->branch?->name }}</span></div>
        <div class="flex justify-between"><span>Customer</span><span>{{ $order->customer?->name }}</span></div>
        <div class="flex justify-between"><span>Billing</span><span>{{ str_replace('_', ' ', ucfirst($order->customer?->billing_type ?? 'regular')) }}</span></div>
        <div class="flex justify-between"><span>Transaction</span><span>{{ $order->transaction_type === 'delivery' ? 'Delivery' : 'Walk-in' }}</span></div>
        <div class="flex justify-between"><span>Status</span><span>{{ \App\Support\StatusBadge::label($order->status) }}</span></div>
    </div>

    <table class="w-full text-xs">
        <thead>
            <tr class="border-b border-border text-left text-muted">
                <th class="py-2">Item</th>
                <th class="py-2 text-right">Qty</th>
                <th class="py-2 text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr class="border-b border-dashed border-border">
                    <td class="py-2">
                        <p class="font-medium">{{ $item->description }}</p>
                        <p class="text-muted">{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $item->unit_price, 2) }}</p>
                    </td>
                    <td class="py-2 text-right">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="py-2 text-right">{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-4 space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-muted">Subtotal</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->subtotal, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Discount</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->discount, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">VAT</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->tax, 2) }}</span></div>
        <div class="flex justify-between border-t border-border pt-2 font-semibold"><span>Total</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</span></div>
        <div class="flex justify-between"><span class="text-muted">Paid</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $totalPaid, 2) }}</span></div>
        <div class="flex justify-between font-semibold"><span>Balance</span><span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</span></div>
    </div>

    <div class="mt-4 border-t border-dashed border-border pt-3">
        <p class="mb-2 text-xs font-semibold">Payments</p>
        @forelse($order->payments as $payment)
            <div class="flex justify-between text-xs">
                <span>{{ str_replace('_', ' ', ucfirst($payment->payment_type)) }} - {{ $payment->paid_at?->format('M d, h:i A') }}{{ $payment->reference_no ? ' - Ref: '.$payment->reference_no : '' }}</span>
                <span>{{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</span>
            </div>
        @empty
            <p class="text-xs text-muted">No payment yet. Remaining balance: {{ $settings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</p>
        @endforelse
    </div>

    @if($receiptFooter)
        <p class="mt-4 rounded-md bg-smoke p-2 text-center text-xs text-muted">{{ $receiptFooter }}</p>
    @endif
</section>
