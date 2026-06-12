<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reports PDF</title>
    <style>
        @page { margin: 24px; }
        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }
        h1, h2, p { margin: 0; }
        h1 { font-size: 22px; }
        h2 {
            border-bottom: 1px solid #d1d5db;
            font-size: 14px;
            margin: 22px 0 8px;
            padding-bottom: 6px;
        }
        .muted { color: #6b7280; }
        .header {
            border-bottom: 2px solid #111827;
            margin-bottom: 12px;
            padding-bottom: 12px;
        }
        .meta {
            margin-top: 8px;
            width: 100%;
        }
        .meta td {
            color: #374151;
            padding: 2px 14px 2px 0;
            white-space: nowrap;
        }
        table.report {
            border-collapse: collapse;
            margin-bottom: 10px;
            width: 100%;
        }
        table.report th {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            color: #374151;
            font-size: 10px;
            padding: 6px;
            text-align: left;
            text-transform: uppercase;
        }
        table.report td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            vertical-align: top;
        }
        .right { text-align: right; }
        .empty {
            color: #6b7280;
            padding: 18px 6px;
            text-align: center;
        }
    </style>
</head>
<body>
    @php($currency = $settings->currency ?? 'PHP')

    <div class="header">
        <h1>{{ $settings->business_name ?? 'Laundry System' }} Reports</h1>
        <p class="muted">Sales, receivables, inventory usage, payment types, customer ledger, and activity logs.</p>
        <table class="meta">
            <tr>
                <td><strong>Branch:</strong> {{ $branchName }}</td>
                <td><strong>Date range:</strong> {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('M d, Y') }} to {{ \Illuminate\Support\Carbon::parse($dateTo)->format('M d, Y') }}</td>
                <td><strong>Generated:</strong> {{ $generatedAt->format('M d, Y h:i A') }}</td>
            </tr>
        </table>
    </div>

    <h2>Sales by Date</h2>
    <table class="report">
        <thead>
            <tr><th>Date</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Bank</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByDate as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->report_date)->format('M d, Y') }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->bank_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Sales by Branch</h2>
    <table class="report">
        <thead>
            <tr><th>Branch</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Bank</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByBranch as $row)
                <tr>
                    <td>{{ $row->branch_name }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->bank_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No branch sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Physical Collections by Branch</h2>
    <table class="report">
        <thead>
            <tr><th>Collected At</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Bank</th><th class="right">Collected</th></tr>
        </thead>
        <tbody>
            @forelse($collectionsByBranch as $row)
                <tr>
                    <td>{{ $row->branch_name }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->bank_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No branch collections found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Cross-Branch Collections for Remittance</h2>
    <table class="report">
        <thead>
            <tr><th>Payment</th><th>JO #</th><th>Sales Branch</th><th>Collected At</th><th>Status</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @forelse($crossBranchCollections as $payment)
                <tr>
                    <td>{{ $payment->payment_number }}</td>
                    <td>{{ $payment->jobOrder?->job_order_number }}</td>
                    <td>{{ $payment->branch?->name }}</td>
                    <td>{{ $payment->collectedBranch?->name }}</td>
                    <td>{{ \App\Support\StatusBadge::label($payment->settlement_status ?: 'pending') }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No cross-branch collections found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Receivables</h2>
    <table class="report">
        <thead>
            <tr><th>JO #</th><th>Customer</th><th>Branch</th><th class="right">Balance</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse($receivables as $order)
                <tr>
                    <td>{{ $order->job_order_number }}</td>
                    <td>{{ $order->customer?->name }}</td>
                    <td>{{ $order->branch?->name }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $order->balance, 2) }}</td>
                    <td>{{ \App\Support\StatusBadge::label($order->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No receivables found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Inventory Usage</h2>
    <table class="report">
        <thead>
            <tr><th>Item</th><th>Branch</th><th class="right">Qty Out</th><th>Remarks</th><th>Date</th></tr>
        </thead>
        <tbody>
            @forelse($inventoryUsage as $movement)
                <tr>
                    <td>{{ $movement->inventory?->name }}</td>
                    <td>{{ $movement->inventory?->branch?->name }}</td>
                    <td class="right">{{ number_format((float) $movement->quantity, 4) }} {{ $movement->inventory?->unit }}</td>
                    <td>{{ $movement->remarks }}</td>
                    <td>{{ $movement->created_at->format('M d, Y h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No usage found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Sales Payment Type</h2>
    <table class="report">
        <thead>
            <tr><th>Type</th><th class="right">Count</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @forelse($paymentTypes as $row)
                <tr>
                    <td>{{ str_replace('_', ' ', ucfirst($row->payment_type)) }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No payments found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>GCash Reference Breakdown</h2>
    <table class="report">
        <thead>
            <tr><th>Date</th><th>Payment #</th><th>JO #</th><th>Customer</th><th>Reference</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @forelse($gcashPayments as $payment)
                <tr>
                    <td>{{ $payment->paid_at?->format('M d, Y h:i A') }}</td>
                    <td>{{ $payment->payment_number }}</td>
                    <td>{{ $payment->jobOrder?->job_order_number }}</td>
                    <td>{{ $payment->customer?->name }}</td>
                    <td>{{ $payment->reference_no ?: 'No reference' }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No GCash payments found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Expenses</h2>
    <p class="muted">Store cash: {{ $currency }} {{ number_format((float) ($expenseSummary->store_cash_expenses ?? 0), 2) }} | Owner paid / record only: {{ $currency }} {{ number_format((float) ($expenseSummary->owner_expenses ?? 0), 2) }} | Cash advance: {{ $currency }} {{ number_format((float) $cashAdvanceTotal, 2) }}</p>
    <table class="report">
        <thead>
            <tr><th>Date</th><th>Branch</th><th>Expense</th><th>Paid From</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @forelse($expenses as $expense)
                <tr>
                    <td>{{ $expense->expense_date?->format('M d, Y') }}</td>
                    <td>{{ $expense->branch?->name }}</td>
                    <td>{{ $expense->title }}<br><span class="muted">{{ $expense->category }} - {{ \App\Support\StatusBadge::label($expense->expense_type ?? 'regular') }}</span></td>
                    <td>{{ $expense->paid_from === 'owner' ? 'Owner Paid / Record Only' : 'Store Cash' }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $expense->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No expenses found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Customer Ledger</h2>
    <table class="report">
        <thead>
            <tr><th>Customer</th><th>Type</th><th class="right">Amount</th><th class="right">Running</th><th>Description</th></tr>
        </thead>
        <tbody>
            @forelse($customerLedger as $entry)
                <tr>
                    <td>{{ $entry->customer?->name }}</td>
                    <td>{{ ucfirst($entry->entry_type) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $entry->amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $entry->running_balance, 2) }}</td>
                    <td>{{ $entry->description }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No ledger entries found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Activity Logs</h2>
    <table class="report">
        <thead>
            <tr><th>Action</th><th>User</th><th>Branch</th><th>Details</th><th>Date</th></tr>
        </thead>
        <tbody>
            @if($activityLogs->isEmpty())
                <tr><td colspan="5" class="empty">No activity logs found.</td></tr>
            @endif

            @foreach($activityLogs as $log)
                <tr>
                    <td>{{ str_replace('_', ' ', ucfirst($log->action)) }}</td>
                    <td>{{ $log->user?->name ?? 'System' }}</td>
                    <td>{{ $log->branch?->name ?? 'N/A' }}</td>
                    <td>
                        {{ collect($log->properties ?? [])->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? $value : json_encode($value)))->implode(' | ') ?: 'N/A' }}
                    </td>
                    <td>{{ $log->created_at->format('M d, Y h:i A') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
