<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PoTransaction;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PoTransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->isAdmin();
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $canChooseBranch ? ($request->integer('branch_id') ?: null) : $user->branch_id;

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $baseQuery = PoTransaction::query()
            ->with(['branch', 'customer', 'jobOrder'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($dateFrom, fn ($query) => $query->whereDate('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('transaction_date', '<=', $dateTo))
            ->when(in_array($request->status, PoTransaction::STATUSES, true), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(function ($query) use ($search) {
                    $query->where('po_number', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('jobOrder', fn ($query) => $query->where('job_order_number', 'like', "%{$search}%"));
                });
            });

        $summary = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(CASE WHEN status = "pending" THEN balance ELSE 0 END), 0) as pending_amount, COALESCE(SUM(paid_amount), 0) as paid_amount, COALESCE(SUM(balance), 0) as outstanding_balance')
            ->first();

        $transactions = $baseQuery
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'billed' THEN 1 WHEN 'partially_paid' THEN 2 WHEN 'paid' THEN 3 ELSE 4 END")
            ->latest('transaction_date')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.po-transactions.index', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'statuses' => PoTransaction::STATUSES,
            'summary' => $summary,
            'transactions' => $transactions,
        ]);
    }

    public function update(Request $request, PoTransaction $poTransaction)
    {
        $this->authorizePoTransaction($request, $poTransaction);

        $validated = $request->validate([
            'status' => ['required', Rule::in(PoTransaction::STATUSES)],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:'.$poTransaction->amount],
        ]);

        $paidAmount = min((float) ($validated['paid_amount'] ?? $poTransaction->paid_amount), (float) $poTransaction->amount);
        $balance = max((float) $poTransaction->amount - $paidAmount, 0);
        $status = $validated['status'];

        if ($balance <= 0) {
            $status = 'paid';
        } elseif ($paidAmount > 0) {
            $status = 'partially_paid';
        }

        $poTransaction->update([
            'paid_amount' => $paidAmount,
            'balance' => $balance,
            'status' => $status,
            'billed_at' => in_array($status, ['billed', 'partially_paid', 'paid'], true) ? ($poTransaction->billed_at ?: now()) : null,
            'paid_at' => $status === 'paid' ? ($poTransaction->paid_at ?: now()) : null,
        ]);

        Activity::log($request, 'po_transaction_updated', $poTransaction, [
            'po_number' => $poTransaction->po_number,
            'status' => $poTransaction->status,
            'paid_amount' => $poTransaction->paid_amount,
        ], $poTransaction->branch_id);

        return back()->with('success', 'PO transaction updated successfully.');
    }

    private function authorizePoTransaction(Request $request, PoTransaction $poTransaction): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless((int) $request->user()->branch_id === (int) $poTransaction->branch_id, 403);
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);

            return [
                $this->parseDate($parts[0] ?? null),
                $this->parseDate($parts[1] ?? ($parts[0] ?? null)),
            ];
        }

        $from = $this->parseDate($request->date_from);
        $to = $this->parseDate($request->date_to);

        if ($from || $to) {
            return [$from, $to];
        }

        return [today()->toDateString(), today()->toDateString()];
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
