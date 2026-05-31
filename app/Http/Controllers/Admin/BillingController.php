<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchBillingRecord;
use App\Models\BranchExpense;
use App\Models\SystemSetting;
use App\Models\SystemTrialSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'billing_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'billing_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status' => ['nullable', Rule::in(['unpaid', 'paid', 'overdue', 'suspended'])],
        ]);

        $trial = SystemTrialSetting::current();
        $branches = Branch::orderBy('name')->get();
        $records = BranchBillingRecord::with(['branch', 'paidBy', 'expense'])
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['billing_year'] ?? null, fn ($query, $year) => $query->where('billing_year', $year))
            ->when($filters['billing_month'] ?? null, fn ($query, $month) => $query->where('billing_month', $month))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('billing_year')
            ->orderByDesc('billing_month')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'paid' => BranchBillingRecord::where('status', 'paid')->sum('amount'),
            'unpaid_count' => BranchBillingRecord::whereIn('status', ['unpaid', 'overdue', 'suspended'])->count(),
            'branches' => $branches->count(),
            'trial_status' => $trial->computedStatus(),
        ];
        $settings = SystemSetting::current();

        return view('admin.billing.index', compact('trial', 'branches', 'records', 'summary', 'settings', 'filters'));
    }

    public function updateTrial(Request $request)
    {
        $validated = $request->validate([
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_start_date' => ['nullable', 'date'],
            'trial_end_date' => ['nullable', 'date', 'after_or_equal:trial_start_date'],
            'trial_remarks' => ['nullable', 'string'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $trial = SystemTrialSetting::current();
        $trial->fill([
            'trial_enabled' => (bool) ($validated['trial_enabled'] ?? false),
            'trial_start_date' => $validated['trial_start_date'] ?? null,
            'trial_end_date' => $validated['trial_end_date'] ?? null,
            'trial_remarks' => $validated['trial_remarks'] ?? null,
            'grace_period_days' => $validated['grace_period_days'],
            'updated_by' => $request->user()->id,
        ]);
        $trial->trial_status = $trial->computedStatus();
        $trial->save();

        return back()->with('success', 'Trial settings updated successfully.');
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => ['integer', 'exists:branches,id'],
            'billing_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'end_month' => ['required', 'integer', 'min:1', 'max:12', 'gte:start_month'],
            'prices' => ['required', 'array'],
            'prices.*' => ['required', 'numeric', 'min:0'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'update_unpaid' => ['nullable', 'boolean'],
        ]);

        foreach ($validated['branches'] as $branchId) {
            if (! array_key_exists($branchId, $validated['prices'])) {
                throw ValidationException::withMessages([
                    "prices.{$branchId}" => 'Each selected branch must have its own monthly price.',
                ]);
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($validated, $request, &$created, &$updated, &$skipped): void {
            foreach ($validated['branches'] as $branchId) {
                $amount = (float) ($validated['prices'][$branchId] ?? 0);

                for ($month = (int) $validated['start_month']; $month <= (int) $validated['end_month']; $month++) {
                    $dueDate = $this->dueDate((int) $validated['billing_year'], $month, (int) $validated['due_day']);
                    $record = BranchBillingRecord::where('branch_id', $branchId)
                        ->where('billing_year', $validated['billing_year'])
                        ->where('billing_month', $month)
                        ->first();

                    if (! $record) {
                        BranchBillingRecord::create([
                            'branch_id' => $branchId,
                            'billing_year' => $validated['billing_year'],
                            'billing_month' => $month,
                            'amount' => $amount,
                            'due_date' => $dueDate,
                            'status' => 'unpaid',
                            'generated_by' => $request->user()->id,
                        ]);
                        $created++;
                        continue;
                    }

                    if (($validated['update_unpaid'] ?? false) && $record->status !== 'paid') {
                        $record->update([
                            'amount' => $amount,
                            'due_date' => $dueDate,
                            'generated_by' => $request->user()->id,
                        ]);
                        $updated++;
                        continue;
                    }

                    $skipped++;
                }
            }
        });

        return back()->with('success', "Billing generated. Created: {$created}. Updated unpaid: {$updated}. Skipped: {$skipped}.");
    }

    public function updateStatus(Request $request, BranchBillingRecord $billingRecord)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['unpaid', 'overdue', 'suspended'])],
        ]);

        if ($billingRecord->status === 'paid') {
            return back()->with('error', 'Paid billing records cannot be changed with status controls.');
        }

        $billingRecord->update([
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Billing status updated successfully.');
    }

    public function markPaid(Request $request, BranchBillingRecord $billingRecord)
    {
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($billingRecord, $validated, $request): void {
            $billingRecord->loadMissing('branch');

            $expense = $billingRecord->expense;

            if (! $expense) {
                $expense = BranchExpense::firstOrCreate(
                    [
                        'source' => 'branch_billing',
                        'source_id' => $billingRecord->id,
                    ],
                    [
                        'branch_id' => $billingRecord->branch_id,
                        'category' => 'System Monthly Subscription',
                        'title' => 'System Billing - '.$billingRecord->periodLabel(),
                        'amount' => $billingRecord->amount,
                        'expense_date' => $validated['payment_date'],
                        'payment_method' => $validated['payment_method'],
                        'reference_no' => $validated['reference_no'] ?? null,
                        'remarks' => $validated['remarks'] ?? null,
                        'created_by' => $request->user()->id,
                    ]
                );
            }

            $billingRecord->update([
                'status' => 'paid',
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference_no' => $validated['reference_no'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'paid_by' => $request->user()->id,
                'expense_id' => $expense->id,
            ]);
        });

        return back()->with('success', 'Billing record marked as paid.');
    }

    private function dueDate(int $year, int $month, int $dueDay): Carbon
    {
        $date = Carbon::create($year, $month, 1);

        return $date->day(min($dueDay, $date->daysInMonth));
    }
}
