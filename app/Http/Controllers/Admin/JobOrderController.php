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
use App\Support\Activity;
use App\Support\SmsNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $orders = JobOrder::with(['branch', 'customer'])
            ->when($user->role !== 'super_admin' && $user->role !== 'admin', fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where('job_order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.job-orders.index', compact('orders'));
    }

    public function show(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        $jobOrder->load(['branch', 'customer', 'creator', 'items.service', 'payments.receiver', 'cycles.user']);

        return view('admin.job-orders.show', [
            'order' => $jobOrder,
            'settings' => SystemSetting::current(),
        ]);
    }

    public function receipt(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeJobOrder($request, $jobOrder);

        $jobOrder->load(['branch.setting', 'customer', 'creator', 'items.service', 'payments']);

        return view('admin.job-orders.receipt', [
            'order' => $jobOrder,
            'branchSetting' => $jobOrder->branch?->setting,
            'settings' => SystemSetting::current(),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $branchId = in_array($user->role, ['super_admin', 'admin'], true)
            ? Branch::where('is_active', true)->value('id')
            : $user->branch_id;

        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $customers = Customer::where('is_active', true)
            ->when(! in_array($user->role, ['super_admin', 'admin'], true), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'phone', 'billing_type']);
        $services = LaundryService::where('is_active', true)
            ->when(! in_array($user->role, ['super_admin', 'admin'], true), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'pricing_type', 'price']);

        return view('admin.job-orders.create', compact('branches', 'customers', 'services', 'branchId'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.laundry_service_id' => ['required', 'exists:laundry_services,id'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', Rule::in(['cash', 'credit', 'po', 'monthly_billing'])],
            'load_count' => ['nullable', 'integer', 'min:0'],
            'drying_cycles' => ['nullable', 'integer', 'min:0'],
            'drying_extension_minutes' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'admin'], true)) {
            $validated['branch_id'] = $user->branch_id;
        }

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
                'customer_id' => $validated['customer_id'],
                'created_by' => $user->id,
                'job_order_number' => $this->nextJobOrderNumber((int) $validated['branch_id']),
                'status' => 'pending',
                'load_count' => $validated['load_count'] ?? 0,
                'drying_cycles' => $validated['drying_cycles'] ?? 0,
                'drying_extension_minutes' => $validated['drying_extension_minutes'] ?? 0,
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

            $this->deductInventoryForOrder($order, $validated['items'], $user->id);

            for ($cycle = 1; $cycle <= (int) ($validated['drying_cycles'] ?? 0); $cycle++) {
                $order->cycles()->create([
                    'user_id' => $user->id,
                    'cycle_type' => 'dry',
                    'cycle_number' => $cycle,
                    'notes' => $cycle === (int) ($validated['drying_cycles'] ?? 0) && ($validated['drying_extension_minutes'] ?? 0) > 0
                        ? 'Includes '.$validated['drying_extension_minutes'].' minute drying extension.'
                        : null,
                ]);
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
                $payment = Payment::create([
                    'branch_id' => $order->branch_id,
                    'job_order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'received_by' => $user->id,
                    'payment_number' => $this->nextPaymentNumber(),
                    'payment_type' => $validated['payment_type'] ?? 'cash',
                    'amount' => $paid,
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

        return $prefix.'-'.$branchCode.'-'.now()->format('Ymd').'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
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

    private function deductInventoryForOrder(JobOrder $order, array $items, int $userId): void
    {
        $serviceIds = collect($items)->pluck('laundry_service_id')->unique()->values();

        $services = LaundryService::query()
            ->with('inventoryUsages')
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
                $deductions[$usage->inventory_id] = ($deductions[$usage->inventory_id] ?? 0)
                    + ((float) $usage->quantity * (float) $item['quantity']);
            }
        }

        foreach ($deductions as $inventoryId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $inventory = Inventory::query()
                ->whereKey($inventoryId)
                ->where('branch_id', $order->branch_id)
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw ValidationException::withMessages([
                    'items' => 'A service inventory rule is linked to an invalid branch stock item.',
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
}
