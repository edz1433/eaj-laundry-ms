<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\LaundryService;
use App\Support\Activity;
use App\Support\ServiceCategories;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class LaundryServiceController extends Controller
{
    private const PRICING_TYPES = ['kilo', 'load', 'piece', 'custom'];
    private const STATUS_FILTERS = ['active', 'inactive'];

    public function index(Request $request)
    {
        $user = $request->user();
        $canChooseBranch = $this->canChooseBranch($user);

        $branches = Branch::where('is_active', true)
            ->when(! $canChooseBranch, fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')
            ->get();

        $selectedBranchId = $canChooseBranch
            ? ($request->integer('branch_id') ?: $branches->first()?->id)
            : $user->branch_id;

        $services = LaundryService::with(['branch', 'inventoryUsages'])
            ->where('branch_id', $selectedBranchId)
            ->when(in_array($request->pricing_type, self::PRICING_TYPES, true), fn ($query) => $query->where('pricing_type', $request->pricing_type))
            ->when(in_array($request->status, self::STATUS_FILTERS, true), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(10)
            ->withQueryString();
        $inventoryItems = Inventory::query()
            ->where('branch_id', $selectedBranchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'quantity']);
        $serviceCategories = ServiceCategories::LABELS;

        return view('admin.services.index', compact('services', 'branches', 'selectedBranchId', 'canChooseBranch', 'inventoryItems', 'serviceCategories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active', true);

        $service = DB::transaction(function () use ($validated) {
            $service = LaundryService::create(collect($validated)->except('inventory_usages')->all());
            $this->syncInventoryUsages($service, $validated['inventory_usages'] ?? []);

            return $service;
        });

        Activity::log($request, 'service_created', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $service->branch_id])->with('success', 'Service created successfully.');
    }

    public function update(Request $request, LaundryService $service)
    {
        $this->authorizeService($service);

        $validated = $request->validate($this->rules());
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active');

        DB::transaction(function () use ($service, $validated) {
            $service->update(collect($validated)->except('inventory_usages')->all());
            $this->syncInventoryUsages($service, $validated['inventory_usages'] ?? []);
        });

        Activity::log($request, 'service_updated', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index', ['branch_id' => $service->branch_id])->with('success', 'Service updated successfully.');
    }

    public function destroy(LaundryService $service)
    {
        $this->authorizeService($service);
        $service->delete();

        Activity::log(request(), 'service_deleted', $service, [
            'name' => $service->name,
        ], $service->branch_id);

        return redirect()->route('admin.services.index')->with('success', 'Service deleted successfully.');
    }

    private function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:255'],
            'report_category' => ['required', Rule::in(ServiceCategories::keys())],
            'pricing_type' => ['required', Rule::in(['kilo', 'load', 'piece', 'custom'])],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'inventory_usages' => ['nullable', 'array'],
            'inventory_usages.*' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
        ];
    }

    private function syncInventoryUsages(LaundryService $service, array $usages): void
    {
        $quantities = collect($usages)
            ->filter(fn ($quantity) => is_numeric($quantity) && (float) $quantity > 0)
            ->mapWithKeys(fn ($quantity, $inventoryId) => [(int) $inventoryId => (float) $quantity]);

        $validInventoryIds = Inventory::query()
            ->where('branch_id', $service->branch_id)
            ->whereIn('id', $quantities->keys())
            ->pluck('id');

        if ($validInventoryIds->count() !== $quantities->count()) {
            throw ValidationException::withMessages([
                'inventory_usages' => 'Every inventory usage must belong to the selected service branch.',
            ]);
        }

        $service->inventoryUsages()->whereNotIn('inventory_id', $validInventoryIds)->delete();

        foreach ($validInventoryIds as $inventoryId) {
            $service->inventoryUsages()->updateOrCreate(
                ['inventory_id' => $inventoryId],
                ['quantity' => $quantities->get($inventoryId)]
            );
        }
    }

    private function normalizeBranch(array $validated): array
    {
        $user = auth()->user();

        if (! $this->canChooseBranch($user)) {
            if (! $user->branch_id) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Your account is not assigned to a branch yet.',
                ]);
            }

            $validated['branch_id'] = $user->branch_id;
        }

        return $validated;
    }

    private function authorizeService(LaundryService $service): void
    {
        $user = auth()->user();

        if ($this->canChooseBranch($user)) {
            return;
        }

        abort_unless((int) $service->branch_id === (int) $user->branch_id, 403);
    }

    private function canChooseBranch($user): bool
    {
        return $user->isSuperAdmin() || $user->role === 'admin';
    }
}
