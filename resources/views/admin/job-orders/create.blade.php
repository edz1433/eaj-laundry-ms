@extends('layouts.app')

@section('page_title', in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'New Job Order')

@section('content')
<form
    method="POST"
    action="{{ route('admin.job-orders.store') }}"
    x-data="posPage(@js($services), @js($customers), @js((float) ($appSettings?->vat_rate ?? 0)), @js((bool) ($appSettings?->vat_enabled ?? false)))"
    class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]"
>
    @csrf

    <section class="min-w-0 space-y-4">
        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-semibold">New Job Order</h1>
                    <p class="text-sm text-muted">Select a customer, add services, then collect payment.</p>
                </div>
                <a href="{{ route('admin.job-orders.index') }}" class="inline-flex h-9 items-center rounded-md border border-border px-3 text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                    Orders
                </a>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium">Branch</label>
                    @if(in_array(auth()->user()->role, ['super_admin', 'admin'], true))
                        <select name="branch_id" x-model="branchId" class="h-10 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected($branchId == $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" name="branch_id" value="{{ $branchId }}">
                        <input value="{{ auth()->user()->branch?->name }}" disabled class="h-10 w-full rounded-md border border-border bg-smoke px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                    @endif
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium">Customer</label>
                    <div class="relative" @click.outside="customerOpen = false">
                        <input type="hidden" name="customer_id" :value="selectedCustomerId">
                        <div class="flex h-10 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                            <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                            <input
                                type="search"
                                x-model="customerSearch"
                                @focus="customerOpen = true"
                                @input="selectedCustomerId = ''; customerOpen = true"
                                placeholder="Search customer name, phone, billing..."
                                class="w-full bg-transparent text-sm outline-none"
                                autocomplete="off"
                            >
                            <button type="button" x-show="selectedCustomerId" @click="clearCustomer()" title="Clear customer" aria-label="Clear customer" class="inline-flex h-6 w-6 items-center justify-center rounded-md hover:bg-smoke dark:hover:bg-gray-900">
                                <span data-lucide="x" class="h-3.5 w-3.5"></span>
                            </button>
                        </div>

                        <div
                            x-cloak
                            x-show="customerOpen"
                            x-transition
                            class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-white p-1 shadow-lg dark:border-gray-800 dark:bg-gray-950"
                        >
                            <template x-for="customer in filteredCustomers" :key="customer.id">
                                <button
                                    type="button"
                                    @click="selectCustomer(customer)"
                                    class="flex w-full items-center justify-between gap-3 rounded-sm px-3 py-2 text-left text-sm hover:bg-smoke dark:hover:bg-gray-900"
                                >
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium" x-text="customer.name"></span>
                                        <span class="block truncate text-xs text-muted" x-text="`${customer.phone || 'No phone'} - ${formatBilling(customer.billing_type)}`"></span>
                                    </span>
                                    <span x-show="String(selectedCustomerId) === String(customer.id)" data-lucide="check" class="h-4 w-4 shrink-0 text-primary"></span>
                                </button>
                            </template>

                            <div x-show="filteredCustomers.length === 0" class="px-3 py-6 text-center text-sm text-muted">
                                No customers found for this branch.
                            </div>
                        </div>
                    </div>
                    <p x-show="!selectedCustomerId && customerSearch" class="mt-1.5 text-xs text-amber-600 dark:text-amber-300">
                        Select a customer from the list before saving.
                    </p>
                </div>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <label class="text-sm font-medium">
                    Loads Used
                    <input name="load_count" type="number" min="0" value="0" class="mt-1.5 h-10 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                </label>

                <label class="text-sm font-medium">
                    Dry Cycles
                    <input name="drying_cycles" type="number" min="0" value="0" class="mt-1.5 h-10 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                </label>

                <label class="text-sm font-medium">
                    Extra Dry Minutes
                    <input name="drying_extension_minutes" type="number" min="0" value="0" class="mt-1.5 h-10 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                </label>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="font-semibold">Service Catalog</h2>
                    <p class="text-sm text-muted">Tap a service to add it to the cart.</p>
                </div>

                <div class="flex h-9 w-full items-center gap-2 rounded-md border border-border px-3 dark:border-gray-800 md:w-64">
                    <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                    <input type="search" x-model.debounce.200ms="serviceSearch" placeholder="Search services..." class="w-full bg-transparent text-sm outline-none">
                </div>
            </div>

            <div class="mb-3 flex gap-1 overflow-x-auto rounded-md bg-smoke p-1 dark:bg-gray-950">
                <template x-for="type in serviceTypes" :key="type.value">
                    <button
                        type="button"
                        @click="typeFilter = type.value"
                        class="h-8 shrink-0 rounded-sm px-3 text-sm font-medium"
                        :class="typeFilter === type.value ? 'bg-white text-dark shadow-sm dark:bg-gray-900 dark:text-white' : 'text-muted hover:text-dark dark:hover:text-white'"
                        x-text="type.label"
                    ></button>
                </template>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="service in filteredServices" :key="service.id">
                    <button
                        type="button"
                        @click="add(service)"
                        class="group rounded-md border border-border p-3 text-left transition hover:border-primary hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium" x-text="service.name"></p>
                                <p class="mt-1 text-xs capitalize text-muted" x-text="service.pricing_type"></p>
                            </div>
                            <span class="rounded-md bg-white px-2 py-1 text-xs font-medium text-primary shadow-sm dark:bg-gray-900">
                                {{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(service.price)"></span>
                            </span>
                        </div>
                    </button>
                </template>

                <div x-show="filteredServices.length === 0" class="col-span-full rounded-md border border-dashed border-border p-8 text-center text-sm text-muted dark:border-gray-800">
                    No services match your filter.
                </div>
            </div>
        </div>

        <textarea name="notes" rows="3" placeholder="Notes / instructions" class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-950"></textarea>
    </section>

    <aside class="w-full lg:sticky lg:top-[4.5rem] lg:w-80 lg:self-start xl:w-[22rem] xl:justify-self-end">
        <div class="w-full min-w-0 overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex h-12 items-center justify-between border-b border-border px-3 dark:border-gray-800">
                <div>
                    <h2 class="text-sm font-semibold">Cart</h2>
                    <p class="text-[11px] text-muted"><span x-text="items.length"></span> item<span x-show="items.length !== 1">s</span></p>
                </div>
                <button type="button" x-show="items.length" @click="items = []" title="Clear cart" aria-label="Clear cart" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                    <span data-lucide="trash" class="h-4 w-4"></span>
                </button>
            </div>

            <div class="max-h-[40vh] space-y-1.5 overflow-y-auto p-2 lg:max-h-[calc(100vh-22rem)]">
                <template x-for="(item, index) in items" :key="index">
                    <div class="rounded-md border border-border bg-white p-2 dark:border-gray-800 dark:bg-gray-950">
                        <input type="hidden" :name="`items[${index}][laundry_service_id]`" :value="item.id">
                        <input type="hidden" :name="`items[${index}][description]`" :value="item.name">

                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium" x-text="item.name"></p>
                                <p class="text-[11px] text-muted">{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(item.quantity * item.price)"></span></p>
                            </div>
                            <button type="button" @click="items.splice(index, 1)" title="Remove item" aria-label="Remove item" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                                <span data-lucide="x" class="h-4 w-4"></span>
                            </button>
                        </div>

                        <div class="grid grid-cols-[5.5rem_1fr] gap-1.5">
                            <div class="flex h-8 overflow-hidden rounded-md border border-border dark:border-gray-800">
                                <button type="button" @click="item.quantity = Math.max(Number(item.quantity || 0) - 1, 0.01)" class="flex w-7 items-center justify-center hover:bg-smoke dark:hover:bg-gray-900">-</button>
                                <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model.number="item.quantity" aria-label="Quantity" class="w-full border-x border-border bg-transparent px-1 text-center text-xs outline-none dark:border-gray-800">
                                <button type="button" @click="item.quantity = Number(item.quantity || 0) + 1" class="flex w-7 items-center justify-center hover:bg-smoke dark:hover:bg-gray-900">+</button>
                            </div>
                            <div class="flex h-8 items-center rounded-md border border-border px-2 dark:border-gray-800">
                                <span class="mr-1 text-[11px] text-muted">{{ $appSettings?->currency ?? 'PHP' }}</span>
                                <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.price" aria-label="Unit price" class="w-full bg-transparent text-right text-xs outline-none">
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="items.length === 0" class="rounded-md border border-dashed border-border p-6 text-center text-sm text-muted dark:border-gray-800">
                    Add services from the catalog.
                </div>
            </div>

            <div class="space-y-1.5 border-t border-border p-3 text-sm dark:border-gray-800">
                <div class="flex justify-between text-xs"><span class="text-muted">Subtotal</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(subtotal)"></span></span></div>
                <div class="flex h-8 items-center justify-between gap-3">
                    <span class="text-muted">Discount</span>
                    <input name="discount" x-model.number="discount" type="number" min="0" step="0.01" class="h-8 w-24 rounded-md border border-border px-2 text-right text-xs dark:border-gray-800 dark:bg-gray-950">
                </div>
                <div class="flex justify-between text-xs"><span class="text-muted">VAT</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(tax)"></span></span></div>
                <div class="my-1.5 h-px bg-border dark:bg-gray-800"></div>
                <div class="flex justify-between text-base font-semibold"><span>Total</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(total)"></span></span></div>
                <div class="flex h-8 items-center justify-between gap-3">
                    <span class="text-muted">Paid</span>
                    <input name="paid_amount" x-model.number="paid" type="number" min="0" step="0.01" class="h-8 w-24 rounded-md border border-border px-2 text-right text-xs dark:border-gray-800 dark:bg-gray-950">
                </div>
                <div class="flex justify-between text-sm font-medium"><span>Balance</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(balance)"></span></span></div>
                <div class="grid grid-cols-[1fr_auto] gap-2 pt-1">
                    <select name="payment_type" class="h-9 min-w-0 rounded-md border border-border bg-white px-2 text-xs dark:border-gray-800 dark:bg-gray-950">
                        <option value="cash">Cash</option>
                        <option value="credit">Credit</option>
                        <option value="po">PO</option>
                        <option value="monthly_billing">Monthly Billing</option>
                    </select>
                    <button type="button" @click="paid = total" title="Pay exact total" aria-label="Pay exact total" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                        <span data-lucide="payments" class="h-4 w-4"></span>
                    </button>
                </div>
                <button :disabled="items.length === 0 || !selectedCustomerId" class="h-9 w-full rounded-md bg-primary text-sm font-medium text-white hover:opacity-90 disabled:opacity-50">
                    Save Job Order
                </button>
            </div>
        </div>
    </aside>
</form>

<script>
function posPage(services, customers, vatRate, vatEnabled) {
    return {
        branchId: @js((string) $branchId),
        services,
        customers,
        items: [],
        discount: 0,
        paid: 0,
        customerOpen: false,
        customerSearch: '',
        selectedCustomerId: '',
        serviceSearch: '',
        typeFilter: 'all',
        serviceTypes: [
            { value: 'all', label: 'All' },
            { value: 'kilo', label: 'Kilo' },
            { value: 'load', label: 'Load' },
            { value: 'piece', label: 'Piece' },
            { value: 'custom', label: 'Custom' },
        ],
        init() {
            this.$watch('branchId', () => {
                this.items = [];
                this.discount = 0;
                this.paid = 0;
                this.serviceSearch = '';

                if (!this.selectedCustomerId) {
                    this.$nextTick(() => window.renderLucideIcons());
                    return;
                }

                const selected = this.customers.find(customer => String(customer.id) === String(this.selectedCustomerId));
                if (!selected || String(selected.branch_id) !== String(this.branchId)) {
                    this.clearCustomer();
                }

                this.$nextTick(() => window.renderLucideIcons());
            });
        },
        get availableCustomers() {
            return this.customers.filter(customer => String(customer.branch_id) === String(this.branchId));
        },
        get filteredCustomers() {
            const term = this.customerSearch.toLowerCase().trim();

            return this.availableCustomers.filter(customer => {
                const billing = this.formatBilling(customer.billing_type).toLowerCase();

                return !term
                    || customer.name.toLowerCase().includes(term)
                    || String(customer.phone || '').toLowerCase().includes(term)
                    || billing.includes(term);
            }).slice(0, 30);
        },
        selectCustomer(customer) {
            this.selectedCustomerId = customer.id;
            this.customerSearch = `${customer.name} - ${this.formatBilling(customer.billing_type)}`;
            this.customerOpen = false;
            this.$nextTick(() => window.renderLucideIcons());
        },
        clearCustomer() {
            this.selectedCustomerId = '';
            this.customerSearch = '';
            this.customerOpen = false;
            this.$nextTick(() => window.renderLucideIcons());
        },
        formatBilling(value) {
            return String(value || 'regular').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
        },
        get availableServices() {
            return this.services.filter(service => String(service.branch_id) === String(this.branchId));
        },
        get filteredServices() {
            const term = this.serviceSearch.toLowerCase().trim();
            return this.availableServices.filter(service => {
                const matchesType = this.typeFilter === 'all' || service.pricing_type === this.typeFilter;
                const matchesSearch = !term || service.name.toLowerCase().includes(term);
                return matchesType && matchesSearch;
            });
        },
        add(service) {
            const existing = this.items.find(item => item.id === service.id);
            if (existing) {
                existing.quantity = Number(existing.quantity) + 1;
            } else {
                this.items.push({ id: service.id, name: service.name, quantity: 1, price: Number(service.price) });
            }
            this.$nextTick(() => window.renderLucideIcons());
        },
        get subtotal() { return this.items.reduce((sum, item) => sum + (Number(item.quantity || 0) * Number(item.price || 0)), 0); },
        get tax() { return vatEnabled ? Math.max(this.subtotal - Number(this.discount || 0), 0) * (Number(vatRate) / 100) : 0; },
        get total() { return Math.max(this.subtotal - Number(this.discount || 0), 0) + this.tax; },
        get balance() { return Math.max(this.total - Number(this.paid || 0), 0); },
        money(value) { return Number(value || 0).toFixed(2); }
    }
}
</script>
@endsection
