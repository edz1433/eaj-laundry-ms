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
use App\Models\JobOrderItem;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use App\Support\ServiceCategories;
use App\Models\AccountsPayable;
use App\Models\AccountsPayablePayment;
use App\Models\MoneyMovement;
use App\Models\SmsLog;
use App\Support\FinancialReconciliation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private const DENOMINATIONS = [
        '1000' => 'PHP 1,000', '500' => 'PHP 500', '200' => 'PHP 200',
        '100' => 'PHP 100', '50' => 'PHP 50', '20' => 'PHP 20',
        '10' => 'PHP 10', '5' => 'PHP 5', '1' => 'PHP 1', '0.25' => 'PHP 0.25',
    ];

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

    public function zReadingPdf(Request $request)
    {
        $data = $this->consolidatedZReadingData($request);
        $pdf = Pdf::loadView('admin.z-readings.pdf', $data)->setPaper('a4', 'landscape');

        return $pdf->stream('z-reading-'.$data['dateFrom'].'-to-'.$data['dateTo'].'.pdf');
    }

    private function consolidatedZReadingData(Request $request): array
    {
        $user = $request->user();
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $user->isAdmin() ? ($request->integer('branch_id') ?: null) : $user->branch_id;
        $financial = FinancialReconciliation::forPeriod($branchId, $dateFrom, $dateTo);
        $branch = $branchId
            ? Branch::query()->findOrFail($branchId)
            : new Branch(['name' => 'All Branches', 'code' => 'ALL', 'machine_count' => Branch::query()->max('machine_count')]);

        $orders = JobOrder::query()
            ->with([
                'customer:id,name,address,billing_type',
                'items:id,job_order_id,laundry_service_id,description,service_category,quantity,unit_price,total',
                'items.service:id,name,report_category',
                'payments' => fn ($query) => $query
                    ->whereIn('payment_type', ['cash', 'gcash'])
                    ->whereDate('paid_at', '>=', $dateFrom)
                    ->whereDate('paid_at', '<=', $dateTo)
                    ->orderBy('paid_at'),
            ])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', '!=', 'cancelled')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $payments = Payment::query()
            ->with(['customer:id,name', 'jobOrder:id,job_order_number,created_at'])
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo)
            ->whereIn('payment_type', ['cash', 'gcash'])
            ->orderBy('paid_at')
            ->get();
        $currentPayments = $payments->filter(fn (Payment $payment) => $payment->jobOrder?->created_at?->betweenIncluded($dateFrom, Carbon::parse($dateTo)->endOfDay()));
        $previousPayments = $payments->diff($currentPayments);
        $paymentTotals = fn ($items) => $items->groupBy('payment_type')
            ->map(fn ($group) => round((float) $group->sum('amount'), 2))
            ->all();

        $expenses = BranchExpense::query()
            ->with('creator:id,name')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('expense_date', '>=', $dateFrom)
            ->whereDate('expense_date', '<=', $dateTo)
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get();

        $serviceTotals = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->orderByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->get([
                DB::raw('COALESCE(laundry_services.name, job_order_items.description) as service_name'),
                DB::raw('SUM(job_order_items.quantity) as quantity'),
                DB::raw('SUM(job_order_items.total) as total_amount'),
            ]);

        $inventoryUsage = InventoryMovement::query()
            ->with('inventory:id,branch_id,name,unit')
            ->where('movement_type', 'out')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($branchId, fn ($query) => $query->whereHas('inventory', fn ($query) => $query->where('branch_id', $branchId)))
            ->get()
            ->groupBy(fn (InventoryMovement $movement) => $movement->inventory_id)
            ->map(fn ($group) => [
                'item_name' => $group->first()->inventory?->name,
                'quantity' => round((float) $group->sum('quantity'), 4),
                'unit' => $group->first()->inventory?->unit,
            ])
            ->values();

        $machineCycles = DB::table('cycle_records')
            ->join('job_orders', 'job_orders.id', '=', 'cycle_records.job_order_id')
            ->whereNull('job_orders.deleted_at')
            ->whereIn('cycle_records.cycle_type', ['wash', 'dry'])
            ->whereNotNull('cycle_records.machine_number')
            ->when($branchId, fn ($query) => $query->whereRaw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id) = ?', [$branchId]))
            ->whereDate('cycle_records.started_at', '>=', $dateFrom)
            ->whereDate('cycle_records.started_at', '<=', $dateTo)
            ->groupBy('cycle_records.machine_number', 'cycle_records.cycle_type')
            ->orderBy('cycle_records.machine_number')
            ->get([
                'cycle_records.machine_number',
                'cycle_records.cycle_type',
                DB::raw('COUNT(*) as cycle_count'),
            ]);

        $readings = ZReading::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->orderBy('business_date')
            ->get();
        $cashCount = collect(self::DENOMINATIONS)->mapWithKeys(fn ($label, $value) => [
            $value => $readings->sum(fn (ZReading $reading) => (int) data_get($reading->cash_count, $value, 0)),
        ])->all();
        $machineCounters = $this->consolidatedMachineCounters($readings);

        $reading = new ZReading([
            'reading_number' => 'ZR-'.($branch->code ?: 'ALL').'-'.Carbon::parse($dateFrom)->format('Ymd').'-'.Carbon::parse($dateTo)->format('Ymd'),
            'business_date' => $dateTo,
            'cash_count' => $cashCount,
            'machine_counters' => $machineCounters,
            'expected_cash_amount' => $financial['cash_collections'],
            'cash_expense_amount' => $financial['store_cash_expenses'],
            'expected_cash_drawer_amount' => $financial['expected_cash_drawer'],
            'actual_cash_amount' => $readings->sum('actual_cash_amount'),
            'expected_gcash_amount' => $financial['expected_gcash'],
            'actual_gcash_amount' => $readings->sum('actual_gcash_amount'),
            'expected_total_amount' => (float) $financial['expected_cash_drawer'] + (float) $financial['expected_gcash'],
            'actual_total_amount' => (float) $readings->sum('actual_cash_amount') + (float) $readings->sum('actual_gcash_amount'),
            'over_short_amount' => $readings->sum('over_short_amount'),
            'transaction_count' => $orders->count(),
            'signature_name' => $user->name,
            'closed_at' => now(),
        ]);
        $reading->setRelation('branch', $branch);
        $reading->setRelation('preparer', $user);

        $orderItems = $orders->map(fn (JobOrder $order) => [
            'job_order_number' => $order->job_order_number,
            'customer_name' => $order->customer?->name,
            'address' => $order->customer?->address,
            'created_at' => $order->created_at?->toDateTimeString(),
            'total' => round((float) $order->total, 2),
            'balance' => round((float) $order->balance, 2),
            'notes' => $order->notes,
            'service_amounts' => $order->items
                ->groupBy(fn ($item) => $item->service_category ?: $item->service?->report_category ?: 'other')
                ->map(fn ($items) => round((float) $items->sum('total'), 2))
                ->all(),
            'payments' => $order->payments->map(fn (Payment $payment) => [
                'type' => $payment->payment_type,
                'amount' => round((float) $payment->amount, 2),
                'reference_no' => $payment->reference_no,
            ])->values()->all(),
        ])->values()->all();

        $details = [
            'job_order_items' => $orderItems,
            'daily_total_sales' => round((float) $orders->sum('total'), 2),
            'daily_unpaid_amount' => round((float) $orders->sum('balance'), 2),
            'payment_breakdown' => [
                'current_sales' => $paymentTotals($currentPayments),
                'previous_payment_items' => $previousPayments->map(fn (Payment $payment) => [
                    'paid_at' => $payment->paid_at?->toDateTimeString(),
                    'job_order_number' => $payment->jobOrder?->job_order_number,
                    'customer_name' => $payment->customer?->name,
                    'type' => $payment->payment_type,
                    'reference_no' => $payment->reference_no,
                    'amount' => round((float) $payment->amount, 2),
                ])->values()->all(),
            ],
            'expense_breakdown' => [
                'money_movements' => ['cash_in' => $financial['cash_in'], 'cash_out' => $financial['cash_out']],
                'items' => $expenses->map(fn (BranchExpense $expense) => [
                    'title' => $expense->title, 'category' => $expense->category,
                    'payment_method' => $expense->payment_method, 'paid_from' => $expense->paid_from,
                    'reference_no' => $expense->reference_no, 'remarks' => $expense->remarks,
                    'amount' => round((float) $expense->amount, 2),
                ])->all(),
            ],
            'service_totals' => $serviceTotals->map(fn ($row) => [
                'service_name' => $row->service_name,
                'quantity' => round((float) $row->quantity, 2),
                'total_amount' => round((float) $row->total_amount, 2),
            ])->all(),
            'inventory_usage' => $inventoryUsage->all(),
            'machine_cycles' => $machineCycles->map(fn ($row) => [
                'machine_number' => (int) $row->machine_number,
                'cycle_type' => $row->cycle_type,
                'cycle_count' => (int) $row->cycle_count,
            ])->all(),
        ];

        $signatories = User::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('role', 'branch_manager')
            ->where('status', 'active')
            ->pluck('name')
            ->all();

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'dateRangeLabel' => Carbon::parse($dateFrom)->format('M d, Y').' to '.Carbon::parse($dateTo)->format('M d, Y'),
            'documentTitle' => 'Daily Z Reading',
            'documentNumber' => $reading->reading_number,
            'generatedAt' => now(),
            'denominations' => self::DENOMINATIONS,
            'details' => $details,
            'reading' => $reading,
            'settings' => SystemSetting::current(),
            'signatories' => ['branch_manager' => $signatories, 'cashier' => []],
        ];
    }

    private function consolidatedMachineCounters($readings): array
    {
        $counters = [];

        foreach ($readings as $reading) {
            foreach ($reading->machine_counters ?? [] as $machine => $types) {
                foreach (['wash', 'dry'] as $type) {
                    $beginning = data_get($types, "{$type}.beginning");
                    $ending = data_get($types, "{$type}.ending");
                    $total = data_get($types, "{$type}.total");
                    $counters[$machine][$type]['beginning'] ??= $beginning;
                    if ($ending !== null) {
                        $counters[$machine][$type]['ending'] = $ending;
                    }
                    $counters[$machine][$type]['total'] = ($counters[$machine][$type]['total'] ?? 0) + (int) ($total ?? 0);
                }
            }
        }

        return $counters;
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

        $zReadings = ZReading::query()
            ->with(['branch:id,name', 'preparer:id,name'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', '>=', $dateFrom)
            ->whereDate('business_date', '<=', $dateTo)
            ->orderBy('business_date')
            ->orderBy('branch_id')
            ->get();

        $zReadingSummary = (object) [
            'reading_count' => $zReadings->count(),
            'transaction_count' => $zReadings->sum('transaction_count'),
            'expected_total' => round((float) $zReadings->sum(fn (ZReading $reading) => (float) $reading->expected_cash_drawer_amount + (float) $reading->expected_gcash_amount), 2),
            'actual_total' => round((float) $zReadings->sum(fn (ZReading $reading) => (float) $reading->actual_cash_amount + (float) $reading->actual_gcash_amount), 2),
            'over_short' => round((float) $zReadings->sum(fn (ZReading $reading) => ((float) $reading->actual_cash_amount + (float) $reading->actual_gcash_amount) - ((float) $reading->expected_cash_drawer_amount + (float) $reading->expected_gcash_amount)), 2),
            'expected_cash' => round((float) $zReadings->sum('expected_cash_drawer_amount'), 2),
            'actual_cash' => round((float) $zReadings->sum('actual_cash_amount'), 2),
            'expected_gcash' => round((float) $zReadings->sum('expected_gcash_amount'), 2),
            'actual_gcash' => round((float) $zReadings->sum('actual_gcash_amount'), 2),
        ];

        $zPaymentSummary = Payment::query()
            ->when($branchId, fn ($query) => $query->where('collected_branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo)
            ->whereIn('payment_type', ['cash', 'gcash'])
            ->selectRaw('payment_type, COUNT(*) as payments_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('payment_type')
            ->orderBy('payment_type')
            ->get();

        $zPreviousPaymentTotal = Payment::query()
            ->join('job_orders', 'job_orders.id', '=', 'payments.job_order_id')
            ->when($branchId, fn ($query) => $query->where('payments.collected_branch_id', $branchId))
            ->whereDate('payments.paid_at', '>=', $dateFrom)
            ->whereDate('payments.paid_at', '<=', $dateTo)
            ->whereIn('payments.payment_type', ['cash', 'gcash'])
            ->whereRaw('DATE(job_orders.created_at) < DATE(payments.paid_at)')
            ->sum('payments.amount');

        $zServiceTotals = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->leftJoin('laundry_services', 'laundry_services.id', '=', 'job_order_items.laundry_service_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->orderByRaw('COALESCE(laundry_services.name, job_order_items.description)')
            ->get([
                DB::raw('COALESCE(laundry_services.name, job_order_items.description) as service_name'),
                DB::raw('SUM(job_order_items.quantity) as quantity'),
                DB::raw('SUM(job_order_items.total) as total_amount'),
            ]);

        $zCategoryTotals = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->whereIn('job_order_items.service_category', ServiceCategories::keys())
            ->groupBy('job_order_items.service_category')
            ->get([
                'job_order_items.service_category',
                DB::raw('SUM(job_order_items.quantity) as quantity'),
                DB::raw('SUM(job_order_items.total) as total_amount'),
            ])
            ->keyBy('service_category');

        $zDailyServiceTotals = JobOrderItem::query()
            ->join('job_orders', 'job_orders.id', '=', 'job_order_items.job_order_id')
            ->join('branches', 'branches.id', '=', 'job_orders.branch_id')
            ->whereNull('job_orders.deleted_at')
            ->where('job_orders.status', '!=', 'cancelled')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->groupByRaw('DATE(job_orders.created_at), job_orders.branch_id, branches.name')
            ->orderByRaw('DATE(job_orders.created_at), branches.name')
            ->get([
                DB::raw('DATE(job_orders.created_at) as business_date'),
                'job_orders.branch_id',
                'branches.name as branch_name',
                ...collect(ServiceCategories::keys())
                    ->reject(fn (string $category) => $category === 'other')
                    ->map(fn (string $category) => DB::raw("SUM(CASE WHEN job_order_items.service_category = '{$category}' THEN job_order_items.total ELSE 0 END) as {$category}_amount"))
                    ->all(),
            ]);

        $zDailyOrders = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', '!=', 'cancelled')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->selectRaw('DATE(created_at) as business_date, branch_id, COUNT(*) as order_count, COALESCE(SUM(total), 0) as sales_amount, COALESCE(SUM(balance), 0) as unpaid_amount')
            ->groupByRaw('DATE(created_at), branch_id')
            ->get()
            ->keyBy(fn ($row) => $row->business_date.'-'.$row->branch_id);

        $zDailyPayments = Payment::query()
            ->join('job_orders', 'job_orders.id', '=', 'payments.job_order_id')
            ->when($branchId, fn ($query) => $query->where('job_orders.branch_id', $branchId))
            ->whereDate('job_orders.created_at', '>=', $dateFrom)
            ->whereDate('job_orders.created_at', '<=', $dateTo)
            ->whereIn('payments.payment_type', ['cash', 'gcash'])
            ->whereRaw('DATE(payments.paid_at) = DATE(job_orders.created_at)')
            ->selectRaw("DATE(job_orders.created_at) as business_date, job_orders.branch_id, COALESCE(SUM(CASE WHEN payments.payment_type = 'cash' THEN payments.amount ELSE 0 END), 0) as cash_amount, COALESCE(SUM(CASE WHEN payments.payment_type = 'gcash' THEN payments.amount ELSE 0 END), 0) as gcash_amount")
            ->groupByRaw('DATE(job_orders.created_at), job_orders.branch_id')
            ->get()
            ->keyBy(fn ($row) => $row->business_date.'-'.$row->branch_id);

        $zDailyOperations = $zDailyServiceTotals->map(function ($row) use ($zDailyOrders, $zDailyPayments) {
            $key = $row->business_date.'-'.$row->branch_id;
            $order = $zDailyOrders->get($key);
            $payment = $zDailyPayments->get($key);
            $row->order_count = (int) ($order?->order_count ?? 0);
            $row->sales_amount = round((float) ($order?->sales_amount ?? 0), 2);
            $row->unpaid_amount = round((float) ($order?->unpaid_amount ?? 0), 2);
            $row->cash_amount = round((float) ($payment?->cash_amount ?? 0), 2);
            $row->gcash_amount = round((float) ($payment?->gcash_amount ?? 0), 2);

            return $row;
        });

        $zMachineCycles = DB::table('cycle_records')
            ->join('job_orders', 'job_orders.id', '=', 'cycle_records.job_order_id')
            ->join('branches', 'branches.id', '=', DB::raw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id)'))
            ->whereNull('job_orders.deleted_at')
            ->whereIn('cycle_records.cycle_type', ['wash', 'dry'])
            ->whereNotNull('cycle_records.machine_number')
            ->when($branchId, fn ($query) => $query->whereRaw('COALESCE(job_orders.processing_branch_id, job_orders.branch_id) = ?', [$branchId]))
            ->whereDate('cycle_records.started_at', '>=', $dateFrom)
            ->whereDate('cycle_records.started_at', '<=', $dateTo)
            ->groupBy('branches.name', 'cycle_records.machine_number', 'cycle_records.cycle_type')
            ->orderBy('branches.name')
            ->orderBy('cycle_records.machine_number')
            ->get([
                'branches.name as branch_name',
                'cycle_records.machine_number',
                'cycle_records.cycle_type',
                DB::raw('COUNT(*) as cycle_count'),
            ]);

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
            'zMachineCycles' => $zMachineCycles,
            'zCategoryLabels' => collect(ServiceCategories::LABELS)->except('other')->all(),
            'zCategoryTotals' => $zCategoryTotals,
            'zDailyOperations' => $zDailyOperations,
            'zPaymentSummary' => $zPaymentSummary,
            'zPreviousPaymentTotal' => round((float) $zPreviousPaymentTotal, 2),
            'zReadings' => $zReadings,
            'zReadingSummary' => $zReadingSummary,
            'zServiceTotals' => $zServiceTotals,
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
