@extends('layouts.app')

@section('page_title', 'Job Orders')

@section('content')
@php($dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : ''))
<style>
    @media print {
        body * { visibility: hidden !important; }
        .receipt-print-area, .receipt-print-area * { visibility: visible !important; }
        .receipt-print-area {
            left: 0 !important;
            margin: 0 auto !important;
            max-width: 420px !important;
            position: absolute !important;
            right: 0 !important;
            top: 0 !important;
            width: 100% !important;
        }
        .receipt-print-actions { display: none !important; }
    }
</style>
<div
    x-data="{
        statusOpen: null,
        cancelOpen: null,
        paymentOpen: null,
        receiptOpen: null,
        dateRange: @js($dateRangeValue),
        init() {
            this.$nextTick(() => {
                if (!window.flatpickr) return;
                window.flatpickr(this.$refs.dateRange, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                    onClose: (dates, value) => this.dateRange = value,
                });
            });
        },
    }"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="jobOrders" class="h-3.5 w-3.5"></span>
                {{ in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'Laundry operations' }}
            </div>
            <h1 class="text-xl font-semibold">Job Orders</h1>
            <p class="text-sm text-muted">Create, filter, review, and edit laundry transactions.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.job-orders.create') }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                <span data-lucide="plus" class="h-4 w-4"></span>
                New POS
            </a>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_12rem_16rem_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search JO or customer..." class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                @endforeach
            </select>
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" placeholder="Date range" autocomplete="off" class="w-full bg-transparent text-sm outline-none">
            </div>
            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800"><span data-lucide="search" class="h-4 w-4"></span></button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                <tr>
                    <th class="px-4 py-3">JO #</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Processing</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Balance</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border dark:divide-gray-800">
                @forelse($orders as $order)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $order->job_order_number }}</td>
                        <td class="px-4 py-3">
                            <p>{{ $order->customer?->name }}</p>
                            <span class="{{ \App\Support\StatusBadge::classes($order->transaction_type === 'delivery' ? 'delivery' : 'regular') }}">{{ $order->transaction_type === 'delivery' ? 'Delivery' : 'Walk-in' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $order->branch?->name }}</p>
                            @if(($order->branch?->branch_type ?? 'full_service') === 'pickup_dropoff')
                                <p class="text-xs text-muted">Pickup & Drop-off</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <p>{{ $order->processingBranch?->name ?? $order->branch?->name }}</p>
                            @if((int) ($order->processing_branch_id ?: $order->branch_id) !== (int) $order->branch_id)
                                @if($order->production_accepted_at)
                                    <p class="text-xs text-emerald-600">Received {{ $order->production_accepted_at->format('M d, h:i A') }}</p>
                                @else
                                    <p class="text-xs text-amber-600">Waiting for QR scan</p>
                                @endif
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td>
                        <td class="px-4 py-3"><span class="{{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.job-orders.show', $order) }}" title="View" aria-label="View job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="eye" class="h-4 w-4"></span>
                            </a>
                            <a href="{{ route('admin.job-orders.edit', $order) }}" title="Edit" aria-label="Edit job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="settings" class="h-4 w-4"></span>
                            </a>
                            <button type="button" @click="paymentOpen = {{ $order->id }}" title="Payment history" aria-label="View payment history" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="payments" class="h-4 w-4"></span>
                            </button>
                            <button type="button" @click="receiptOpen = {{ $order->id }}" title="Receipt" aria-label="Print receipt" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="receipt" class="h-4 w-4"></span>
                            </button>
                            @unless(in_array($order->status, ['completed', 'cancelled'], true))
                                <button type="button" @click="statusOpen = {{ $order->id }}" title="Update status" aria-label="Update status" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="activity" class="h-4 w-4"></span>
                                </button>
                                <button type="button" @click="cancelOpen = {{ $order->id }}" title="Cancel" aria-label="Cancel job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                    <span data-lucide="x" class="h-4 w-4"></span>
                                </button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-muted">No job orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $orders->links() }}</div>
    </div>

    @foreach($orders as $order)
        <div x-cloak x-show="paymentOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="paymentOpen = null" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="payments" class="h-4 w-4 text-primary"></span>Payment History</h2>
                        <p class="text-sm text-muted">{{ $order->job_order_number }} - {{ $order->customer?->name }}</p>
                    </div>
                    <button type="button" @click="paymentOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <div class="mb-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Total</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Paid</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->paid_amount, 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border p-3 dark:border-gray-800">
                        <p class="text-xs text-muted">Balance</p>
                        <p class="font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-border dark:border-gray-800">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-smoke text-xs uppercase text-muted dark:bg-gray-950">
                            <tr><th class="px-3 py-2">Payment #</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Reference</th><th class="px-3 py-2">Received By</th><th class="px-3 py-2">Date</th><th class="px-3 py-2 text-right">Amount</th></tr>
                        </thead>
                        <tbody class="divide-y divide-border dark:divide-gray-800">
                            @forelse($order->payments->sortByDesc('paid_at') as $payment)
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $payment->payment_number }}</td>
                                    <td class="px-3 py-2"><span class="{{ \App\Support\StatusBadge::classes($payment->payment_type) }}">{{ \App\Support\StatusBadge::label($payment->payment_type) }}</span></td>
                                    <td class="px-3 py-2">{{ $payment->reference_no ?: 'N/A' }}</td>
                                    <td class="px-3 py-2">{{ $payment->receiver?->name ?? 'N/A' }}</td>
                                    <td class="px-3 py-2">{{ $payment->paid_at?->format('M d, Y h:i A') }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-8 text-center text-muted">No payments recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div x-cloak x-show="receiptOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="receiptOpen = null" class="max-h-[92vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-4 shadow-2xl dark:bg-gray-900">
                <div class="receipt-print-actions mb-3 flex items-center justify-between gap-2">
                    <h2 class="inline-flex min-w-0 items-center gap-2 text-sm font-semibold"><span data-lucide="receipt" class="h-4 w-4 text-primary"></span><span class="truncate">{{ $order->job_order_number }} Receipt</span></h2>
                    <div class="flex gap-2">
                        <button type="button" onclick="window.print()" class="inline-flex h-8 items-center gap-2 rounded-md bg-primary px-3 text-xs font-medium text-white hover:opacity-90"><span data-lucide="printer" class="h-3.5 w-3.5"></span>Print</button>
                        <button type="button" @click="receiptOpen = null" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950"><span data-lucide="x" class="h-4 w-4"></span></button>
                    </div>
                </div>
                <div class="receipt-print-area">
                    @include('admin.job-orders.partials.receipt-card', ['order' => $order, 'settings' => $appSettings, 'branchSetting' => $order->branch?->setting])
                </div>
            </div>
        </div>

        <div x-cloak x-show="statusOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="statusOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="activity" class="h-4 w-4 text-primary"></span>Update Status</h2>
                        <p class="text-sm text-muted">{{ $order->job_order_number }}</p>
                    </div>
                    <button type="button" @click="statusOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <form method="POST" action="{{ route('admin.job-orders.status', $order) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                        @foreach(array_filter($statuses, fn ($status) => $status !== 'cancelled') as $status)
                            <option value="{{ $status }}" @selected($order->status === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                        @endforeach
                    </select>
                    <div class="flex justify-end">
                        <button class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Status</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-cloak x-show="cancelOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="cancelOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">Cancel job order?</h2>
                    <p class="mt-1 text-sm text-muted">{{ $order->job_order_number }} will be marked as cancelled.</p>
                </div>
                <form method="POST" action="{{ route('admin.job-orders.cancel', $order) }}" class="flex justify-end gap-2">
                    @csrf
                    @method('PATCH')
                    <button type="button" @click="cancelOpen = null" class="h-9 rounded-md border border-border px-4 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">Keep</button>
                    <button class="h-9 rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700">Cancel Order</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endsection
