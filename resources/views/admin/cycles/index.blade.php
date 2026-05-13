@extends('layouts.app')

@section('page_title', 'Cycle Monitoring')

@section('content')
<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-900">
                <span data-lucide="cycles" class="h-3.5 w-3.5"></span>
                Operations board
            </div>
            <h1 class="text-xl font-semibold">Cycle Monitoring</h1>
            <p class="text-sm text-muted">Track wash, dry, fold, iron, and pickup readiness.</p>
        </div>

        <form method="GET" class="flex gap-2">
            <select name="status" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                <option value="">All active</option>
                @foreach(['pending','washing','drying','folding','ready_for_pickup'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
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
                    <span class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        {{ str_replace('_', ' ', $order->status) }}
                    </span>
                </div>

                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach(['wash' => 'Washing', 'dry' => 'Drying', 'fold' => 'Folding', 'iron' => 'Ironing'] as $type => $label)
                        <form method="POST" action="{{ route('admin.cycles.store', $order) }}">
                            @csrf
                            <input type="hidden" name="cycle_type" value="{{ $type }}">
                            <button title="Start {{ $label }}" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border px-2 text-xs font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                <span data-lucide="plus" class="h-3.5 w-3.5"></span>
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>

                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach(['pending','washing','drying','folding','ready_for_pickup','completed'] as $status)
                        <form method="POST" action="{{ route('admin.cycles.status', $order) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $status }}">
                            <button class="h-8 rounded-md px-2 text-xs font-medium {{ $order->status === $status ? 'bg-primary text-white' : 'border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950' }}">
                                {{ str_replace('_', ' ', $status) }}
                            </button>
                        </form>
                    @endforeach
                </div>

                <div class="space-y-2 border-t border-border pt-3 dark:border-gray-800">
                    @forelse($order->cycles->sortByDesc('created_at')->take(5) as $cycle)
                        <div class="flex items-center justify-between gap-2 rounded-md bg-smoke px-3 py-2 text-sm dark:bg-gray-950">
                            <div>
                                <p class="font-medium">{{ ucfirst($cycle->cycle_type) }} #{{ $cycle->cycle_number }}</p>
                                <p class="text-xs text-muted">
                                    {{ $cycle->started_at?->format('M d, h:i A') ?? 'Not started' }}
                                    @if($cycle->ended_at) - {{ $cycle->ended_at->format('h:i A') }} @endif
                                </p>
                            </div>

                            @if(! $cycle->ended_at)
                                <form method="POST" action="{{ route('admin.cycles.end', $cycle) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button title="End cycle" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border hover:bg-white dark:border-gray-800 dark:hover:bg-gray-900">
                                        <span data-lucide="activity" class="h-4 w-4"></span>
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-muted">Done</span>
                            @endif
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
