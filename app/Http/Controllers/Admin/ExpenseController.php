<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchExpense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        [$dateFrom, $dateTo] = $this->dateRange($request);

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $baseQuery = BranchExpense::query()
            ->with(['branch', 'creator'])
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($canChooseBranch && $request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->when($dateFrom, fn ($query) => $query->whereDate('expense_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            ->when($request->filled('paid_from'), fn ($query) => $query->where('paid_from', $request->paid_from))
            ->when($request->filled('expense_type'), fn ($query) => $query->where('expense_type', $request->expense_type))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->search;

                $query->where(fn ($query) => $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%"));
            });

        $summary = (clone $baseQuery)
            ->selectRaw("COALESCE(SUM(amount), 0) as total_expenses, COALESCE(SUM(CASE WHEN paid_from = 'store_cash' THEN amount ELSE 0 END), 0) as store_cash_expenses, COALESCE(SUM(CASE WHEN paid_from = 'owner' THEN amount ELSE 0 END), 0) as owner_expenses, COALESCE(SUM(CASE WHEN expense_type = 'cash_advance' THEN amount ELSE 0 END), 0) as cash_advance_total")
            ->first();

        $expenses = $baseQuery
            ->latest('expense_date')
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.expenses.index', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'expenses' => $expenses,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => [$user->canManageAllBranches() ? 'required' : 'nullable', 'exists:branches,id'],
            'category' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'paid_from' => ['required', Rule::in(['store_cash', 'owner'])],
            'expense_type' => ['required', Rule::in(['regular', 'cash_advance'])],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        if (! $user->canManageAllBranches()) {
            $validated['branch_id'] = $user->branch_id;
        }

        BranchExpense::create($validated + ['created_by' => $user->id]);

        return back()->with('success', 'Expense recorded successfully.');
    }

    public function destroy(Request $request, BranchExpense $expense)
    {
        if (! $request->user()->canManageAllBranches()) {
            abort_unless((int) $request->user()->branch_id === (int) $expense->branch_id, 403);
        }

        $expense->delete();

        return back()->with('success', 'Expense removed successfully.');
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);

            return [
                $this->parseDate($parts[0] ?? null),
                $this->parseDate($parts[1] ?? $parts[0] ?? null),
            ];
        }

        return [
            $this->parseDate($request->date_from),
            $this->parseDate($request->date_to),
        ];
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
