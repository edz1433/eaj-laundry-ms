@extends('layouts.app')

@section('page_title', 'Branches')

@section('content')
<div x-data="{ createOpen: false, editOpen: null }" class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="branches" class="h-3.5 w-3.5"></span>
                Multi-branch control
            </div>
            <h1 class="text-xl font-semibold tracking-normal">Branches</h1>
            <p class="text-sm text-muted">Manage branch profiles and operational access.</p>
        </div>

        @if($canCreateBranch)
            <button type="button" @click="createOpen = true" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white shadow-sm transition hover:opacity-90">
                <span data-lucide="plus" class="h-4 w-4"></span>
                Add Branch
            </button>
        @endif
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                    <tr>
                        <th class="px-4 py-3">Branch</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3">Users</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border dark:divide-gray-800">
                    @forelse($branches as $branch)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $branch->name }}</p>
                                <p class="text-xs text-muted">{{ $branch->code }} - {{ $branch->address ?: 'No address' }}</p>
                                <p class="text-xs text-muted">{{ $branch->latitude !== null && $branch->longitude !== null ? 'Geofence: '.($branch->attendance_radius_meters ?: 150).'m' : 'Geofence not set' }}</p>
                            </td>
                            <td class="px-4 py-3">{{ $branch->contact_number ?: 'N/A' }}</td>
                            <td class="px-4 py-3">{{ $branch->users_count }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-md px-2 py-1 text-xs font-medium {{ $branch->is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700' }}">
                                    {{ $branch->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" @click="editOpen = {{ $branch->id }}" title="Edit" aria-label="Edit branch" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="settings" class="h-4 w-4"></span>
                                </button>

                                @if(auth()->user()->isSuperAdmin())
                                    <form method="POST" action="{{ route('admin.branches.destroy', $branch) }}" class="inline" x-data>
                                        @csrf
                                        @method('DELETE')
                                        <button
                                            type="submit"
                                            title="Delete"
                                            aria-label="Delete branch"
                                            x-on:click.prevent="Swal.fire({ title: 'Delete branch?', text: 'Only empty branches can be deleted.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Delete' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50"
                                        >
                                            <span data-lucide="trash" class="h-4 w-4"></span>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">No branches found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-border px-4 py-3 dark:border-gray-800">
            {{ $branches->links() }}
        </div>
    </div>

    @if($canCreateBranch)
        <div x-cloak x-show="createOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="createOpen = false" class="w-full max-w-xl rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="branches" class="h-4 w-4 text-primary"></span>Add Branch</h2>
                    <button type="button" @click="createOpen = false" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                @include('admin.branches.partials.form', [
                    'action' => route('admin.branches.store'),
                    'method' => 'POST',
                    'branch' => new \App\Models\Branch(['is_active' => true]),
                ])
            </div>
        </div>
    @endif

    @foreach($branches as $branch)
        <div x-cloak x-show="editOpen === {{ $branch->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="editOpen = null" class="w-full max-w-xl rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="settings" class="h-4 w-4 text-primary"></span>Edit Branch</h2>
                    <button type="button" @click="editOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                @include('admin.branches.partials.form', [
                    'action' => route('admin.branches.update', $branch),
                    'method' => 'PUT',
                    'branch' => $branch,
                ])
            </div>
        </div>
    @endforeach
</div>
@endsection

