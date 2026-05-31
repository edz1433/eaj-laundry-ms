@extends('layouts.app')

@section('page_title', 'Job Orders')

@section('content')
<div x-data="{ statusOpen: null, cancelOpen: null }" class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="jobOrders" class="h-3.5 w-3.5"></span>
                {{ in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'Laundry operations' }}
            </div>
            <h1 class="text-xl font-semibold">Job Orders</h1>
            <p class="text-sm text-muted">Create and monitor laundry transactions.</p>
        </div>
        <a href="{{ route('admin.job-orders.create') }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
            <span data-lucide="plus" class="h-4 w-4"></span>
            New POS
        </a>
    </div>

    <div class="rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <form method="GET" class="grid grid-cols-1 gap-2 md:grid-cols-[1fr_12rem_auto]">
            <input name="search" value="{{ request('search') }}" placeholder="Search JO or customer..." class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All status</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800"><span data-lucide="search" class="h-4 w-4"></span></button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                <tr>
                    <th class="px-4 py-3">JO #</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Branch</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Balance</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border dark:divide-gray-800">
                @forelse($orders as $order)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $order->job_order_number }}</td>
                        <td class="px-4 py-3">{{ $order->customer?->name }}</td>
                        <td class="px-4 py-3">{{ $order->branch?->name }}</td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->total, 2) }}</td>
                        <td class="px-4 py-3">{{ $appSettings?->currency ?? 'PHP' }} {{ number_format((float) $order->balance, 2) }}</td>
                        <td class="px-4 py-3"><span class="{{ \App\Support\StatusBadge::classes($order->status) }}">{{ \App\Support\StatusBadge::label($order->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.job-orders.show', $order) }}" title="View" aria-label="View job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="eye" class="h-4 w-4"></span>
                            </a>
                            <a href="{{ route('admin.job-orders.receipt', $order) }}" target="_blank" title="Receipt" aria-label="Print receipt" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                <span data-lucide="receipt" class="h-4 w-4"></span>
                            </a>
                            @unless(in_array($order->status, ['completed', 'cancelled'], true))
                                <button type="button" @click="statusOpen = {{ $order->id }}" title="Update status" aria-label="Update status" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">
                                    <span data-lucide="activity" class="h-4 w-4"></span>
                                </button>
                                <button type="button" @click="cancelOpen = {{ $order->id }}" title="Cancel" aria-label="Cancel job order" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50">
                                    <span data-lucide="x" class="h-4 w-4"></span>
                                </button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No job orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t border-border px-4 py-3 dark:border-gray-800">{{ $orders->links() }}</div>
    </div>

    @foreach($orders as $order)
        <div x-cloak x-show="statusOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="statusOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="activity" class="h-4 w-4 text-primary"></span>Update Status</h2>
                        <p class="text-sm text-muted">{{ $order->job_order_number }}</p>
                    </div>
                    <button type="button" @click="statusOpen = null" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <form method="POST" action="{{ route('admin.job-orders.status', $order) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-700 dark:bg-gray-950">
                        @foreach(array_filter($statuses, fn ($status) => $status !== 'cancelled') as $status)
                            <option value="{{ $status }}" @selected($order->status === $status)>{{ \App\Support\StatusBadge::label($status) }}</option>
                        @endforeach
                    </select>
                    <div class="flex justify-end">
                        <button class="h-9 rounded-md bg-primary px-4 text-sm font-medium text-white hover:opacity-90">Save Status</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-cloak x-show="cancelOpen === {{ $order->id }}" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="cancelOpen = null" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">Cancel job order?</h2>
                    <p class="mt-1 text-sm text-muted">{{ $order->job_order_number }} will be marked as cancelled.</p>
                </div>
                <form method="POST" action="{{ route('admin.job-orders.cancel', $order) }}" class="flex justify-end gap-2">
                    @csrf
                    @method('PATCH')
                    <button type="button" @click="cancelOpen = null" class="h-9 rounded-md border border-border px-4 text-sm font-medium hover:bg-smoke dark:border-gray-700 dark:hover:bg-gray-800">Keep</button>
                    <button class="h-9 rounded-md bg-red-600 px-4 text-sm font-medium text-white hover:bg-red-700">Cancel Order</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endsection
