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
            <tr><th>Date</th><th class="right">Payments</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByDate as $row)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($row->report_date)->format('M d, Y') }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No sales found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Sales by Branch</h2>
    <table class="report">
        <thead>
            <tr><th>Branch</th><th class="right">Payments</th><th class="right">Sales</th></tr>
        </thead>
        <tbody>
            @forelse($salesByBranch as $row)
                <tr>
                    <td>{{ $row->branch_name }}</td>
                    <td class="right">{{ $row->payments_count }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $row->total_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty">No branch sales found.</td></tr>
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
                    <td>{{ str_replace('_', ' ', ucfirst($order->status)) }}</td>
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

    <h2>Payment Type</h2>
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
