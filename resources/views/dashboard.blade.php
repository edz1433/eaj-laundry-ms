@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
<div
    x-data="dashboardPage(@js(route('dashboard.data', request()->query())), @js($dashboardData), @js($dateRangeValue))"
    class="space-y-4"
>
    <div class="flex flex-col gap-3 rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center gap-1.5 rounded-md border border-border bg-smoke px-2.5 py-1 text-xs font-medium text-muted dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="dashboard" class="h-3.5 w-3.5"></span>
                {{ $canChooseBranch ? 'Executive overview' : 'Branch command center' }}
            </div>
            <h1 class="text-xl font-semibold tracking-normal">
                {{ $canChooseBranch ? 'Business Dashboard' : auth()->user()->branch?->name.' Dashboard' }}
            </h1>
            <p class="text-sm text-muted">
                Live sales, physical collections, workflow, receivables, and inventory signals.
                <span class="ml-1" x-text="`Updated ${data.generated_at}`"></span>
            </p>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-[12rem_16rem_auto]">
            @if($canChooseBranch)
                <select name="branch_id" class="h-9 rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    <option value="">All branches</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}">
            @endif

            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                <span data-lucide="calendar" class="h-4 w-4 text-muted"></span>
                <input x-ref="dateRange" x-model="dateRange" name="date_range" type="text" class="w-full bg-transparent text-sm outline-none" autocomplete="off">
            </div>

            <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-white hover:opacity-90">
                <span data-lucide="search" class="h-4 w-4"></span>
                Apply
            </button>
        </form>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
        <template x-for="card in statCards" :key="card.key">
            <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-3 flex h-8 w-8 items-center justify-center rounded-md bg-smoke text-primary dark:bg-gray-950">
                    <span :data-lucide="card.icon" class="h-4 w-4"></span>
                </div>
                <p class="text-xs font-medium text-muted" x-text="card.label"></p>
                <p class="mt-1 text-lg font-semibold" x-text="data.stats[card.key]"></p>
            </div>
        </template>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.8fr)]">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Sales Trend</h2>
                    <p class="text-sm text-muted">Sales owned by branch in the selected date range.</p>
                </div>
                <span data-lucide="payments" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="salesChart"></canvas>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">Workflow Status</h2>
                    <p class="text-sm text-muted">Job orders by status.</p>
                </div>
                <span data-lucide="activity" class="h-4 w-4 text-primary"></span>
            </div>
            <div class="h-72">
                <canvas x-ref="statusChart"></canvas>
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-border px-4 py-3 dark:border-gray-800">
                <div>
                    <h2 class="text-base font-semibold">Recent Job Orders</h2>
                    <p class="text-sm text-muted">Live latest transactions.</p>
                </div>
                <a href="{{ route('admin.job-orders.index') }}" class="inline-flex h-8 items-center rounded-md border border-border px-3 text-sm hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">View all</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border bg-smoke text-xs uppercase text-muted dark:border-gray-800 dark:bg-gray-950">
                        <tr>
                            <th class="px-4 py-3">JO #</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Branch</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border dark:divide-gray-800">
                        <template x-for="order in data.recent_orders" :key="order.id">
                            <tr>
                                <td class="px-4 py-3"><a :href="order.url" class="font-medium hover:text-primary" x-text="order.number"></a></td>
                                <td class="px-4 py-3" x-text="order.customer"></td>
                                <td class="px-4 py-3" x-text="order.branch"></td>
                                <td class="px-4 py-3"><span :class="order.status_badge" x-text="order.status"></span></td>
                                <td class="px-4 py-3 text-right font-medium" x-text="order.total"></td>
                            </tr>
                        </template>
                        <tr x-show="data.recent_orders.length === 0">
                            <td colspan="5" class="px-4 py-10 text-center text-muted">No recent job orders.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-base font-semibold">Quick Actions</h2>
            <p class="mb-3 text-sm text-muted">Role-aware shortcuts.</p>
            <div class="space-y-2">
                @foreach([
                    ['label' => 'Create job order', 'route' => 'admin.job-orders.create', 'icon' => 'jobOrders'],
                    ['label' => 'Payments', 'route' => 'admin.payments.index', 'icon' => 'payments'],
                    ['label' => 'Receivables', 'route' => 'admin.receivables.index', 'icon' => 'receivables'],
                    ['label' => 'Inventory', 'route' => 'admin.inventory.index', 'icon' => 'inventory'],
                ] as $action)
                    @if(Route::has($action['route']) && auth()->user()->hasMenuAccess(str_contains($action['route'], 'job-orders') ? 'job_orders' : explode('.', $action['route'])[1]))
                        <a href="{{ route($action['route']) }}" class="flex h-10 items-center gap-2 rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="{{ $action['icon'] }}" class="h-4 w-4 text-primary"></span>
                            {{ $action['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>

<script>
function dashboardPage(fetchUrl, initialData, initialDateRange) {
    return {
        data: initialData,
        dateRange: initialDateRange,
        salesChart: null,
        statusChart: null,
        statCards: [
            { key: 'sales', label: 'Sales', icon: 'payments' },
            { key: 'collections', label: 'Collected', icon: 'receipt' },
            { key: 'orders', label: 'Orders', icon: 'jobOrders' },
            { key: 'open_orders', label: 'Open', icon: 'activity' },
            { key: 'ready_for_pickup', label: 'Ready', icon: 'laundry' },
            { key: 'receivables', label: 'Receivables', icon: 'receivables' },
            { key: 'low_stock', label: 'Low Stock', icon: 'inventory' },
        ],
        init() {
            this.$nextTick(() => {
                this.initDateRange();
                this.drawCharts();
                window.renderLucideIcons();
            });

            window.setInterval(() => this.refresh(), 30000);
        },
        initDateRange() {
            if (!window.flatpickr) return;

            window.flatpickr(this.$refs.dateRange, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                defaultDate: this.dateRange ? this.dateRange.split(' to ') : null,
                onClose: (dates, value) => this.dateRange = value,
            });
        },
        refresh() {
            fetch(fetchUrl, { headers: { 'Accept': 'application/json' } })
                .then(response => response.json())
                .then(payload => {
                    this.data = payload;
                    this.updateCharts();
                    this.$nextTick(() => window.renderLucideIcons());
                });
        },
        drawCharts() {
            const color = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#2E7D32';
            const grid = document.documentElement.classList.contains('dark') ? '#1f2937' : '#e2e8f0';

            this.salesChart = new window.Chart(this.$refs.salesChart, {
                type: 'line',
                data: {
                    labels: this.data.charts.sales.labels,
                    datasets: [{
                        label: 'Sales',
                        data: this.data.charts.sales.values,
                        borderColor: color,
                        backgroundColor: color + '22',
                        fill: true,
                        tension: 0.35,
                    }]
                },
                options: this.chartOptions(grid)
            });

            this.statusChart = new window.Chart(this.$refs.statusChart, {
                type: 'bar',
                data: {
                    labels: this.data.charts.status.labels,
                    datasets: [{
                        label: 'Orders',
                        data: this.data.charts.status.values,
                        backgroundColor: color,
                        borderRadius: 6,
                    }]
                },
                options: this.chartOptions(grid)
            });
        },
        updateCharts() {
            if (!this.salesChart || !this.statusChart) return;

            this.salesChart.data.labels = this.data.charts.sales.labels;
            this.salesChart.data.datasets[0].data = this.data.charts.sales.values;
            this.salesChart.update();

            this.statusChart.data.labels = this.data.charts.status.labels;
            this.statusChart.data.datasets[0].data = this.data.charts.status.values;
            this.statusChart.update();
        },
        chartOptions(grid) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: '#64748B' } },
                    y: { beginAtZero: true, grid: { color: grid }, ticks: { color: '#64748B', precision: 0 } },
                }
            };
        }
    }
}
</script>
@endsection
