<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Inventory;
use App\Models\JobOrder;
use App\Models\LaundryService;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Activity;
use App\Support\SmsNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOrderController extends Controller
{
    private const STATUSES = ['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'completed', 'cancelled'];

    public function index(Request $request)
    {
        $user = $request->user();
        [$dateFrom, $dateTo] = $this->dateRange($request);

        $orders = JobOrder::with(['branch.setting', 'processingBranch', 'currentBranch', 'releaseBranch', 'customer', 'items', 'payments.receiver', 'payments.collectedBranch'])
            ->when($user->role !== 'super_admin' && $user->role !== 'admin', fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when(in_array($request->status, self::STATUSES, true), fn ($q) => $q->where('status', $request->status))
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(fn ($query) => $query
                    ->where('job_order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$search}%")));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.job-orders.index', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'orders' => $orders,
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        $jobOrder->load(['branch.setting', 'processingBranch', 'currentBranch', 'releaseBranch', 'customer', 'creator', 'items.service', 'payments.receiver', 'payments.collectedBranch', 'cycles.user']);

        return view('admin.job-orders.show', [
            'order' => $jobOrder,
            'settings' => SystemSetting::current(),
        ]);
    }

    public function receipt(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrderReceipt($request, $jobOrder);

        $jobOrder->load(['branch.setting', 'processingBranch', 'currentBranch', 'releaseBranch', 'customer', 'creator', 'items.service', 'payments.collectedBranch']);

        return view('admin.job-orders.receipt', [
            'order' => $jobOrder,
            'branchSetting' => $jobOrder->branch?->setting,
            'settings' => SystemSetting::current(),
        ]);
    }

    public function acceptProductionScan(Request $request, JobOrder $jobOrder)
    {
        $productionBranch = $this->acceptProductionByBranch($request, $jobOrder, (int) $request->user()->branch_id);

        return redirect()
            ->route('admin.cycles.index', ['search' => $jobOrder->job_order_number])
            ->with('success', 'Laundry accepted by '.$productionBranch->name.' and added to production cycle monitoring.');
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = in_array($user->role, ['super_admin', 'admin'], true);
        $requestedBranchId = $request->integer('branch_id');
        $branchId = $canChooseBranch
            ? Branch::where('is_active', true)
                ->when($requestedBranchId, fn ($query) => $query->whereKey($requestedBranchId))
                ->value('id')
            : $user->branch_id;

        $branchId ??= Branch::where('is_active', true)->value('id');

        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $processingBranches = Branch::where('is_active', true)
            ->where('branch_type', 'full_service')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'branch_type', 'machine_count']);
        $customers = Customer::where('is_active', true)
            ->when(! in_array($user->role, ['super_admin', 'admin'], true), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'phone', 'billing_type']);
        $services = LaundryService::where('is_active', true)
            ->when(! in_array($user->role, ['super_admin', 'admin'], true), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'pricing_type', 'price']);
        $selectedCustomerId = '';
        if ($request->filled('customer_id')) {
            $selectedCustomerId = (string) Customer::where('is_active', true)
                ->whereKey($request->integer('customer_id'))
                ->where('branch_id', $branchId)
                ->value('id');
        }

        return view('admin.job-orders.create', compact('branches', 'processingBranches', 'customers', 'services', 'branchId', 'selectedCustomerId'));
    }

    public function edit(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        $jobOrder->load(['branch', 'processingBranch', 'customer', 'items.service', 'payments']);
        $user = $request->user();
        $branchId = $jobOrder->branch_id;
        $serviceIds = $jobOrder->items->pluck('laundry_service_id')->filter()->unique()->values();

        $customers = Customer::query()
            ->where('branch_id', $branchId)
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhere('id', $jobOrder->customer_id))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'phone', 'billing_type']);

        $services = LaundryService::query()
            ->where('branch_id', $branchId)
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhereIn('id', $serviceIds))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'pricing_type', 'price']);

        $branches = Branch::query()
            ->whereKey($branchId)
            ->get();

        $selectedCustomerId = (string) $jobOrder->customer_id;
        $initialItems = $jobOrder->items
            ->map(fn ($item) => [
                'id' => $item->laundry_service_id,
                'name' => $item->description,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->unit_price,
            ])
            ->values();

        $processingBranches = Branch::where('is_active', true)
            ->where('branch_type', 'full_service')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'branch_type', 'machine_count']);

        return view('admin.job-orders.edit', [
            'branches' => $branches,
            'processingBranches' => $processingBranches,
            'customers' => $customers,
            'services' => $services,
            'branchId' => $branchId,
            'selectedCustomerId' => $selectedCustomerId,
            'jobOrder' => $jobOrder,
            'initialItems' => $initialItems,
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'processing_branch_id' => ['nullable', 'exists:branches,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.laundry_service_id' => ['required', 'exists:laundry_services,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', Rule::in(['cash', 'gcash', 'bank', 'credit', 'po', 'monthly_billing'])],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
            'transaction_type' => ['nullable', Rule::in(['walk_in', 'delivery'])],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'admin'], true)) {
            $validated['branch_id'] = $user->branch_id;
        }

        $originBranch = Branch::query()->findOrFail($validated['branch_id']);
        $validated['processing_branch_id'] = $this->resolveProcessingBranchId($originBranch, $validated['processing_branch_id'] ?? null, $user);

        $customerBelongsToBranch = Customer::query()
            ->whereKey($validated['customer_id'])
            ->where('branch_id', $validated['branch_id'])
            ->exists();

        if (! $customerBelongsToBranch) {
            throw ValidationException::withMessages([
                'customer_id' => 'Please choose a customer from the selected branch.',
            ]);
        }

        $serviceIds = collect($validated['items'])->pluck('laundry_service_id')->unique()->values();
        $servicesBelongToBranch = LaundryService::query()
            ->whereIn('id', $serviceIds)
            ->where('branch_id', $validated['branch_id'])
            ->count() === $serviceIds->count();

        if (! $servicesBelongToBranch) {
            throw ValidationException::withMessages([
                'items' => 'All services must belong to the selected branch.',
            ]);
        }

        return DB::transaction(function () use ($request, $validated, $user) {
            $settings = SystemSetting::current();
            $subtotal = collect($validated['items'])->sum(fn ($item) => (float) $item['quantity'] * (float) $item['unit_price']);
            $discount = min((float) ($validated['discount'] ?? 0), $subtotal);
            $taxable = max($subtotal - $discount, 0);
            $tax = $settings->vat_enabled ? ($taxable * ((float) $settings->vat_rate / 100)) : 0;
            $total = $taxable + $tax;
            $paid = min((float) ($validated['paid_amount'] ?? 0), $total);

            $order = JobOrder::create([
                'branch_id' => $validated['branch_id'],
                'processing_branch_id' => $validated['processing_branch_id'],
                'current_branch_id' => $validated['branch_id'],
                'release_branch_id' => $validated['branch_id'],
                'customer_id' => $validated['customer_id'],
                'created_by' => $user->id,
                'job_order_number' => $this->nextJobOrderNumber((int) $validated['branch_id']),
                'status' => 'pending',
                'transaction_type' => $validated['transaction_type'] ?? 'walk_in',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid_amount' => $paid,
                'balance' => $total - $paid,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $order->items()->create([
                    'laundry_service_id' => $item['laundry_service_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => (float) $item['quantity'] * (float) $item['unit_price'],
                ]);
            }

            if (! $order->branch?->isPickupDropoff()) {
                $this->deductInventoryForOrder($order, $validated['items'], $user->id);
                $order->update(['inventory_deducted_at' => now()]);
            }

            $running = (float) CustomerLedger::where('customer_id', $order->customer_id)->latest()->value('running_balance');
            CustomerLedger::create([
                'branch_id' => $order->branch_id,
                'customer_id' => $order->customer_id,
                'job_order_id' => $order->id,
                'entry_type' => 'debit',
                'amount' => $total,
                'running_balance' => $running + $total,
                'description' => "Job order {$order->job_order_number}",
            ]);

            if ($paid > 0) {
                $collectedBranchId = $this->collectedBranchId($request, $order);
                $payment = Payment::create([
                    'branch_id' => $order->branch_id,
                    'collected_branch_id' => $collectedBranchId,
                    'job_order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'received_by' => $user->id,
                    'payment_number' => $this->nextPaymentNumber(),
                    'payment_type' => $validated['payment_type'] ?? 'cash',
                    'reference_no' => $validated['payment_reference_no'] ?? null,
                    'amount' => $paid,
                    'settlement_status' => $collectedBranchId === (int) $order->branch_id ? 'local' : 'pending',
                    'paid_at' => now(),
                ]);

                CustomerLedger::create([
                    'branch_id' => $order->branch_id,
                    'customer_id' => $order->customer_id,
                    'job_order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'entry_type' => 'credit',
                    'amount' => $paid,
                    'running_balance' => $running + $total - $paid,
                    'description' => "Payment {$payment->payment_number}",
                ]);
            }

            Activity::log($request, 'job_order_created', $order, [
                'job_order_number' => $order->job_order_number,
                'total' => $order->total,
            ], $order->branch_id);

            return redirect()->route('admin.job-orders.index')->with('success', 'Job order created successfully.');
        });
    }

    public function update(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        $validated = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'processing_branch_id' => ['nullable', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.laundry_service_id' => ['required', 'exists:laundry_services,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'transaction_type' => ['nullable', Rule::in(['walk_in', 'delivery'])],
            'notes' => ['nullable', 'string'],
        ]);

        $customerBelongsToBranch = Customer::query()
            ->whereKey($validated['customer_id'])
            ->where('branch_id', $jobOrder->branch_id)
            ->exists();

        if (! $customerBelongsToBranch) {
            throw ValidationException::withMessages([
                'customer_id' => 'Please choose a customer from this job order branch.',
            ]);
        }

        $serviceIds = collect($validated['items'])->pluck('laundry_service_id')->unique()->values();
        $servicesBelongToBranch = LaundryService::query()
            ->whereIn('id', $serviceIds)
            ->where('branch_id', $jobOrder->branch_id)
            ->count() === $serviceIds->count();

        if (! $servicesBelongToBranch) {
            throw ValidationException::withMessages([
                'items' => 'All services must belong to this job order branch.',
            ]);
        }

        return DB::transaction(function () use ($request, $validated, $jobOrder) {
            $previousProcessingBranchId = (int) ($jobOrder->processing_branch_id ?: $jobOrder->branch_id);
            $validated['processing_branch_id'] = $this->resolveProcessingBranchId($jobOrder->branch, $validated['processing_branch_id'] ?? null, $request->user());
            $settings = SystemSetting::current();
            $subtotal = collect($validated['items'])->sum(fn ($item) => (float) $item['quantity'] * (float) $item['unit_price']);
            $discount = min((float) ($validated['discount'] ?? 0), $subtotal);
            $taxable = max($subtotal - $discount, 0);
            $tax = $settings->vat_enabled ? ($taxable * ((float) $settings->vat_rate / 100)) : 0;
            $total = $taxable + $tax;
            $paid = (float) $jobOrder->payments()->sum('amount');

            $jobOrder->items()->delete();
            foreach ($validated['items'] as $item) {
                $jobOrder->items()->create([
                    'laundry_service_id' => $item['laundry_service_id'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => (float) $item['quantity'] * (float) $item['unit_price'],
                ]);
            }

            $orderUpdates = [
                'customer_id' => $validated['customer_id'],
                'processing_branch_id' => $validated['processing_branch_id'],
                'status' => $validated['status'],
                'transaction_type' => $validated['transaction_type'] ?? 'walk_in',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid_amount' => $paid,
                'balance' => max($total - $paid, 0),
                'notes' => $validated['notes'] ?? null,
                'completed_at' => $validated['status'] === 'completed' ? ($jobOrder->completed_at ?: now()) : null,
            ];

            if ((int) $validated['processing_branch_id'] !== $previousProcessingBranchId && $jobOrder->branch?->isPickupDropoff()) {
                $orderUpdates += [
                    'current_branch_id' => $jobOrder->branch_id,
                    'release_branch_id' => $jobOrder->branch_id,
                    'production_accepted_at' => null,
                    'inventory_deducted_at' => null,
                    'production_completed_at' => null,
                    'returned_to_branch_at' => null,
                    'released_at' => null,
                ];
            }

            $jobOrder->update($orderUpdates);

            Payment::query()
                ->where('job_order_id', $jobOrder->id)
                ->update([
                    'customer_id' => $jobOrder->customer_id,
                    'branch_id' => $jobOrder->branch_id,
                ]);

            CustomerLedger::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('entry_type', 'debit')
                ->update([
                    'branch_id' => $jobOrder->branch_id,
                    'customer_id' => $jobOrder->customer_id,
                    'amount' => $total,
                    'description' => "Edited job order {$jobOrder->job_order_number}",
                ]);

            CustomerLedger::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('entry_type', 'credit')
                ->update([
                    'branch_id' => $jobOrder->branch_id,
                    'customer_id' => $jobOrder->customer_id,
                ]);

            Activity::log($request, 'job_order_updated', $jobOrder, [
                'job_order_number' => $jobOrder->job_order_number,
                'status' => $jobOrder->status,
                'total' => $jobOrder->total,
            ], $jobOrder->branch_id);

            return redirect()
                ->route('admin.job-orders.show', $jobOrder)
                ->with('success', 'Job order updated successfully.');
        });
    }

    public function updateStatus(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        abort_if(in_array($jobOrder->status, ['completed', 'cancelled'], true), 422, 'Completed or cancelled job orders cannot be changed.');

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'washing', 'drying', 'folding', 'ready_for_pickup', 'completed'])],
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

        return back()->with('success', 'Job order status updated successfully.');
    }

    public function cancel(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        abort_if(in_array($jobOrder->status, ['completed', 'cancelled'], true), 422, 'Completed or cancelled job orders cannot be cancelled.');

        $jobOrder->update([
            'status' => 'cancelled',
            'completed_at' => null,
        ]);

        Activity::log($request, 'job_order_cancelled', $jobOrder, [
            'job_order_number' => $jobOrder->job_order_number,
        ], $jobOrder->branch_id);

        return back()->with('success', 'Job order cancelled successfully.');
    }

    private function nextJobOrderNumber(int $branchId): string
    {
        $globalPrefix = SystemSetting::current()->job_order_prefix ?: 'JO';
        $branchPrefix = BranchSetting::query()
            ->where('branch_id', $branchId)
            ->value('job_order_prefix');
        $branchCode = Branch::query()
            ->whereKey($branchId)
            ->value('code') ?: 'BR'.$branchId;

        $prefix = $branchPrefix ?: $globalPrefix;
        $count = JobOrder::query()
            ->where('branch_id', $branchId)
            ->whereDate('created_at', today())
            ->count() + 1;

        $prefixParts = [trim((string) $prefix)];
        if (strcasecmp(trim((string) $prefix), trim((string) $branchCode)) !== 0) {
            $prefixParts[] = trim((string) $branchCode);
        }

        $prefixText = collect($prefixParts)
            ->filter()
            ->implode('-');

        return $prefixText.'-'.now()->format('Ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    private function nextPaymentNumber(): string
    {
        return 'PAY-'.now()->format('Ymd').'-'.str_pad((string) (Payment::whereDate('created_at', today())->count() + 1), 4, '0', STR_PAD_LEFT);
    }

    private function authorizeJobOrder(Request $request, JobOrder $jobOrder): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless((int) $request->user()->branch_id === (int) $jobOrder->branch_id, 403);
    }

    private function authorizeJobOrderReceipt(Request $request, JobOrder $jobOrder): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless(in_array((int) $request->user()->branch_id, [
            (int) $jobOrder->branch_id,
            (int) ($jobOrder->processing_branch_id ?: $jobOrder->branch_id),
            (int) ($jobOrder->current_branch_id ?: $jobOrder->branch_id),
            (int) ($jobOrder->release_branch_id ?: $jobOrder->branch_id),
        ], true), 403);
    }

    public function acceptProductionByBranch(Request $request, JobOrder $jobOrder, int $branchId): Branch
    {
        $productionBranch = Branch::query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->first();

        abort_unless($productionBranch && $productionBranch->isFullService(), 403);
        abort_if(in_array($jobOrder->status, ['completed', 'cancelled'], true), 422, 'Completed or cancelled job orders cannot be accepted for production.');

        $jobOrder->loadMissing('branch');
        abort_unless($jobOrder->branch?->isPickupDropoff(), 422, 'Only pickup/drop-off orders can be accepted by production scan.');

        $assignedProductionId = $jobOrder->processing_branch_id ?: null;
        abort_unless((int) $assignedProductionId === (int) $productionBranch->id, 403, 'This laundry is assigned to another production branch.');

        $hasCycles = $jobOrder->cycles()->exists();
        abort_if(
            $hasCycles && (int) ($jobOrder->processing_branch_id ?: $jobOrder->branch_id) !== (int) $productionBranch->id,
            422,
            'This job order already has production cycles in another branch.'
        );

        $jobOrder->update([
            'current_branch_id' => $productionBranch->id,
            'release_branch_id' => $productionBranch->id,
            'production_accepted_at' => $jobOrder->production_accepted_at ?: now(),
            'returned_to_branch_at' => null,
        ]);

        if (! $jobOrder->inventory_deducted_at) {
            $jobOrder->loadMissing('items');
            $items = $jobOrder->items
                ->map(fn ($item) => [
                    'laundry_service_id' => $item->laundry_service_id,
                    'quantity' => (float) $item->quantity,
                ])
                ->all();

            $this->deductInventoryForOrder($jobOrder, $items, $request->user()?->id);
            $jobOrder->update(['inventory_deducted_at' => now()]);
        }

        Activity::log($request, 'job_order_production_scan_accepted', $jobOrder, [
            'job_order_number' => $jobOrder->job_order_number,
            'dropoff_branch_id' => $jobOrder->branch_id,
            'processing_branch_id' => $productionBranch->id,
        ], $jobOrder->branch_id);

        return $productionBranch;
    }

    private function collectedBranchId(Request $request, JobOrder $order): int
    {
        return (int) ($request->user()->branch_id ?: $order->branch_id);
    }

    private function resolveProcessingBranchId(Branch $originBranch, ?int $processingBranchId, User $user): int
    {
        if (! $user->canManageAllBranches() && $originBranch->isFullService()) {
            return (int) $originBranch->id;
        }

        if ($originBranch->isPickupDropoff()) {
            $processingBranchId = $processingBranchId ?: (int) Branch::query()
                ->where('is_active', true)
                ->where('branch_type', 'full_service')
                ->value('id');
        } else {
            $processingBranchId = $processingBranchId ?: (int) $originBranch->id;
        }

        $processingBranch = Branch::query()
            ->whereKey($processingBranchId)
            ->where('is_active', true)
            ->where('branch_type', 'full_service')
            ->first();

        if (! $processingBranch) {
            throw ValidationException::withMessages([
                'processing_branch_id' => 'Please choose an active full-service branch for production.',
            ]);
        }

        return (int) $processingBranch->id;
    }

    private function deductInventoryForOrder(JobOrder $order, array $items, ?int $userId): void
    {
        $serviceIds = collect($items)->pluck('laundry_service_id')->unique()->values();

        $services = LaundryService::query()
            ->with('inventoryUsages.inventory')
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        $deductions = [];

        foreach ($items as $item) {
            $service = $services->get((int) $item['laundry_service_id']);

            if (! $service) {
                continue;
            }

            foreach ($service->inventoryUsages as $usage) {
                $inventory = $this->productionInventoryForUsage($order, $usage);

                $deductions[$inventory->id] = ($deductions[$inventory->id] ?? 0)
                    + ((float) $usage->quantity * (float) $item['quantity']);
            }
        }

        foreach ($deductions as $inventoryId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $inventory = Inventory::query()
                ->whereKey($inventoryId)
                ->where('branch_id', $order->processing_branch_id ?: $order->branch_id)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages([
                    'items' => 'A service inventory rule is linked to an invalid production stock item.',
                ]);
            }

            if ((float) $inventory->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'items' => "{$inventory->name} is insufficient. Available: {$inventory->quantity} {$inventory->unit}. Needed: ".number_format($quantity, 4).' '.$inventory->unit.'.',
                ]);
            }

            $inventory->movements()->create([
                'user_id' => $userId,
                'movement_type' => 'out',
                'quantity' => $quantity,
                'remarks' => "Auto deducted for {$order->job_order_number}",
            ]);

            $inventory->update([
                'quantity' => (float) $inventory->quantity - $quantity,
            ]);
        }
    }

    private function productionInventoryForUsage(JobOrder $order, $usage): Inventory
    {
        $sourceInventory = $usage->inventory;
        $productionBranchId = (int) ($order->processing_branch_id ?: $order->branch_id);

        if ($sourceInventory && (int) $sourceInventory->branch_id === $productionBranchId) {
            return $sourceInventory;
        }

        if (! $order->branch?->isPickupDropoff()) {
            throw ValidationException::withMessages([
                'items' => 'A service inventory rule is linked to an invalid branch stock item.',
            ]);
        }

        $productionInventory = Inventory::query()
            ->where('branch_id', $productionBranchId)
            ->where('is_active', true)
            ->when($sourceInventory?->sku, fn ($query) => $query->where('sku', $sourceInventory->sku))
            ->when(! $sourceInventory?->sku, fn ($query) => $query
                ->where('name', $sourceInventory?->name)
                ->where('unit', $sourceInventory?->unit))
            ->first();

        if (! $productionInventory) {
            throw ValidationException::withMessages([
                'items' => 'Production branch stock is not configured for '.$sourceInventory?->name.'. Add matching inventory stock in the assigned production branch.',
            ]);
        }

        return $productionInventory;
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
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
