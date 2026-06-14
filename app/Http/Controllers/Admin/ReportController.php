<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\CustomerLedger;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\AccountsPayable;
use App\Models\AccountsPayablePayment;
use App\Models\MoneyMovement;
use App\Models\SmsLog;
use App\Support\FinancialReconciliation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.reports.index', $this->reportData($request));
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        $branchName = $data['selectedBranchId']
            ? $data['branches']->firstWhere('id', $data['selectedBranchId'])?->name
            : 'All branches';

        $pdf = Pdf::loadView('admin.reports.pdf', [
            ...$data,
            'branchName' => $branchName,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('reports-'.$data['dateFrom'].'-to-'.$data['dateTo'].'.pdf');
    }

    private function reportData(Request $request): array
    {
        $user = $request->user();
        $canChooseBranch = $user->isAdmin();
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $canChooseBranch ? ($request->integer('branch_id') ?: null) : $user->branch_id;
        $financialSummary = FinancialReconciliation::forPeriod($branchId, $dateFrom, $dateTo);

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $payments = Payment::query()
            ->with(['branch', 'collectedBranch', 'customer', 'jobOrder', 'receiver'])
            ->whereIn('payment_type', ['cash', 'gcash', 'unpaid', 'po'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $collections = Payment::query()
            ->with(['branch', 'collectedBranch', 'customer', 'jobOrder', 'receiver'])
            ->whereIn('payment_type', ['cash', 'gcash'])
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereIn('payment_type', ['cash', 'gcash', 'bank'])
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $salesByDate = (clone $payments)
            ->selectRaw("DATE(paid_at) as report_date, COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(CASE WHEN payment_type = 'cash' THEN amount ELSE 0 END), 0) as cash_amount, COALESCE(SUM(CASE WHEN payment_type = 'gcash' THEN amount ELSE 0 END), 0) as gcash_amount, COALESCE(SUM(CASE WHEN payment_type = 'bank' THEN amount ELSE 0 END), 0) as bank_amount, COUNT(*) as payments_count")
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get();

        $salesByBranch = (clone $payments)
            ->join('branches', 'payments.branch_id', '=', 'branches.id')
            ->selectRaw("branches.name as branch_name, COALESCE(SUM(payments.amount), 0) as total_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'cash' THEN payments.amount ELSE 0 END), 0) as cash_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'gcash' THEN payments.amount ELSE 0 END), 0) as gcash_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'bank' THEN payments.amount ELSE 0 END), 0) as bank_amount, COUNT(*) as payments_count")
            ->groupBy('branches.name')
            ->orderByDesc('total_amount')
            ->get();

        $paymentTypes = (clone $payments)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->orderByDesc('total_amount')
            ->get();

        $gcashPayments = (clone $payments)
            ->where('payment_type', 'gcash')
            ->latest('paid_at')
            ->limit(80)
            ->get();

        $collectionsByBranch = (clone $collections)
            ->join('branches', 'payments.collected_branch_id', '=', 'branches.id')
            ->selectRaw("branches.name as branch_name, COALESCE(SUM(payments.amount), 0) as total_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'cash' THEN payments.amount ELSE 0 END), 0) as cash_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'gcash' THEN payments.amount ELSE 0 END), 0) as gcash_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'bank' THEN payments.amount ELSE 0 END), 0) as bank_amount, COUNT(*) as payments_count")
            ->groupBy('branches.name')
            ->orderByDesc('total_amount')
            ->get();

        $crossBranchCollections = (clone $collections)
            ->whereColumn('collected_branch_id', '!=', 'branch_id')
            ->latest('paid_at')
            ->limit(80)
            ->get();

        $receivables = JobOrder::query()
            ->with(['branch', 'customer'])
            ->where('balance', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->limit(30)
            ->get();

        $inventoryUsage = InventoryMovement::query()
            ->with(['inventory.branch', 'user'])
            ->where('movement_type', 'out')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->whereHas('inventory', fn ($query) => $query->where('branch_id', $branchId)))
            ->latest()
            ->limit(40)
            ->get();

        $customerLedger = CustomerLedger::query()
            ->with(['customer', 'branch'])
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->limit(40)
            ->get();

        $activityLogs = ActivityLog::query()
            ->with(['user', 'branch'])
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->limit(40)
            ->get();

        $expenses = BranchExpense::query()
            ->with(['branch', 'creator'])
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('expense_date')
            ->latest()
            ->limit(60)
            ->get();

        $expenseSummary = BranchExpense::query()
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw("COALESCE(SUM(amount), 0) as total_expenses, COALESCE(SUM(CASE WHEN paid_from = 'store_cash' THEN amount ELSE 0 END), 0) as store_cash_expenses, COALESCE(SUM(CASE WHEN paid_from = 'owner' THEN amount ELSE 0 END), 0) as owner_expenses")
            ->first();

        $accountsPayables = AccountsPayable::query()
            ->with(['branch', 'payments'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('funded_at', '>=', $dateFrom)
            ->whereDate('funded_at', '<=', $dateTo)
            ->latest('funded_at')
            ->limit(60)
            ->get();

        $accountsPayableSummary = AccountsPayable::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('funded_at', '>=', $dateFrom)
            ->whereDate('funded_at', '<=', $dateTo)
            ->selectRaw('COALESCE(SUM(original_amount), 0) as original_total, COALESCE(SUM(paid_amount), 0) as paid_total, COALESCE(SUM(balance), 0) as balance_total')
            ->first();

        $accountsPayablePayments = AccountsPayablePayment::query()
            ->with(['payable', 'branch', 'recorder'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->latest('payment_date')
            ->limit(60)
            ->get();

        $moneyMovements = MoneyMovement::query()
            ->with(['branch', 'recorder'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('movement_date', '>=', $dateFrom)
            ->whereDate('movement_date', '<=', $dateTo)
            ->latest('movement_date')
            ->limit(60)
            ->get();

        $smsSummary = SmsLog::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued")
            ->first();

        $jobOrderSummary = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw("COUNT(*) as total_orders, SUM(CASE WHEN is_rush = 1 THEN 1 ELSE 0 END) as rush_orders, COALESCE(SUM(total), 0) as order_value, COALESCE(SUM(balance), 0) as unpaid_balance")
            ->first();

        $loyalCustomerCount = Customer::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->has('jobOrders', '>=', 10)
            ->count();

        return [
            'activityLogs' => $activityLogs,
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'customerLedger' => $customerLedger,
            'dateFrom' => $dateFrom,
            'dateRangeValue' => $dateFrom.' to '.$dateTo,
            'dateTo' => $dateTo,
            'expenses' => $expenses,
            'expenseSummary' => $expenseSummary,
            'accountsPayables' => $accountsPayables,
            'accountsPayableSummary' => $accountsPayableSummary,
            'accountsPayablePayments' => $accountsPayablePayments,
            'moneyMovements' => $moneyMovements,
            'smsSummary' => $smsSummary,
            'jobOrderSummary' => $jobOrderSummary,
            'loyalCustomerCount' => $loyalCustomerCount,
            'financialSummary' => $financialSummary,
            'collectionsByBranch' => $collectionsByBranch,
            'crossBranchCollections' => $crossBranchCollections,
            'gcashPayments' => $gcashPayments,
            'inventoryUsage' => $inventoryUsage,
            'paymentTypes' => $paymentTypes,
            'receivables' => $receivables,
            'salesByBranch' => $salesByBranch,
            'salesByDate' => $salesByDate,
            'selectedBranchId' => $branchId,
            'settings' => SystemSetting::current(),
        ];
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);

            return [
                $this->parseDate($parts[0] ?? null, today()->subDays(6)->toDateString()),
                $this->parseDate($parts[1] ?? $parts[0] ?? null, today()->toDateString()),
            ];
        }

        return [today()->subDays(6)->toDateString(), today()->toDateString()];
    }

    private function parseDate(?string $date, string $fallback): string
    {
        if (! $date) {
            return $fallback;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
