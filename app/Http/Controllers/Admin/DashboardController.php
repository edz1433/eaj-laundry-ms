<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Support\StatusBadge;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->isAdmin();

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'dashboardData' => $this->payload($request),
            'dateRangeValue' => $this->dateRangeValue($request),
            'selectedBranchId' => $this->branchId($request),
            'settings' => SystemSetting::current(),
        ]);
    }

    public function data(Request $request)
    {
        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $branchId = $this->branchId($request);
        $currency = SystemSetting::current()->currency ?: 'PHP';

        $orders = JobOrder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        $ordersInRange = (clone $orders)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo);

        $payments = Payment::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('paid_at', '>=', $dateFrom)
            ->whereDate('paid_at', '<=', $dateTo);

        $salesTotal = (float) (clone $payments)->sum('amount');
        $ordersCount = (clone $ordersInRange)->count();
        $openOrders = (clone $orders)->whereNotIn('status', ['completed', 'cancelled'])->count();
        $readyForPickup = (clone $orders)->where('status', 'ready_for_pickup')->count();
        $receivables = (float) (clone $orders)->where('balance', '>', 0)->sum('balance');
        $lowStock = Inventory::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->count();

        $salesByDate = (clone $payments)
            ->selectRaw('DATE(paid_at) as paid_date, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('paid_date')
            ->pluck('total_amount', 'paid_date');

        $salesLabels = [];
        $salesValues = [];
        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $key = $date->toDateString();
            $salesLabels[] = $date->format('M d');
            $salesValues[] = round((float) ($salesByDate[$key] ?? 0), 2);
        }

        $statusRows = (clone $ordersInRange)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statuses = ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'completed', 'cancelled'];
        $statusLabels = array_map(fn ($status) => StatusBadge::label($status), $statuses);
        $statusValues = array_map(fn ($status) => (int) ($statusRows[$status] ?? 0), $statuses);

        $recentOrders = (clone $orders)
            ->with(['customer', 'branch'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (JobOrder $order) => [
                'id' => $order->id,
                'number' => $order->job_order_number,
                'customer' => $order->customer?->name ?? 'Walk-in',
                'branch' => $order->branch?->name ?? 'N/A',
                'status' => StatusBadge::label($order->status),
                'status_badge' => StatusBadge::classes($order->status),
                'total' => $this->money($currency, (float) $order->total),
                'url' => route('admin.job-orders.show', $order),
            ])
            ->values();

        return [
            'currency' => $currency,
            'generated_at' => now()->format('M d, Y h:i:s A'),
            'stats' => [
                'sales' => $this->money($currency, $salesTotal),
                'orders' => number_format($ordersCount),
                'open_orders' => number_format($openOrders),
                'ready_for_pickup' => number_format($readyForPickup),
                'receivables' => $this->money($currency, $receivables),
                'low_stock' => number_format($lowStock),
            ],
            'charts' => [
                'sales' => [
                    'labels' => $salesLabels,
                    'values' => $salesValues,
                ],
                'status' => [
                    'labels' => $statusLabels,
                    'values' => $statusValues,
                ],
            ],
            'recent_orders' => $recentOrders,
        ];
    }

    private function branchId(Request $request): ?int
    {
        if (! $request->user()->isAdmin()) {
            return $request->user()->branch_id;
        }

        return $request->filled('branch_id') ? (int) $request->branch_id : null;
    }

    private function dateRange(Request $request): array
    {
        if ($request->filled('date_range')) {
            $parts = preg_split('/\s+to\s+/', $request->date_range);
            $from = $this->parseDate($parts[0] ?? null);
            $to = $this->parseDate($parts[1] ?? $parts[0] ?? null);

            return [$from, $to];
        }

        return [today()->subDays(6)->toDateString(), today()->toDateString()];
    }

    private function dateRangeValue(Request $request): string
    {
        [$from, $to] = $this->dateRange($request);

        return $from.' to '.$to;
    }

    private function parseDate(?string $date): string
    {
        if (! $date) {
            return today()->toDateString();
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }

    private function money(string $currency, float $value): string
    {
        return $currency.' '.number_format($value, 2);
    }
}
