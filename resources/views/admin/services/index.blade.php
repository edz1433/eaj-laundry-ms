@extends('layouts.app')

@section('page_title', 'Laundry Services')

@section('content')
@php($activeBranch = $branches->firstWhere('id', $selectedBranchId))
<div x-data="{ createOpen: false, editOpen: null }" class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="services" class="h-3.5 w-3.5"></span>
                Pricing catalog
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Laundry Services</h1>
            <p class="text-sm text-muted">
                {{ $activeBranch ? 'Managing pricing for '.$activeBranch->name.'.' : 'Create a branch before adding services.' }}
            </p>
        </div>

        <button type="button" @click="createOpen = true" @disabled(! $activeBranch) class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white shadow-sm hover:opacity-90 disabled:opacity-50">
            <span data-lucide="plus" class="h-4 w-4"></span>
            Add Service
        </button>
    </div>

    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" action="{{ route('admin.services.index') }}" class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_11rem_10rem_9rem_auto]">
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" placeholder="Search services..." class="w-full bg-transparent text-sm outline-none">
            </div>

            @if($canChooseBranch)
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
            @endif

            <select name="pricing_type" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All types</option>
                @foreach(['kilo', 'load', 'piece', 'custom'] as $type)
                    <option value="{{ $type }}" @selected(request('pricing_type') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>

            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>

            <button type="submit" title="Filter" aria-label="Filter services" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4"></span>
            </button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr>
                        <th class="px-4 py-3">Service</th>
                        <th class="px-4 py-3">Branch</th>
                        <th class="px-4 py-3">Z Reading</th>
                        <th class="px-4 py-3">Pricing</th>
                        <th class="px-4 py-3">Price</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($services as $service)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $service->name }}</td>
                            <td class="px-4 py-3">{{ $service->branch?->name ?? 'N/A' }}</td>
                            <td class="px-4 py-3">{{ $serviceCategories[$service->report_category] ?? 'Other' }}</td>
                            <td class="px-4 py-3">{{ ucfirst($service->pricing_type) }}</td>
                            <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $service->price, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="{{ \App\Support\StatusBadge::classes($service->is_active ? 'active' : 'inactive') }}">
                                    {{ $service->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="editOpen = {{ $service->id }}" title="Edit" aria-label="Edit service" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="settings" class="h-4 w-4"></span>
                                </button>

                                <form method="POST" action="{{ route('admin.services.destroy', $service) }}" class="inline" x-data>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete" aria-label="Delete service" x-on:click.prevent="Swal.fire({ title: 'Delete service?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                        <span data-lucide="trash" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No services found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $services->links() }}</div>
    </div>

    <div x-cloak x-show="createOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div @click.outside="createOpen = false" class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="services" class="h-4 w-4 text-primary"></span>Add Service</h2>
                <button type="button" @click="createOpen = false" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
            </div>
            @include('admin.services.partials.form', ['action' => route('admin.services.store'), 'method' => 'POST', 'service' => new \App\Models\LaundryService(['branch_id' => $selectedBranchId, 'pricing_type' => 'kilo', 'is_active' => true, 'price' => 0])])
        </div>
    </div>

    @foreach($services as $service)
        <div x-cloak x-show="editOpen === {{ $service->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="editOpen = null" class="w-full max-w-3xl rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="settings" class="h-4 w-4 text-primary"></span>Edit Service</h2>
                    <button type="button" @click="editOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>
                @include('admin.services.partials.form', ['action' => route('admin.services.update', $service), 'method' => 'PUT', 'service' => $service])
            </div>
        </div>
    @endforeach
</div>
@endsection

