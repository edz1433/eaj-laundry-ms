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
        <p class="muted">Sales, operations, unpaid balances, expenses, accounts payable, cash movements, SMS outcomes, inventory, and audit logs.</p>
        <table class="meta">
            <tr>
                <td><strong>Branch:</strong> {{ $branchName }}</td>
                <td><strong>Date range:</strong> {{ \Illuminate\Support\Carbon::parse($dateFrom)->format('M d, Y') }} to {{ \Illuminate\Support\Carbon::parse($dateTo)->format('M d, Y') }}</td>
                <td><strong>Generated:</strong> {{ $generatedAt->format('M d, Y h:i A') }}</td>
            </tr>
        </table>
    </div>

    <h2>Financial Reconciliation</h2>
    <p class="muted">Authoritative formula: cash collected + cash deposits/owner cash funding - store-cash expenses - cash withdrawals/remittances/payable repayments.</p>
    <table class="report">
        <thead><tr><th>Metric</th><th class="right">Amount</th><th>Drawer Treatment</th></tr></thead>
        <tbody>
            @foreach([
                'sales_owned' => ['Sales Owned', 'Sales ownership only'],
                'physical_collections' => ['Physical Collections', 'Cash and GCash received'],
                'expected_cash_drawer' => ['Expected Cash Drawer', 'Physical cash only'],
                'expected_gcash' => ['Expected GCash', 'Separate cashless balance'],
                'expenses_total' => ['Recorded Expenses', 'Only store-cash expenses reduce drawer'],
                'accounts_payable' => ['Accounts Payable', 'Only cash repayments reduce drawer'],
                'unpaid_balance' => ['Unpaid Customer Balance', 'No drawer effect until paid'],
                'over_short' => ['Z Reading Over / Short', 'Actual less expected'],
            ] as $key => [$label, $treatment])
                <tr><td>{{ $label }}</td><td class="right">{{ $currency }} {{ number_format((float) $financialSummary[$key], 2) }}</td><td>{{ $treatment }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2>Operational Summary</h2>
    <table class="report">
        <thead><tr><th class="right">Job Orders</th><th class="right">Rush Orders</th><th class="right">Loyal Customers</th><th class="right">Order Value</th><th class="right">Unpaid Balance</th><th class="right">SMS Sent</th><th class="right">SMS Failed</th><th class="right">SMS Queued</th></tr></thead>
        <tbody><tr>
            <td class="right">{{ number_format((int) ($jobOrderSummary->total_orders ?? 0)) }}</td>
            <td class="right">{{ number_format((int) ($jobOrderSummary->rush_orders ?? 0)) }}</td>
            <td class="right">{{ number_format($loyalCustomerCount) }}</td>
            <td class="right">{{ $currency }} {{ number_format((float) ($jobOrderSummary->order_value ?? 0), 2) }}</td>
            <td class="right">{{ $currency }} {{ number_format((float) ($jobOrderSummary->unpaid_balance ?? 0), 2) }}</td>
            <td class="right">{{ number_format((int) ($smsSummary->sent ?? 0)) }}</td>
            <td class="right">{{ number_format((int) ($smsSummary->failed ?? 0)) }}</td>
            <td class="right">{{ number_format((int) ($smsSummary->queued ?? 0)) }}</td>
        </tr></tbody>
    </table>

    <h2>Sales by Date</h2>
    <table class="report">
        <thead>
            <tr><th>Date</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByDate as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->report_date)->format('M d, Y') }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Accounts Payable Repayments</h2>
    <table class="report">
        <thead><tr><th>Date</th><th>Payment</th><th>Payable</th><th>Method</th><th>Reference</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @forelse($accountsPayablePayments as $payment)
                <tr><td>{{ $payment->payment_date?->format('M d, Y') }}</td><td>{{ $payment->payment_number }}</td><td>{{ $payment->payable?->payable_number }}</td><td>{{ \App\Support\StatusBadge::label($payment->payment_method) }}</td><td>{{ $payment->reference_no ?: 'N/A' }}</td><td class="right">{{ $currency }} {{ number_format((float) $payment->amount, 2) }}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">No payable repayments found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Cash Drawer Movements</h2>
    <table class="report">
        <thead><tr><th>Date</th><th>Branch</th><th>Movement</th><th>Reference</th><th>Recorded By</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @forelse($moneyMovements as $movement)
                @php($signedAmount = $movement->direction === 'in' ? (float) $movement->amount : -1 * (float) $movement->amount)
                <tr><td>{{ $movement->movement_date?->format('M d, Y') }}</td><td>{{ $movement->branch?->name }}</td><td>{{ $movement->type_label }}<br><span class="muted">{{ $movement->description }}</span></td><td>{{ $movement->reference_no ?: 'N/A' }}</td><td>{{ $movement->recorder?->name ?? 'System' }}</td><td class="right">{{ $signedAmount < 0 ? '-' : '+' }} {{ $currency }} {{ number_format(abs($signedAmount), 2) }}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">No cash drawer movements found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Sales by Branch</h2>
    <table class="report">
        <thead>
            <tr><th>Branch</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByBranch as $row)
                <tr>
                    <td>{{ $row->branch_name }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No branch sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Physical Collections by Branch</h2>
    <table class="report">
        <thead>
            <tr><th>Collected At</th><th class="right">Payments</th><th class="right">Cash</th><th class="right">GCash</th><th class="right">Collected</th></tr>
        </thead>
        <tbody>
            @forelse($collectionsByBranch as $row)
                <tr>
                    <td>{{ $row->branch_name }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->cash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->gcash_amount, 2) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No branch collections found.</td></tr>
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
                    <td>{{ \App\Support\StatusBadge::label($row->payment_type) }}</td>
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
    <p class="muted">Store-funded: {{ $currency }} {{ number_format((float) ($expenseSummary->store_cash_expenses ?? 0), 2) }} | Owner-paid, reimbursement due: {{ $currency }} {{ number_format((float) ($expenseSummary->owner_expenses ?? 0), 2) }}</p>
    <table class="report">
        <thead>
            <tr><th>Date</th><th>Branch</th><th>Expense</th><th>Paid From</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @forelse($expenses as $expense)
                <tr>
                    <td>{{ $expense->expense_date?->format('M d, Y') }}</td>
                    <td>{{ $expense->branch?->name }}</td>
                    <td>{{ $expense->title }}<br><span class="muted">{{ \App\Support\StatusBadge::label($expense->category) }}</span></td>
                    <td>{{ $expense->paid_from === 'owner' ? 'Owner-paid, reimbursement due' : 'Store-funded' }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $expense->amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No expenses found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Accounts Payable</h2>
    <p class="muted">Original: {{ $currency }} {{ number_format((float) ($accountsPayableSummary->original_total ?? 0), 2) }} | Repaid: {{ $currency }} {{ number_format((float) ($accountsPayableSummary->paid_total ?? 0), 2) }} | Outstanding: {{ $currency }} {{ number_format((float) ($accountsPayableSummary->balance_total ?? 0), 2) }}</p>
    <table class="report">
        <thead><tr><th>Payable</th><th>Branch</th><th>Source</th><th class="right">Original</th><th class="right">Balance</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($accountsPayables as $payable)
                <tr><td>{{ $payable->payable_number }} - {{ $payable->creditor_name }}<br><span class="muted">{{ $payable->description }}</span></td><td>{{ $payable->branch?->name }}</td><td>{{ $payable->source_type === 'owner_paid_expense' ? 'Owner-paid expense' : 'Owner funding' }}</td><td class="right">{{ $currency }} {{ number_format((float) $payable->original_amount, 2) }}</td><td class="right">{{ $currency }} {{ number_format((float) $payable->balance, 2) }}</td><td>{{ \App\Support\StatusBadge::label($payable->status) }}</td></tr>
            @empty
                <tr><td colspan="6" class="empty">No accounts payable found.</td></tr>
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
