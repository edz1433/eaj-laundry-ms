<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if($method !== 'POST') @method($method) @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium">Name</label>
            <input name="name" value="{{ old('name', $service->name) }}" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Branch</label>
            @if(! (auth()->user()->isSuperAdmin() || auth()->user()->role === 'admin'))
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
                <input value="{{ auth()->user()->branch?->name }}" disabled class="h-9 w-full rounded-md border border-border bg-smoke px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            @else
                <select name="branch_id" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('branch_id', $service->branch_id) == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Pricing Type</label>
            <select name="pricing_type" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                @foreach(['kilo', 'load', 'piece', 'custom'] as $type)
                    <option value="{{ $type }}" @selected(old('pricing_type', $service->pricing_type) === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium">Price</label>
            <input type="number" step="0.01" min="0" name="price" value="{{ old('price', $service->price ?? 0) }}" required class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
        </div>

        <div class="md:col-span-2">
            <label class="inline-flex h-9 items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $service->is_active)) class="rounded border-border text-primary">
                Active service
            </label>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="flex justify-end">
        <button type="submit" class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Service</button>
    </div>
</form>
