<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $reading->reading_number }}</title>
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
            margin: 18px 0 8px;
            padding-bottom: 6px;
        }
        table { border-collapse: collapse; width: 100%; }
        th {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            color: #374151;
            font-size: 10px;
            padding: 6px;
            text-align: left;
            text-transform: uppercase;
        }
        td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            vertical-align: top;
        }
        .header {
            border-bottom: 2px solid #111827;
            margin-bottom: 12px;
            padding-bottom: 12px;
        }
        .meta td {
            border: 0;
            color: #374151;
            padding: 2px 14px 2px 0;
            white-space: nowrap;
        }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .summary td { font-size: 12px; }
        .strong { font-weight: bold; }
        .negative { color: #dc2626; }
        .positive { color: #059669; }
        .signature {
            margin-top: 46px;
            width: 100%;
        }
        .signature table td {
            border: 0;
            padding: 0 18px 0 0;
            width: 33.333%;
        }
        .signature-line {
            border-top: 1px solid #111827;
            padding-top: 6px;
            text-align: center;
        }
        .accounting-note {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            color: #4b5563;
            margin: 10px 0 0;
            padding: 7px 8px;
        }
        .total-row td {
            background: #f3f4f6;
            font-weight: bold;
        }
    </style>
</head>
<body>
    @php
        $currency = $settings->currency ?? 'PHP';
        $paymentAmounts = $reading->payment_breakdown['amounts'] ?? [];
        $paymentCounts = $reading->payment_breakdown['counts'] ?? [];
        $expenseBreakdown = $reading->expense_breakdown ?? [];
        $moneyMovements = $expenseBreakdown['money_movements'] ?? [];
        $payableActivity = $expenseBreakdown['accounts_payable'] ?? [];
        $formatMoney = fn (float $amount) => $currency.' '.number_format(abs($amount), 2);
        $formatSignedMoney = fn (float $amount) => ($amount < 0 ? '- ' : '+ ').$formatMoney($amount);
        $cashVariance = round((float) $reading->actual_cash_amount - (float) $reading->expected_cash_drawer_amount, 2);
        $gcashVariance = round((float) $reading->actual_gcash_amount - (float) $reading->expected_gcash_amount, 2);
    @endphp

    <div class="header">
        <h1>{{ $settings->business_name ?? 'Laundry System' }} Z Reading</h1>
        <p class="muted">End-of-day cash count and payment reconciliation.</p>
        <table class="meta">
            <tr>
                <td><strong>Reading #:</strong> {{ $reading->reading_number }}</td>
                <td><strong>Branch:</strong> {{ $reading->branch?->name }}</td>
                <td><strong>Business date:</strong> {{ $reading->business_date?->format('M d, Y') }}</td>
            </tr>
            <tr>
                <td><strong>Prepared by:</strong> {{ $reading->preparer?->name ?? 'System' }}</td>
                <td><strong>Closed:</strong> {{ $reading->closed_at?->format('M d, Y h:i A') }}</td>
                <td><strong>Transactions:</strong> {{ number_format((int) $reading->transaction_count) }}</td>
            </tr>
        </table>
    </div>

    <h2>Payment Breakdown</h2>
    <table>
        <thead>
            <tr>
                <th>Payment Type</th>
                <th class="right">Count</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach(['cash', 'gcash', 'unpaid', 'po'] as $type)
                <tr>
                    <td>{{ \App\Support\StatusBadge::label($type) }}</td>
                    <td class="right">{{ number_format((int) ($paymentCounts[$type] ?? 0)) }}</td>
                    <td class="right positive">{{ $formatSignedMoney((float) ($paymentAmounts[$type] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Cash Count</h2>
    <table>
        <thead>
            <tr>
                <th>Denomination</th>
                <th class="right">Quantity</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($denominations as $value => $label)
                @php($quantity = (int) (($reading->cash_count ?? [])[$value] ?? 0))
                <tr>
                    <td>{{ $label }}</td>
                    <td class="right">{{ number_format($quantity) }}</td>
                    <td class="right">{{ $currency }} {{ number_format((float) $value * $quantity, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Reconciliation</h2>
    <p class="accounting-note">Accounting sign convention: positive (+) means money added or counted; negative (-) means money deducted, paid out, remitted, or short.</p>
    <table class="summary">
        <thead>
            <tr>
                <th>Account / Line Item</th>
                <th>Accounting Treatment</th>
                <th class="right">Amount</th>
                <th class="right">Running / Variance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Cash payments collected</td>
                <td>Cash drawer increase</td>
                <td class="right positive">{{ $formatSignedMoney((float) $reading->expected_cash_amount) }}</td>
                <td class="right"></td>
            </tr>
            <tr>
                <td>Store-cash expenses</td>
                <td>Cash drawer decrease</td>
                <td class="right negative">{{ $formatSignedMoney(-1 * (float) $reading->cash_expense_amount) }}</td>
                <td class="right"></td>
            </tr>
            <tr>
                <td>Petty cash deposits / cash added</td>
                <td>Cash drawer increase</td>
                <td class="right positive">{{ $formatSignedMoney((float) ($moneyMovements['cash_in'] ?? 0)) }}</td>
                <td class="right"></td>
            </tr>
            <tr>
                <td>Petty cash withdrawals / remittances</td>
                <td>Cash drawer decrease</td>
                <td class="right negative">{{ $formatSignedMoney(-1 * (float) ($moneyMovements['cash_out'] ?? 0)) }}</td>
                <td class="right"></td>
            </tr>
            <tr class="total-row">
                <td>Expected cash drawer</td>
                <td>System cash balance</td>
                <td class="right strong">{{ $formatMoney((float) $reading->expected_cash_drawer_amount) }}</td>
                <td class="right"></td>
            </tr>
            <tr>
                <td>Actual cash count</td>
                <td>Physical cash counted</td>
                <td class="right positive">{{ $formatSignedMoney((float) $reading->actual_cash_amount) }}</td>
                <td class="right {{ $cashVariance < 0 ? 'negative' : ($cashVariance > 0 ? 'positive' : '') }}">{{ $formatSignedMoney($cashVariance) }}</td>
            </tr>
            <tr>
                <td>GCash balance</td>
                <td>Collections + owner funding - payable repayments</td>
                <td class="right">{{ $formatMoney((float) $reading->expected_gcash_amount) }} expected / {{ $formatMoney((float) $reading->actual_gcash_amount) }} actual</td>
                <td class="right {{ $gcashVariance < 0 ? 'negative' : ($gcashVariance > 0 ? 'positive' : '') }}">{{ $formatSignedMoney($gcashVariance) }}</td>
            </tr>
            <tr class="total-row">
                <td>Expected total</td>
                <td>Expected enabled balances, including retained legacy records</td>
                <td class="right strong">{{ $formatMoney((float) $reading->expected_total_amount) }}</td>
                <td></td>
            </tr>
            <tr class="total-row">
                <td>Actual total</td>
                <td>Actual enabled balances, including retained legacy records</td>
                <td class="right strong">{{ $formatMoney((float) $reading->actual_total_amount) }}</td>
                <td></td>
            </tr>
            <tr class="total-row">
                <td>Balance over / short</td>
                <td>Actual total less expected total</td>
                <td class="right strong {{ (float) $reading->over_short_amount < 0 ? 'negative' : ((float) $reading->over_short_amount > 0 ? 'positive' : '') }}">
                    {{ $formatSignedMoney((float) $reading->over_short_amount) }}
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Job order range</td>
                <td colspan="3" class="right">{{ $reading->first_job_order_number ?? 'N/A' }} to {{ $reading->last_job_order_number ?? 'N/A' }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Accounts Payable Cashless Activity</h2>
    <table>
        <thead>
            <tr><th>Channel</th><th class="right">Owner Funding</th><th class="right">Repayments</th><th class="right">Net Balance Effect</th></tr>
        </thead>
        <tbody>
            @foreach(['gcash' => 'GCash'] as $method => $label)
                @php($funding = (float) ($payableActivity[$method.'_funding'] ?? 0))
                @php($repayments = (float) ($payableActivity[$method.'_repayments'] ?? 0))
                <tr>
                    <td>{{ $label }}</td>
                    <td class="right positive">{{ $formatSignedMoney($funding) }}</td>
                    <td class="right negative">{{ $formatSignedMoney(-1 * $repayments) }}</td>
                    <td class="right {{ $funding - $repayments < 0 ? 'negative' : 'positive' }}">{{ $formatSignedMoney($funding - $repayments) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Non-Cash Expense Disclosure</h2>
    <table>
        <thead>
            <tr>
                <th>Expense Type</th>
                <th>Accounting Treatment</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Owner-paid expenses recorded</td>
                <td>Recorded expense only; no cash drawer deduction</td>
                <td class="right negative">{{ $formatSignedMoney(-1 * (float) ($expenseBreakdown['owner'] ?? 0)) }}</td>
            </tr>
            <tr>
                <td>Store-funded GCash expenses</td>
                <td>Deducted from expected GCash, not cash drawer</td>
                <td class="right negative">{{ $formatSignedMoney(-1 * (float) ($expenseBreakdown['store_gcash'] ?? 0)) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Money Movements</h2>
    <table>
        <thead>
            <tr>
                <th>Movement</th>
                <th>Direction</th>
                <th>Reference</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($moneyMovements['items'] ?? [] as $movement)
                <tr>
                    <td>{{ $movement['label'] ?? ucfirst(str_replace('_', ' ', $movement['type'] ?? 'movement')) }}<br><span class="muted">{{ $movement['description'] ?? '' }}</span></td>
                    <td>{{ ($movement['direction'] ?? '') === 'in' ? 'Cash In' : 'Cash Out' }}</td>
                    <td>{{ $movement['reference_no'] ?? 'N/A' }}</td>
                    @php($signedMovementAmount = (($movement['direction'] ?? '') === 'in' ? 1 : -1) * (float) ($movement['amount'] ?? 0))
                    <td class="right {{ $signedMovementAmount < 0 ? 'negative' : 'positive' }}">{{ $formatSignedMoney($signedMovementAmount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted" style="text-align: center;">No money movements recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($reading->remarks)
        <h2>Remarks</h2>
        <p>{{ $reading->remarks }}</p>
    @endif

    <div class="signature">
        <table>
            <tr>
                <td>
                    <div class="signature-line">
                        <strong>{{ $reading->signature_name }}</strong><br>
                        <span class="muted">Prepared by</span>
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        <strong>{{ collect($signatories['branch_manager'] ?? [])->implode(', ') ?: 'Branch Manager' }}</strong><br>
                        <span class="muted">Branch manager</span>
                    </div>
                </td>
                <td>
                    <div class="signature-line">
                        <strong>{{ collect($signatories['cashier'] ?? [])->implode(', ') ?: 'Branch Cashier' }}</strong><br>
                        <span class="muted">Branch cashier</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
