<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchExpense;
use App\Models\JobOrder;
use App\Models\MoneyMovement;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\ZReading;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ZReadingController extends Controller
{
    private const DENOMINATIONS = [
        '1000' => 'PHP 1,000',
        '500' => 'PHP 500',
        '200' => 'PHP 200',
        '100' => 'PHP 100',
        '50' => 'PHP 50',
        '20' => 'PHP 20',
        '10' => 'PHP 10',
        '5' => 'PHP 5',
        '1' => 'PHP 1',
        '0.25' => 'PHP 0.25',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;
        $businessDate = $request->date('business_date')?->toDateString() ?: today()->toDateString();

        abort_unless($branchId, 403);

        $branch = $branches->firstWhere('id', $branchId) ?: Branch::query()->findOrFail($branchId);
        if (! $canChooseBranch) {
            abort_unless((int) $user->branch_id === (int) $branch->id, 403);
        }

        $readings = ZReading::query()
            ->with(['branch', 'preparer'])
            ->when(! $canChooseBranch, fn ($query) => $query->where('branch_id', $user->branch_id))
            ->when($canChooseBranch && $request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->branch_id))
            ->latest('business_date')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.z-readings.index', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'readings' => $readings,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $branchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;
        $businessDate = $request->date('business_date')?->toDateString() ?: today()->toDateString();

        abort_unless($branchId, 403);

        $branch = $branches->firstWhere('id', $branchId) ?: Branch::query()->findOrFail($branchId);
        if (! $canChooseBranch) {
            abort_unless((int) $user->branch_id === (int) $branch->id, 403);
        }

        $summary = $this->summary((int) $branch->id, $businessDate);
        $reading = ZReading::query()
            ->with(['branch', 'preparer'])
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate)
            ->first();

        return view('admin.z-readings.create', [
            'branch' => $branch,
            'branches' => $branches,
            'businessDate' => $businessDate,
            'canChooseBranch' => $canChooseBranch,
            'denominations' => self::DENOMINATIONS,
            'reading' => $reading,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();

        $validated = $request->validate([
            'branch_id' => [$canChooseBranch ? 'required' : 'nullable', 'exists:branches,id'],
            'business_date' => ['required', 'date'],
            'cash_count' => ['nullable', 'array'],
            'cash_count.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'actual_gcash_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'actual_bank_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $branchId = $canChooseBranch ? (int) $validated['branch_id'] : (int) $user->branch_id;
        abort_unless($branchId, 403);

        $businessDate = Carbon::parse($validated['business_date'])->toDateString();
        $cashCount = $this->normalizedCashCount($validated['cash_count'] ?? []);
        $actualCash = $this->cashCountTotal($cashCount);
        $actualGcash = round((float) ($validated['actual_gcash_amount'] ?? 0), 2);
        $actualBank = round((float) ($validated['actual_bank_amount'] ?? 0), 2);
        $summary = $this->summary($branchId, $businessDate);
        $actualTotal = round($actualCash + $actualGcash + $actualBank, 2);
        $overShort = round($actualTotal - (float) $summary['expected_total_amount'], 2);

        $reading = DB::transaction(function () use ($branchId, $businessDate, $cashCount, $actualCash, $actualGcash, $actualBank, $actualTotal, $overShort, $summary, $validated, $user): ZReading {
            $reading = ZReading::query()
                ->where('branch_id', $branchId)
                ->whereDate('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if (! $reading) {
                $reading = new ZReading([
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'reading_number' => $this->nextReadingNumber($branchId, $businessDate),
                ]);
            }

            $reading->fill([
                'prepared_by' => $user->id,
                'cash_count' => $cashCount,
                'payment_breakdown' => $summary['payment_breakdown'],
                'expense_breakdown' => $summary['expense_breakdown'],
                'expected_cash_amount' => $summary['expected_cash_amount'],
                'cash_expense_amount' => $summary['cash_expense_amount'],
                'expected_cash_drawer_amount' => $summary['expected_cash_drawer_amount'],
                'actual_cash_amount' => $actualCash,
                'expected_gcash_amount' => $summary['expected_gcash_amount'],
                'actual_gcash_amount' => $actualGcash,
                'expected_bank_amount' => $summary['expected_bank_amount'],
                'actual_bank_amount' => $actualBank,
                'expected_total_amount' => $summary['expected_total_amount'],
                'actual_total_amount' => $actualTotal,
                'over_short_amount' => $overShort,
                'transaction_count' => $summary['transaction_count'],
                'first_job_order_number' => $summary['first_job_order_number'],
                'last_job_order_number' => $summary['last_job_order_number'],
                'signature_name' => $user->name,
                'remarks' => null,
                'closed_at' => now(),
            ]);
            $reading->save();

            return $reading;
        });

        return redirect()
            ->route('admin.z-readings.index', ['branch_id' => $branchId, 'business_date' => $businessDate])
            ->with('success', "Z Reading {$reading->reading_number} saved successfully.");
    }

    public function pdf(Request $request, ZReading $zReading)
    {
        $this->authorizeReading($request, $zReading);

        $zReading->load(['branch', 'preparer']);
        $pdf = Pdf::loadView('admin.z-readings.pdf', [
            'denominations' => self::DENOMINATIONS,
            'reading' => $zReading,
            'settings' => SystemSetting::current(),
            'signatories' => $this->signatories((int) $zReading->branch_id),
        ])->setPaper('a4');

        return $pdf->stream($zReading->reading_number.'.pdf');
    }

    private function summary(int $branchId, string $businessDate): array
    {
        $payments = Payment::query()
            ->where('branch_id', $branchId)
            ->whereDate('paid_at', $businessDate);

        $paymentBreakdown = (clone $payments)
            ->selectRaw('payment_type, COALESCE(SUM(amount), 0) as total_amount, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->pluck('total_amount', 'payment_type')
            ->map(fn ($amount) => round((float) $amount, 2))
            ->all();

        $paymentCounts = (clone $payments)
            ->selectRaw('payment_type, COUNT(*) as payments_count')
            ->groupBy('payment_type')
            ->pluck('payments_count', 'payment_type')
            ->map(fn ($count) => (int) $count)
            ->all();

        $storeCashExpenses = (float) BranchExpense::query()
            ->where('branch_id', $branchId)
            ->where('paid_from', 'store_cash')
            ->whereDate('expense_date', $businessDate)
            ->sum('amount');

        $ownerExpenses = (float) BranchExpense::query()
            ->where('branch_id', $branchId)
            ->where('paid_from', 'owner')
            ->whereDate('expense_date', $businessDate)
            ->sum('amount');

        $moneyMovements = MoneyMovement::query()
            ->with('recorder')
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', $businessDate)
            ->latest()
            ->get();

        $cashInMovements = round((float) $moneyMovements->where('direction', 'in')->sum(fn ($movement) => (float) $movement->amount), 2);
        $cashOutMovements = round((float) $moneyMovements->where('direction', 'out')->sum(fn ($movement) => (float) $movement->amount), 2);

        $jobOrders = JobOrder::query()
            ->where('branch_id', $branchId)
            ->whereDate('created_at', $businessDate)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['job_order_number', 'status']);

        $expectedCash = round((float) ($paymentBreakdown['cash'] ?? 0), 2);
        $expectedGcash = round((float) ($paymentBreakdown['gcash'] ?? 0), 2);
        $expectedBank = round((float) ($paymentBreakdown['bank'] ?? 0), 2);
        $expectedDrawer = round($expectedCash - $storeCashExpenses + $cashInMovements - $cashOutMovements, 2);
        $expectedTotal = round($expectedDrawer + $expectedGcash + $expectedBank, 2);

        return [
            'expected_cash_amount' => $expectedCash,
            'cash_expense_amount' => round($storeCashExpenses, 2),
            'expected_cash_drawer_amount' => $expectedDrawer,
            'expected_gcash_amount' => $expectedGcash,
            'expected_bank_amount' => $expectedBank,
            'expected_total_amount' => $expectedTotal,
            'payment_breakdown' => [
                'amounts' => $paymentBreakdown,
                'counts' => $paymentCounts,
                'credit_amount' => round((float) ($paymentBreakdown['credit'] ?? 0), 2),
                'po_amount' => round((float) ($paymentBreakdown['po'] ?? 0), 2),
                'monthly_billing_amount' => round((float) ($paymentBreakdown['monthly_billing'] ?? 0), 2),
            ],
            'expense_breakdown' => [
                'store_cash' => round($storeCashExpenses, 2),
                'owner' => round($ownerExpenses, 2),
                'money_movements' => [
                    'cash_in' => $cashInMovements,
                    'cash_out' => $cashOutMovements,
                    'items' => $moneyMovements->map(fn (MoneyMovement $movement) => [
                        'id' => $movement->id,
                        'type' => $movement->type,
                        'label' => $movement->type_label,
                        'direction' => $movement->direction,
                        'amount' => round((float) $movement->amount, 2),
                        'reference_no' => $movement->reference_no,
                        'description' => $movement->description,
                        'recorded_by' => $movement->recorder?->name,
                    ])->values()->all(),
                ],
            ],
            'transaction_count' => $jobOrders->count(),
            'first_job_order_number' => $jobOrders->first()?->job_order_number,
            'last_job_order_number' => $jobOrders->last()?->job_order_number,
        ];
    }

    private function normalizedCashCount(array $cashCount): array
    {
        return collect(self::DENOMINATIONS)
            ->mapWithKeys(fn (string $label, string $value) => [$value => max(0, (int) ($cashCount[$value] ?? 0))])
            ->all();
    }

    private function cashCountTotal(array $cashCount): float
    {
        $total = 0.0;

        foreach ($cashCount as $value => $quantity) {
            $total += (float) $value * (int) $quantity;
        }

        return round($total, 2);
    }

    private function nextReadingNumber(int $branchId, string $businessDate): string
    {
        $branchCode = Branch::query()->whereKey($branchId)->value('code') ?: 'BR'.$branchId;
        $count = ZReading::query()
            ->whereDate('created_at', today())
            ->count() + 1;

        return 'ZR-'.$branchCode.'-'.Carbon::parse($businessDate)->format('Ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function signatories(int $branchId): array
    {
        $users = User::query()
            ->where('branch_id', $branchId)
            ->whereIn('role', ['branch_manager', 'cashier'])
            ->where('status', 'active')
            ->orderByRaw("CASE role WHEN 'branch_manager' THEN 0 WHEN 'cashier' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get(['name', 'role']);

        return [
            'branch_manager' => $users->where('role', 'branch_manager')->pluck('name')->values()->all(),
            'cashier' => $users->where('role', 'cashier')->pluck('name')->values()->all(),
        ];
    }

    private function authorizeReading(Request $request, ZReading $reading): void
    {
        if ($request->user()->canManageAllBranches()) {
            return;
        }

        abort_unless((int) $request->user()->branch_id === (int) $reading->branch_id, 403);
    }
}
