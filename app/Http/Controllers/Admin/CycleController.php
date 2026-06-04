<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CycleRecord;
use App\Models\JobOrder;
use App\Support\Activity;
use App\Support\SmsNotifier;
use Illuminate\Http\Request;
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

    public function index(Request $request)
    {
        $statusLabels = [
            'pending' => 'Pending',
            'washing' => 'Washing',
            'drying' => 'Drying',
            'folding' => 'Folding / Ironing',
            'ready_for_pickup' => 'Ready for Pickup',
            'completed' => 'Completed',
        ];

        $orders = JobOrder::with(['branch', 'customer', 'cycles.user'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when(! in_array($request->user()->role, ['super_admin', 'admin'], true), fn ($q) => $q->where('branch_id', $request->user()->branch_id))
            ->when(
                in_array($request->status, self::ACTIVE_STATUSES, true),
                fn ($q) => $q->where('status', $request->status)
            )
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $activeMachinesByBranch = CycleRecord::query()
            ->with('jobOrder:id,branch_id,job_order_number')
            ->where('cycle_type', 'wash')
            ->whereNotNull('machine_number')
            ->whereNull('ended_at')
            ->whereHas('jobOrder', function ($query) use ($request) {
                $query->whereNotIn('status', ['completed', 'cancelled'])
                    ->when(! in_array($request->user()->role, ['super_admin', 'admin'], true), fn ($query) => $query->where('branch_id', $request->user()->branch_id));
            })
            ->get()
            ->groupBy(fn (CycleRecord $cycle) => $cycle->jobOrder?->branch_id)
            ->map(fn ($cycles) => $cycles
                ->filter(fn (CycleRecord $cycle) => $cycle->jobOrder && $cycle->machine_number)
                ->mapWithKeys(fn (CycleRecord $cycle) => [(int) $cycle->machine_number => $cycle->jobOrder->job_order_number])
                ->all()
            )
            ->all();

        return view('admin.cycles.index', [
            'activeMachinesByBranch' => $activeMachinesByBranch,
            'orders' => $orders,
            'statusFilters' => self::ACTIVE_STATUSES,
            'cycleTypes' => self::CYCLE_TYPES,
            'completionStatuses' => self::COMPLETION_STATUSES,
            'statusLabels' => $statusLabels,
        ]);
    }

    public function updateStatus(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeOrder($request, $jobOrder);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::COMPLETION_STATUSES))],
        ]);

        $jobOrder->update([
            'status' => $validated['status'],
            'completed_at' => $validated['status'] === 'completed' ? now() : null,
        ]);

        Activity::log($request, 'job_order_status_updated', $jobOrder, [
            'job_order_number' => $jobOrder->job_order_number,
            'status' => $validated['status'],
        ], $jobOrder->branch_id);

        $jobOrder->loadMissing('customer');
        SmsNotifier::jobOrderStatus($jobOrder);

        return back()->with('success', 'Job order status updated.');
    }

    public function storeCycle(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeOrder($request, $jobOrder);

        $validated = $request->validate([
            'cycle_type' => ['required', Rule::in(array_keys(self::CYCLE_TYPES))],
            'machine_number' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $machineCount = (int) ($jobOrder->branch?->machine_count ?? 0);
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

        $jobOrder->update(['status' => $status]);

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
                ->where('branch_id', $jobOrder->branch_id)
                ->whereNotIn('status', ['completed', 'cancelled']))
            ->exists();
    }

    private function authorizeOrder(Request $request, JobOrder $jobOrder): void
    {
        if (in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            return;
        }

        abort_unless((int) $jobOrder->branch_id === (int) $request->user()->branch_id, 403);
    }
}
