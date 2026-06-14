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

    <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-3">
            <h2 class="text-base font-semibold">Financial Reconciliation</h2>
            <p class="text-sm text-muted">These authoritative totals match Dashboard and Z Reading for the same branch and date period.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([
                'sales_owned' => 'Sales Owned',
                'physical_collections' => 'Physical Collections',
                'expected_cash_drawer' => 'Expected Cash Drawer',
                'expected_gcash' => 'Expected GCash',
                'expenses_total' => 'Recorded Expenses',
                'accounts_payable' => 'Accounts Payable',
                'unpaid_balance' => 'Unpaid Customer Balance',
                'over_short' => 'Z Reading Over / Short',
            ] as $key => $label)
                <div class="rounded-md bg-smoke p-3 dark:bg-gray-950">
                    <p class="text-xs text-muted">{{ $label }}</p>
                    <p class="mt-1 font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $financialSummary[$key], 2) }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-muted">
            Drawer = cash collected + cash deposits/owner cash funding - store-cash expenses - cash withdrawals/remittances/payable repayments.
        </p>
    </div>

    <div class="flex gap-1 overflow-x-auto rounded-lg border border-border bg-white p-1 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        @foreach([
            'sales' => 'Sales',
            'operations' => 'Operations',
            'receivables' => 'Receivables',
            'inventory' => 'Inventory Usage',
            'payments' => 'Payments',
            'expenses' => 'Expenses',
            'payables' => 'Accounts Payable',
            'cash' => 'Cash Movements',
            'ledger' => 'Customer Ledger',
            'activity' => 'Activity Logs',
        ] as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'" class="h-8 shrink-0 rounded-md px-3 text-sm font-medium" :class="tab === '{{ $key }}' ? 'bg-primary text-white' : 'text-muted hover:bg-smoke dark:hover:bg-gray-950'">{{ $label }}</button>
        @endforeach
    </div>

    <div x-show="tab === 'operations'" class="space-y-4">
        <div class="grid gap-3 md:grid-cols-5">
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Job Orders</p><p class="mt-1 text-lg font-semibold">{{ number_format((int) ($jobOrderSummary->total_orders ?? 0)) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Rush Orders</p><p class="mt-1 text-lg font-semibold">{{ number_format((int) ($jobOrderSummary->rush_orders ?? 0)) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Order Value</p><p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($jobOrderSummary->order_value ?? 0), 2) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Unpaid Balance</p><p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($jobOrderSummary->unpaid_balance ?? 0), 2) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Loyal Customers</p><p class="mt-1 text-lg font-semibold">{{ number_format($loyalCustomerCount) }}</p><p class="text-xs text-muted">10+ lifetime visits</p></div>
        </div>
        <div class="grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">SMS Attempts</p><p class="mt-1 text-lg font-semibold">{{ number_format((int) ($smsSummary->total ?? 0)) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">SMS Sent</p><p class="mt-1 text-lg font-semibold text-emerald-600">{{ number_format((int) ($smsSummary->sent ?? 0)) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">SMS Failed</p><p class="mt-1 text-lg font-semibold text-red-600">{{ number_format((int) ($smsSummary->failed ?? 0)) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">SMS Queued</p><p class="mt-1 text-lg font-semibold">{{ number_format((int) ($smsSummary->queued ?? 0)) }}</p></div>
        </div>
        <p class="text-sm text-muted">SMS failures are reported for monitoring and do not prevent job orders or cycle updates.</p>
    </div>

    <div x-show="tab === 'sales'" class="grid gap-4 xl:grid-cols-2">
        <x-report-table title="Sales by Date">
            <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3 text-right">Payments</th><th class="px-4 py-3 text-right">Cash</th><th class="px-4 py-3 text-right">GCash</th><th class="px-4 py-3 text-right">Sales</th></x-slot:head>
            @forelse($salesByDate as $row)
                <tr><td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($row->report_date)->format('M d, Y') }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->cash_amount, 2) }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->gcash_amount, 2) }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No sales found.</td></tr>
            @endforelse
        </x-report-table>

        <x-report-table title="Sales by Branch">
            <x-slot:head><th class="px-4 py-3">Branch</th><th class="px-4 py-3 text-right">Payments</th><th class="px-4 py-3 text-right">Cash</th><th class="px-4 py-3 text-right">GCash</th><th class="px-4 py-3 text-right">Sales</th></x-slot:head>
            @forelse($salesByBranch as $row)
                <tr><td class="px-4 py-3">{{ $row->branch_name }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->cash_amount, 2) }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->gcash_amount, 2) }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No branch sales found.</td></tr>
            @endforelse
        </x-report-table>
    </div>

    <div x-show="tab === 'sales'" class="grid gap-4 xl:grid-cols-2">
        <x-report-table title="Physical Collections by Branch">
            <x-slot:head><th class="px-4 py-3">Collected At</th><th class="px-4 py-3 text-right">Payments</th><th class="px-4 py-3 text-right">Cash</th><th class="px-4 py-3 text-right">GCash</th><th class="px-4 py-3 text-right">Collected</th></x-slot:head>
            @forelse($collectionsByBranch as $row)
                <tr><td class="px-4 py-3">{{ $row->branch_name }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->cash_amount, 2) }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->gcash_amount, 2) }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No branch collections found.</td></tr>
            @endforelse
        </x-report-table>

        <x-report-table title="Cross-Branch Collections for Remittance">
            <x-slot:head><th class="px-4 py-3">Payment</th><th class="px-4 py-3">JO #</th><th class="px-4 py-3">Sales Branch</th><th class="px-4 py-3">Collected At</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Amount</th></x-slot:head>
            @forelse($crossBranchCollections as $payment)
                <tr><td class="px-4 py-3 font-medium">{{ $payment->payment_number }}</td><td class="px-4 py-3">{{ $payment->jobOrder?->job_order_number }}</td><td class="px-4 py-3">{{ $payment->branch?->name }}</td><td class="px-4 py-3">{{ $payment->collectedBranch?->name }}</td><td class="px-4 py-3"><span class="{{ \App\Support\StatusBadge::classes('pending') }}">{{ \App\Support\StatusBadge::label($payment->settlement_status ?: 'pending') }}</span></td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No cross-branch collections found.</td></tr>
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

    <x-report-table title="GCash Reference Breakdown" x-show="tab === 'payments'">
        <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3">Payment #</th><th class="px-4 py-3">JO #</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3 text-right">Amount</th></x-slot:head>
        @forelse($gcashPayments as $payment)
            <tr><td class="px-4 py-3">{{ $payment->paid_at?->format('M d, Y h:i A') }}</td><td class="px-4 py-3 font-medium">{{ $payment->payment_number }}</td><td class="px-4 py-3">{{ $payment->jobOrder?->job_order_number }}</td><td class="px-4 py-3">{{ $payment->customer?->name }}</td><td class="px-4 py-3">{{ $payment->reference_no ?: 'No reference' }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No GCash payments found.</td></tr>
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

    <x-report-table title="Sales Payment Type" x-show="tab === 'payments'">
        <x-slot:head><th class="px-4 py-3">Type</th><th class="px-4 py-3 text-right">Count</th><th class="px-4 py-3 text-right">Total</th></x-slot:head>
        @forelse($paymentTypes as $row)
            <tr><td class="px-4 py-3">{{ \App\Support\StatusBadge::label($row->payment_type) }}</td><td class="px-4 py-3 text-right">{{ $row->payments_count }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $row->total_amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="3" class="px-4 py-10 text-center text-muted">No payments found.</td></tr>
        @endforelse
    </x-report-table>

    <div x-show="tab === 'expenses'" class="space-y-4">
        <div class="grid gap-3 md:grid-cols-3">
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-muted">All Recorded Expenses</p>
                <p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($expenseSummary->total_expenses ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-muted">Taken From Store Today</p>
                <p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($expenseSummary->store_cash_expenses ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p class="text-xs text-muted">Owner-Paid, Reimbursement Due</p>
                <p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($expenseSummary->owner_expenses ?? 0), 2) }}</p>
            </div>
        </div>

        <x-report-table title="Expenses">
            <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Expense</th><th class="px-4 py-3">Paid From</th><th class="px-4 py-3 text-right">Amount</th></x-slot:head>
            @forelse($expenses as $expense)
                <tr><td class="px-4 py-3">{{ $expense->expense_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ $expense->branch?->name }}</td><td class="px-4 py-3"><p class="font-medium">{{ $expense->title }}</p><p class="text-xs text-muted">{{ \App\Support\StatusBadge::label($expense->category) }}{{ $expense->payment_method ? ' - '.\App\Support\StatusBadge::label($expense->payment_method) : '' }}</p></td><td class="px-4 py-3">{{ $expense->paid_from === 'owner' ? 'Owner-paid, reimbursement due' : 'Store-funded' }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $expense->amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No expenses found.</td></tr>
            @endforelse
        </x-report-table>
    </div>

    <div x-show="tab === 'payables'" class="space-y-4">
        <div class="grid gap-3 md:grid-cols-3">
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Total Obligations</p><p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($accountsPayableSummary->original_total ?? 0), 2) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Repaid</p><p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($accountsPayableSummary->paid_total ?? 0), 2) }}</p></div>
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900"><p class="text-xs text-muted">Outstanding</p><p class="mt-1 text-lg font-semibold">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) ($accountsPayableSummary->balance_total ?? 0), 2) }}</p></div>
        </div>
        <x-report-table title="Accounts Payable">
            <x-slot:head><th class="px-4 py-3">Payable</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Source</th><th class="px-4 py-3 text-right">Original</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></x-slot:head>
            @forelse($accountsPayables as $payable)
                <tr><td class="px-4 py-3"><p class="font-medium">{{ $payable->payable_number }} - {{ $payable->creditor_name }}</p><p class="text-xs text-muted">{{ $payable->description }}</p></td><td class="px-4 py-3">{{ $payable->branch?->name }}</td><td class="px-4 py-3">{{ $payable->source_type === 'owner_paid_expense' ? 'Owner-paid expense' : 'Owner funding' }}</td><td class="px-4 py-3 text-right">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $payable->original_amount, 2) }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $payable->balance, 2) }}</td><td class="px-4 py-3">{{ \App\Support\StatusBadge::label($payable->status) }}</td></tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No accounts payable found.</td></tr>
            @endforelse
        </x-report-table>

        <x-report-table title="Payable Repayments In Selected Period">
            <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Payable</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3 text-right">Amount</th></x-slot:head>
            @forelse($accountsPayablePayments as $payment)
                <tr><td class="px-4 py-3">{{ $payment->payment_date?->format('M d, Y') }}</td><td class="px-4 py-3 font-medium">{{ $payment->payment_number }}</td><td class="px-4 py-3">{{ $payment->payable?->payable_number }}</td><td class="px-4 py-3">{{ \App\Support\StatusBadge::label($payment->payment_method) }}</td><td class="px-4 py-3">{{ $payment->reference_no ?: 'N/A' }}</td><td class="px-4 py-3 text-right font-medium">{{ $settings->currency ?? 'PHP' }} {{ number_format((float) $payment->amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No payable repayments found.</td></tr>
            @endforelse
        </x-report-table>
    </div>

    <x-report-table title="Cash Drawer Movements" x-show="tab === 'cash'">
        <x-slot:head><th class="px-4 py-3">Date</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Movement</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3">Recorded By</th><th class="px-4 py-3 text-right">Amount</th></x-slot:head>
        @forelse($moneyMovements as $movement)
            @php($signedAmount = $movement->direction === 'in' ? (float) $movement->amount : -1 * (float) $movement->amount)
            <tr><td class="px-4 py-3">{{ $movement->movement_date?->format('M d, Y') }}</td><td class="px-4 py-3">{{ $movement->branch?->name }}</td><td class="px-4 py-3"><p class="font-medium">{{ $movement->type_label }}</p><p class="text-xs text-muted">{{ $movement->description }}</p></td><td class="px-4 py-3">{{ $movement->reference_no ?: 'N/A' }}</td><td class="px-4 py-3">{{ $movement->recorder?->name ?? 'System' }}</td><td class="px-4 py-3 text-right font-medium {{ $signedAmount < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $signedAmount < 0 ? '-' : '+' }} {{ $settings->currency ?? 'PHP' }} {{ number_format(abs($signedAmount), 2) }}</td></tr>
        @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No cash drawer movements found.</td></tr>
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
