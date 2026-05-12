<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LaundryService;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class LaundryServiceController extends Controller
{
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

        $services = LaundryService::with('branch')
            ->where('branch_id', $selectedBranchId)
            ->when($request->filled('pricing_type'), fn ($query) => $query->where('pricing_type', $request->pricing_type))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->status === 'active'))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.services.index', compact('services', 'branches', 'selectedBranchId', 'canChooseBranch'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active', true);

        $service = LaundryService::create($validated);

        Activity::log($request, 'service_created', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index')->with('success', 'Service created successfully.');
    }

    public function update(Request $request, LaundryService $service)
    {
        $this->authorizeService($service);

        $validated = $request->validate($this->rules());
        $validated = $this->normalizeBranch($validated);
        $validated['is_active'] = $request->boolean('is_active');

        $service->update($validated);

        Activity::log($request, 'service_updated', $service, [
            'name' => $service->name,
            'price' => $service->price,
        ], $service->branch_id);

        return redirect()->route('admin.services.index')->with('success', 'Service updated successfully.');
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
            'pricing_type' => ['required', Rule::in(['kilo', 'load', 'piece', 'custom'])],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
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
