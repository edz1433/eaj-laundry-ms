@extends('layouts.app')

@section('page_title', 'Reports')

@section('content')
<div
    x-data="{ tab: 'sales', dateRange: @js($dateRangeValue), init() { this.$nextTick(() => window.flatpickr && window.flatpickr(this.$refs.dateRange, { mode: 'range', dateFormat: 'Y-m-d', defaultDate: this.dateRange.split(' to '), onClose: (dates, value) => this.dateRange = value })) } }"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="reports" class="h-3.5 w-3.5"></span>
                Business reports
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Reports</h1>
            <p class="text-sm text-muted">Sales, receivables, inventory usage, customer ledger, and audit logs.</p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <form method="GET" action="{{ route('admin.reports.index') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-[12rem_16rem_auto]">
                @if($canChooseBranch)
                    <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                @endif

                <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                    <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                    <input x-ref="dateRange" x-model="dateRange" name="date_range" class="w-full bg-transparent text-sm outline-none" autocomplete="off">
                </div>

                <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                    <span data-lucide="search" class="h-4 w-4"></span>
                    Apply
                </button>
            </form>

            <a href="{{ route('admin.reports.pdf', request()->query()) }}" target="_blank" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border bg-white px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:bg-gray-950 dark:hover:bg-gray-900">
                <span data-lucide="file-text" class="h-4 w-4"></span>
                View PDF
            </a>
        </div>
    </div>

    <div class="flex gap-1 overflow-x-auto rounded-lg border border-border bg-white p-1 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        @foreach([
            'sales' => 'Sales',
            'receivables' => 'Receivables',
            'inventory' => 'Inventory Usage',
            'payments' => 'Payment Type',
            'ledger' => 'Customer Ledger',
            'activity' => 'Activity Logs',
        ] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'" class="h-8 shrink-0 rounded-md px-3 text-sm font-medium" :class="tab === '{{ $key }}' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">{{ $label }}</button>
        @endforeach
    </div>

    <div x-show="tab === 'sales'" class="grid gap-4 xl:grid-cols-2">
        <x-report-table title="Sales by Date">
            <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3 text-right">Payments</th><th class="px-4 py-3 text-right">Sales</th></x-slot:head>
            @forelse($salesByDate as $row)
                <tr><td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($row->report_date)->format('M d, Y') }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="3" class="px-4 py-10 text-center text-muted">No sales found.</td></tr>
            @endforelse
        </x-report-table>

        <x-report-table title="Sales by Branch">
            <x-slot:head><th class="px-4 py-3">Branch</th><th class="px-4 py-3 text-right">Payments</th><th class="px-4 py-3 text-right">Sales</th></x-slot:head>
            @forelse($salesByBranch as $row)
                <tr><td class="px-4 py-3">{{ $row->branch_name }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="3" class="px-4 py-10 text-center text-muted">No branch sales found.</td></tr>
            @endforelse
        </x-report-table>
    </div>

    <x-report-table title="Receivables" x-show="tab === 'receivables'">
        <x-slot:head><th class="px-4 py-3">JO #</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></x-slot:head>
        @forelse($receivables as $order)
            <tr><td class="px-4 py-3 font-medium">{{ $order->job_order_number }}</td><td class="px-4 py-3">{{ $order->customer?->name }}</td><td class="px-4 py-3">{{ $order->branch?->name }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td><td class="px-4 py-3"><span class="{{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span></td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No receivables found.</td></tr>
        @endforelse
    </x-report-table>

    <x-report-table title="Inventory Usage" x-show="tab === 'inventory'">
        <x-slot:head><th class="px-4 py-3">Item</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3 text-right">Qty Out</th><th class="px-4 py-3">Remarks</th><th class="px-4 py-3">Date</th></x-slot:head>
        @forelse($inventoryUsage as $movement)
            <tr><td class="px-4 py-3 font-medium">{{ $movement->inventory?->name }}</td><td class="px-4 py-3">{{ $movement->inventory?->branch?->name }}</td><td class="px-4 py-3 text-right">{{ number_format((float) $movement->quantity, 4) }} {{ $movement->inventory?->unit }}</td><td class="px-4 py-3">{{ $movement->remarks }}</td><td class="px-4 py-3">{{ $movement->created_at->format('M d, Y h:i A') }}</td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No usage found.</td></tr>
        @endforelse
    </x-report-table>

    <x-report-table title="Payment Type" x-show="tab === 'payments'">
        <x-slot:head><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">Count</th><th class="px-4 py-3 text-right">Total</th></x-slot:head>
        @forelse($paymentTypes as $row)
            <tr><td class="px-4 py-3">{{ str_replace('_', ' ', ucfirst($row->payment_type)) }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="3" class="px-4 py-10 text-center text-muted">No payments found.</td></tr>
        @endforelse
    </x-report-table>

    <x-report-table title="Customer Ledger" x-show="tab === 'ledger'">
        <x-slot:head><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Running</th><th class="px-4 py-3">Description</th></x-slot:head>
        @forelse($customerLedger as $entry)
            <tr><td class="px-4 py-3">{{ $entry->customer?->name }}</td><td class="px-4 py-3">{{ ucfirst($entry->entry_type) }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $entry->amount, 2) }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $entry->running_balance, 2) }}</td><td class="px-4 py-3">{{ $entry->description }}</td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No ledger entries found.</td></tr>
        @endforelse
    </x-report-table>

    <x-report-table title="Activity Logs" x-show="tab === 'activity'">
        <x-slot:head><th class="px-4 py-3">Action</th><th class="px-4 py-3">User</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Details</th><th class="px-4 py-3">Date</th></x-slot:head>
        @forelse($activityLogs as $log)
            <tr><td class="px-4 py-3 font-medium">{{ str_replace('_', ' ', ucfirst($log->action)) }}</td><td class="px-4 py-3">{{ $log->user?->name ?? 'System' }}</td><td class="px-4 py-3">{{ $log->branch?->name ?? 'N/A' }}</td><td class="px-4 py-3 text-muted">{{ collect($log->properties ?? [])->map(fn($value, $key) => $key.': '.$value)->implode(' | ') ?: 'N/A' }}</td><td class="px-4 py-3">{{ $log->created_at->format('M d, Y h:i A') }}</td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No activity logs found.</td></tr>
        @endforelse
    </x-report-table>
</div>
@endsection
