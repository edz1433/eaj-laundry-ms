<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CustomerLedger;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
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

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $payments = Payment::query()
            ->with(['branch', 'customer', 'jobOrder', 'receiver'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $salesByDate = (clone $payments)
            ->selectRaw('DATE(paid_at) as report_date, COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get();

        $salesByBranch = (clone $payments)
            ->join('branches', 'payments.branch_id', '=', 'branches.id')
            ->selectRaw('branches.name as branch_name, COALESCE(SUM(payments.amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('branches.name')
            ->orderByDesc('total_amount')
            ->get();

        $paymentTypes = (clone $payments)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->orderByDesc('total_amount')
            ->get();

        $receivables = JobOrder::query()
            ->with(['branch', 'customer'])
            ->where('balance', '>', 0)
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

        return [
            'activityLogs' => $activityLogs,
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'customerLedger' => $customerLedger,
            'dateFrom' => $dateFrom,
            'dateRangeValue' => $dateFrom.' to '.$dateTo,
            'dateTo' => $dateTo,
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
