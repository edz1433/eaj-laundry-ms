@extends('layouts.app')

@section('page_title', 'Cycle Monitoring')

@section('content')
@php($dateRangeValue = request('date_range') ?: ($dateFrom && $dateTo ? $dateFrom.' to '.$dateTo : ''))
<div
    x-data="{
        dateRange: @js($dateRangeValue),
        init() {
            this.$nextTick(() => {
                if (!window.flatpickr) return;
                window.flatpickr(this.$refs.dateRange, {
                    mode: 'range',
                    dateFormat: 'Y-m-d',
                    defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                    onClose: (dates, value) => this.dateRange = value,
                });
            });
        },
    }"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="cycles" class="h-3.5 w-3.5"></span>
                Operations board
            </div>
            <h1 class="text-xl font-semibold">Cycle Monitoring</h1>
            <p class="text-sm text-muted">Start active laundry cycles, then mark orders ready or completed.</p>
        </div>

        <form method="GET" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(12rem,1fr)_12rem_14rem_16rem_12rem_auto]">
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                <input name="search" value="{{ request('search') }}" type="search" placeholder="Search job or customer..." class="w-full bg-transparent text-sm outline-none">
            </div>
            @if($canChooseBranch)
                <select name="branch_id" onchange="this.form.customer_id.value = ''; this.form.submit()" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <select name="customer_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All customers</option>
                @if($canChooseBranch && ! $selectedBranchId)
                    <option value="" disabled>Select a branch to list customers</option>
                @elseif($customers->isEmpty())
                    <option value="" disabled>No customers in selected branch</option>
                @else
                    <optgroup label="{{ $canChooseBranch ? 'Customers in selected branch' : 'Customers in your branch' }}">
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int) $selectedCustomerId === (int) $customer->id)>
                                {{ $customer->name }}
                            </option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" placeholder="Date range" autocomplete="off" class="w-full bg-transparent text-sm outline-none">
            </div>
            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All active</option>
                @foreach($statusFilters as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabels[$status] ?? str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
            <button type="submit" title="Filter" aria-label="Filter cycles" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                <span data-lucide="search" class="h-4 w-4"></span>
            </button>
        </form>
    </div>

    <div class="grid gap-3 xl:grid-cols-2">
        @forelse($orders as $order)
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $order->job_order_number }}</p>
                        <p class="truncate text-sm text-muted">{{ $order->customer?->name }} · {{ $order->branch?->name }}</p>
                    </div>
                    <span class="shrink-0 {{ \App\Support\StatusBadge::classes($order->status) }}">
                        {{ $statusLabels[$order->status] ?? str_replace('_', ' ', ucfirst($order->status)) }}
                    </span>
                </div>

                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach($cycleTypes as $type => $label)
                        @php($activeMachines = $activeMachinesByBranch[$order->branch_id] ?? [])
                        <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                            @csrf
                            <input type="hidden" name="cycle_type" value="{{ $type }}">
                            <div class="flex overflow-hidden rounded-md border border-border dark:border-gray-800">
                                @if($type === 'wash' && (int) ($order->branch?->machine_count ?? 0) > 0)
                                    <select name="machine_number" aria-label="Machine" class="h-8 w-20 border-r border-border bg-white px-2 text-xs dark:border-gray-800 dark:bg-gray-950">
                                        <option value="">Machine</option>
                                        @for($machine = 1; $machine <= (int) $order->branch->machine_count; $machine++)
                                            @php($usingOrder = $activeMachines[$machine] ?? null)
                                            <option value="{{ $machine }}" @disabled($usingOrder)>#{{ $machine }}{{ $usingOrder ? ' - In use' : '' }}</option>
                                        @endfor
                                    </select>
                                @endif
                                <button title="Start {{ $label }}" class="inline-flex h-8 items-center gap-1.5 px-2 text-xs font-medium hover:bg-smoke dark:hover:bg-gray-950">
                                    <span data-lucide="plus" class="h-3.5 w-3.5"></span>
                                    {{ $label }}
                                </button>
                            </div>
                        </form>
                    @endforeach
                </div>

                <div class="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-border bg-smoke p-2 dark:border-gray-800 dark:bg-gray-950">
                    <span class="text-xs font-medium text-muted">Finish order:</span>
                    @foreach($completionStatuses as $status => $label)
                        <form method="POST" action="{{ route('admin.cycles.status', $order) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button class="h-8 rounded-md px-2 text-xs font-medium {{ $order->status === $status ? 'bg-primary text-white' : 'border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950' }}">
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>

                <div class="space-y-2 border-t border-border pt-3 dark:border-gray-800">
                    @if((int) ($order->branch?->machine_count ?? 0) > 0)
                        <div class="flex flex-wrap gap-1.5">
                            @for($machine = 1; $machine <= (int) $order->branch->machine_count; $machine++)
                                @php($usingOrder = ($activeMachinesByBranch[$order->branch_id] ?? [])[$machine] ?? null)
                                <span class="{{ \App\Support\StatusBadge::classes($usingOrder ? 'washing' : 'ok') }}" title="{{ $usingOrder ? 'Used by '.$usingOrder : 'Available' }}">
                                    Machine #{{ $machine }} {{ $usingOrder ? 'Busy' : 'Open' }}
                                </span>
                            @endfor
                        </div>
                    @endif

                    @forelse($order->cycles->sortByDesc('created_at') as $cycle)
                        <div class="flex items-center justify-between gap-2 rounded-md bg-smoke px-3 py-2 text-sm dark:bg-gray-950">
                            <div>
                                <p class="font-medium">
                                    {{ $cycleTypes[$cycle->cycle_type] ?? ucfirst($cycle->cycle_type) }} #{{ $cycle->cycle_number }}
                                    @if($cycle->machine_number)
                                        <span class="text-xs font-normal text-muted">Machine #{{ $cycle->machine_number }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-muted">
                                    {{ $cycle->started_at?->format('M d, h:i A') ?? 'Not started' }}
                                    @if($cycle->ended_at) - {{ $cycle->ended_at->format('h:i A') }} @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-1">
                                @if(! $cycle->ended_at)
                                    <form method="POST" action="{{ route('admin.cycles.end', $cycle) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button title="End cycle" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-white dark:border-gray-800 dark:hover:bg-gray-900">
                                            <span data-lucide="activity" class="h-4 w-4"></span>
                                        </button>
                                    </form>
                                @else
                                    <span class="px-2 text-xs text-muted">Done</span>
                                @endif

                                <form method="POST" action="{{ route('admin.cycles.destroy', $cycle) }}" x-data>
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        title="Remove cycle"
                                        aria-label="Remove cycle"
                                        x-on:click.prevent="Swal.fire({ title: 'Remove cycle?', text: 'Use this to clean duplicate or accidental cycle taps.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Remove' }).then((result) => { if (result.isConfirmed) $el.closest('form').submit(); })"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900/70 dark:hover:bg-red-950/30"
                                    >
                                        <span data-lucide="trash" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-md border border-dashed border-border py-6 text-center text-sm text-muted dark:border-gray-800">No cycles yet.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-white p-10 text-center text-sm text-muted dark:border-gray-800 dark:bg-gray-900">
                No active job orders to monitor.
            </div>
        @endforelse
    </div>

    <div>{{ $orders->links() }}</div>
</div>
@endsection
