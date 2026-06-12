<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Support\Activity;
use App\Support\SmsNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CycleController extends Controller
{
    private const ACTIVE_STATUSES = ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup'];

    private const CYCLE_TYPES = [
        'wash' => 'Washing',
        'dry' => 'Drying',
        'fold' => 'Folding',
        'iron' => 'Ironing',
    ];

    private const COMPLETION_STATUSES = [
        'ready_for_pickup' => 'Ready for Pickup',
        'completed' => 'Completed',
    ];

    private const RELEASE_ACTIONS = [
        'release_here' => 'Release Here',
        'return_to_dropoff' => 'Return to Drop-off',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $user->canManageAllBranches();
        $selectedBranchId = $canChooseBranch ? ($request->integer('branch_id') ?: null) : $user->branch_id;
        $selectedCustomerId = $request->integer('customer_id') ?: null;
        $customerBranchId = $selectedBranchId ?: (! $canChooseBranch ? $user->branch_id : null);
        [$dateFrom, $dateTo] = $this->dateRange($request);
        $statusLabels = [
            'pending' => 'Pending',
            'washing' => 'Washing',
            'drying' => 'Drying',
            'folding' => 'Folding / Ironing',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Completed',
        ];

        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $customers = Customer::query()
            ->with('branch:id,name')
            ->where('is_active', true)
            ->when($customerBranchId, fn ($query) => $query->where(fn ($query) => $query
                ->where('branch_id', $customerBranchId)
                ->orWhereHas('jobOrders', fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where(fn ($query) => $query
                        ->where(fn ($query) => $query
                            ->where('processing_branch_id', $customerBranchId)
                            ->whereNotNull('production_accepted_at'))
                        ->orWhere(fn ($query) => $query
                            ->whereNull('processing_branch_id')
                            ->where('branch_id', $customerBranchId))))))
            ->when(! $customerBranchId, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'phone']);

        if ($selectedCustomerId && ! $customers->contains('id', $selectedCustomerId)) {
            $selectedCustomerId = null;
        }

        $orders = JobOrder::with(['branch', 'processingBranch', 'currentBranch', 'releaseBranch', 'customer', 'cycles.user'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when($selectedBranchId, fn ($q) => $q->where(fn ($query) => $query
                ->where('branch_id', $selectedBranchId)
                ->orWhere(fn ($query) => $query
                    ->where('processing_branch_id', $selectedBranchId)
                    ->whereNotNull('production_accepted_at'))
                ->orWhere('current_branch_id', $selectedBranchId)
                ->orWhere('release_branch_id', $selectedBranchId)
                ->orWhere(fn ($query) => $query
                    ->whereNull('processing_branch_id')
                    ->where('branch_id', $selectedBranchId))))
            ->when(! $canChooseBranch, fn ($q) => $q->where(fn ($query) => $query
                ->where('branch_id', $user->branch_id)
                ->orWhere(fn ($query) => $query
                    ->where('processing_branch_id', $user->branch_id)
                    ->whereNotNull('production_accepted_at'))
                ->orWhere('current_branch_id', $user->branch_id)
                ->orWhere('release_branch_id', $user->branch_id)
                ->orWhere(fn ($query) => $query
                    ->whereNull('processing_branch_id')
                    ->where('branch_id', $user->branch_id))))
            ->when($selectedCustomerId, fn ($q) => $q->where('customer_id', $selectedCustomerId))
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when(
                in_array($request->status, self::ACTIVE_STATUSES, true),
                fn ($q) => $q->where('status', $request->status)
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;

                $q->where(fn ($query) => $query
                    ->where('job_order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")));
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $activeMachinesByBranch = CycleRecord::query()
            ->with('jobOrder:id,branch_id,processing_branch_id,job_order_number')
            ->where('cycle_type', 'wash')
            ->whereNotNull('machine_number')
            ->whereNull('ended_at')
            ->whereHas('jobOrder', function ($query) use ($request) {
                $user = $request->user();
                $canChooseBranch = $user->canManageAllBranches();
                $selectedBranchId = $canChooseBranch ? ($request->integer('branch_id') ?: null) : $user->branch_id;

                $query->whereNotIn('status', ['completed', 'cancelled'])
                    ->when($selectedBranchId, fn ($query) => $query->where(fn ($query) => $query
                        ->where(fn ($query) => $query
                            ->where('processing_branch_id', $selectedBranchId)
                            ->whereNotNull('production_accepted_at'))
                        ->orWhere(fn ($query) => $query
                            ->whereNull('processing_branch_id')
                            ->where('branch_id', $selectedBranchId))))
                    ->when(! $canChooseBranch, fn ($query) => $query->where(fn ($query) => $query
                        ->where(fn ($query) => $query
                            ->where('processing_branch_id', $user->branch_id)
                            ->whereNotNull('production_accepted_at'))
                        ->orWhere(fn ($query) => $query
                            ->whereNull('processing_branch_id')
                            ->where('branch_id', $user->branch_id))));
            })
            ->get()
            ->groupBy(fn (CycleRecord $cycle) => $cycle->jobOrder?->processing_branch_id ?: $cycle->jobOrder?->branch_id)
            ->map(fn ($cycles) => $cycles
                ->filter(fn (CycleRecord $cycle) => $cycle->jobOrder && $cycle->machine_number)
                ->mapWithKeys(fn (CycleRecord $cycle) => [(int) $cycle->machine_number => $cycle->jobOrder->job_order_number])
                ->all()
            )
            ->all();

        return view('admin.cycles.index', [
            'activeMachinesByBranch' => $activeMachinesByBranch,
            'branches' => $branches,
            'canChooseBranch' => $canChooseBranch,
            'customers' => $customers,
            'orders' => $orders,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedBranchId' => $selectedBranchId,
            'selectedCustomerId' => $selectedCustomerId,
            'statusFilters' => self::ACTIVE_STATUSES,
            'cycleTypes' => self::CYCLE_TYPES,
            'completionStatuses' => self::COMPLETION_STATUSES,
            'releaseActions' => self::RELEASE_ACTIONS,
            'statusLabels' => $statusLabels,
        ]);
    }

    public function updateStatus(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeOrder($request, $jobOrder);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::COMPLETION_STATUSES))],
        ]);

        $processingBranchId = $jobOrder->processing_branch_id ?: $jobOrder->branch_id;
        $jobOrder->update([
            'status' => $validated['status'],
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
            'production_completed_at' => $jobOrder->production_completed_at ?: now(),
            'current_branch_id' => $processingBranchId,
            'release_branch_id' => $processingBranchId,
            'released_at' => $validated['status'] === 'completed' ? now() : null,
        ]);

        Activity::log($request, 'job_order_status_updated', $jobOrder, [
            'job_order_number' => $jobOrder->job_order_number,
            'status' => $validated['status'],
        ], $jobOrder->branch_id);

        $jobOrder->loadMissing('customer');
        SmsNotifier::jobOrderStatus($jobOrder);

        return back()->with('success', 'Job order status updated.');
    }

    public function releaseAction(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeReleaseAction($request, $jobOrder);
        abort_unless($jobOrder->status === 'ready_for_pickup', 422);

        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::RELEASE_ACTIONS))],
        ]);

        if ($validated['action'] === 'return_to_dropoff') {
            $processingBranchId = $jobOrder->processing_branch_id ?: $jobOrder->branch_id;
            abort_unless((int) $processingBranchId === (int) $request->user()->branch_id || $request->user()->canManageAllBranches(), 403);
            abort_unless((int) $processingBranchId !== (int) $jobOrder->branch_id, 422);

            $jobOrder->update([
                'status' => 'ready_for_pickup',
                'current_branch_id' => $jobOrder->branch_id,
                'release_branch_id' => $jobOrder->branch_id,
                'production_completed_at' => $jobOrder->production_completed_at ?: now(),
                'returned_to_branch_at' => now(),
                'completed_at' => null,
                'released_at' => null,
            ]);

            Activity::log($request, 'job_order_returned_to_dropoff', $jobOrder, [
                'job_order_number' => $jobOrder->job_order_number,
                'dropoff_branch_id' => $jobOrder->branch_id,
            ], $jobOrder->branch_id);

            return back()->with('success', 'Laundry returned to drop-off branch for release.');
        }

        abort_unless((int) ($jobOrder->release_branch_id ?: $jobOrder->current_branch_id ?: $jobOrder->branch_id) === (int) $request->user()->branch_id || $request->user()->canManageAllBranches(), 403);

        $jobOrder->update([
            'status' => 'completed',
            'completed_at' => now(),
            'released_at' => now(),
            'release_branch_id' => $jobOrder->release_branch_id ?: ($jobOrder->current_branch_id ?: $request->user()->branch_id),
        ]);

        Activity::log($request, 'job_order_released', $jobOrder, [
            'job_order_number' => $jobOrder->job_order_number,
            'release_branch_id' => $jobOrder->release_branch_id,
        ], $jobOrder->release_branch_id);

        $jobOrder->loadMissing('customer');
        SmsNotifier::jobOrderStatus($jobOrder);

        return back()->with('success', 'Laundry released successfully.');
    }

    public function storeCycle(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeOrder($request, $jobOrder);

        $validated = $request->validate([
            'cycle_type' => ['required', Rule::in(array_keys(self::CYCLE_TYPES))],
            'machine_number' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $processingBranch = $jobOrder->processingBranch ?: $jobOrder->branch;
        $machineCount = (int) ($processingBranch?->machine_count ?? 0);
        if ($validated['cycle_type'] === 'wash' && $machineCount > 0 && empty($validated['machine_number'])) {
            return back()->withErrors(['machine_number' => 'Please choose a machine.'])->withInput();
        }

        if (! empty($validated['machine_number']) && ($machineCount <= 0 || (int) $validated['machine_number'] > $machineCount)) {
            return back()->withErrors(['machine_number' => 'Please choose a valid machine.'])->withInput();
        }

        if ($validated['cycle_type'] === 'wash' && ! empty($validated['machine_number']) && $this->machineInUse($jobOrder, (int) $validated['machine_number'])) {
            return back()->withErrors(['machine_number' => 'This machine is still in use. End the active cycle before assigning it again.'])->withInput();
        }

        $cycleNumber = $jobOrder->cycles()->where('cycle_type', $validated['cycle_type'])->max('cycle_number') + 1;

        $cycle = $jobOrder->cycles()->create([
            'user_id' => $request->user()->id,
            'cycle_type' => $validated['cycle_type'],
            'machine_number' => $validated['cycle_type'] === 'wash' ? ($validated['machine_number'] ?? null) : null,
            'cycle_number' => $cycleNumber,
            'started_at' => now(),
            'notes' => $validated['notes'] ?? null,
        ]);

        $status = match ($validated['cycle_type']) {
            'wash' => 'washing',
            'dry' => 'drying',
            'fold', 'iron' => 'folding',
        };

        $jobOrder->update([
            'status' => $status,
            'current_branch_id' => $jobOrder->processing_branch_id ?: $jobOrder->branch_id,
            'release_branch_id' => $jobOrder->processing_branch_id ?: $jobOrder->branch_id,
            'completed_at' => null,
            'released_at' => null,
        ]);

        Activity::log($request, 'cycle_started', $cycle, [
            'job_order_number' => $jobOrder->job_order_number,
            'cycle_type' => $validated['cycle_type'],
            'machine_number' => $validated['cycle_type'] === 'wash' ? ($validated['machine_number'] ?? null) : null,
            'cycle_number' => $cycleNumber,
        ], $jobOrder->branch_id);

        return back()->with('success', self::CYCLE_TYPES[$validated['cycle_type']].' cycle started.');
    }

    public function endCycle(Request $request, CycleRecord $cycle)
    {
        $this->authorizeOrder($request, $cycle->jobOrder);

        $cycle->update(['ended_at' => now()]);

        Activity::log($request, 'cycle_completed', $cycle, [
            'job_order_number' => $cycle->jobOrder?->job_order_number,
            'cycle_type' => $cycle->cycle_type,
            'cycle_number' => $cycle->cycle_number,
        ], $cycle->jobOrder?->branch_id);

        return back()->with('success', 'Cycle completed.');
    }

    public function destroyCycle(Request $request, CycleRecord $cycle)
    {
        $cycle->loadMissing('jobOrder');
        $this->authorizeOrder($request, $cycle->jobOrder);

        DB::transaction(function () use ($request, $cycle): void {
            $jobOrder = $cycle->jobOrder;
            $cycleType = $cycle->cycle_type;
            $cycleNumber = $cycle->cycle_number;

            Activity::log($request, 'cycle_removed', $cycle, [
                'job_order_number' => $jobOrder?->job_order_number,
                'cycle_type' => $cycleType,
                'cycle_number' => $cycleNumber,
            ], $jobOrder?->branch_id);

            $cycle->delete();
            $this->renumberCycles($jobOrder, $cycleType);
        });

        return back()->with('success', 'Cycle record removed.');
    }

    private function renumberCycles(JobOrder $jobOrder, string $cycleType): void
    {
        $jobOrder->cycles()
            ->where('cycle_type', $cycleType)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (CycleRecord $cycle, int $index): void {
                $nextNumber = $index + 1;

                if ((int) $cycle->cycle_number !== $nextNumber) {
                    $cycle->update(['cycle_number' => $nextNumber]);
                }
            });
    }

    private function machineInUse(JobOrder $jobOrder, int $machineNumber): bool
    {
        return CycleRecord::query()
            ->where('cycle_type', 'wash')
            ->where('machine_number', $machineNumber)
            ->whereNull('ended_at')
            ->whereHas('jobOrder', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('processing_branch_id', $jobOrder->processing_branch_id ?: $jobOrder->branch_id)
                    ->orWhere(fn ($query) => $query
                        ->whereNull('processing_branch_id')
                        ->where('branch_id', $jobOrder->processing_branch_id ?: $jobOrder->branch_id)))
                ->whereNotIn('status', ['completed', 'cancelled']))
            ->exists();
    }

    private function authorizeOrder(Request $request, JobOrder $jobOrder): void
    {
        if (in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            return;
        }

        abort_unless(
            (int) $jobOrder->branch_id === (int) $request->user()->branch_id
                || (
                    (int) ($jobOrder->processing_branch_id ?: $jobOrder->branch_id) === (int) $request->user()->branch_id
                    && $jobOrder->production_accepted_at
                ),
            403
        );
    }

    private function authorizeReleaseAction(Request $request, JobOrder $jobOrder): void
    {
        if ($request->user()->canManageAllBranches()) {
            return;
        }

        abort_unless(in_array((int) $request->user()->branch_id, [
            (int) $jobOrder->branch_id,
            (int) ($jobOrder->processing_branch_id ?: $jobOrder->branch_id),
            (int) ($jobOrder->current_branch_id ?: $jobOrder->branch_id),
            (int) ($jobOrder->release_branch_id ?: $jobOrder->branch_id),
        ], true), 403);
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
            $this->parseDate($request->date_to ?: $request->date),
        ];
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
