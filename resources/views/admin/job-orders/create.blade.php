@extends('layouts.app')

@section('page_title', in_array(auth()->user()->role, ['branch_manager', 'cashier'], true) ? 'Cashier POS' : 'New Job Order')
@section('hide_footer', true)

@section('content')
<div
    x-data="posPage(@js($branches), @js($processingBranches), @js($services), @js($customers), @js((float) ($appSettings?->vat_rate ?? 0)), @js((bool) ($appSettings?->vat_enabled ?? false)))"
>
    <form
        method="POST"
        action="{{ route('admin.job-orders.store') }}"
        class="grid gap-4 md:h-[calc(100dvh-6.5rem)] md:grid-cols-[minmax(0,1fr)_18rem] md:overflow-hidden lg:h-[calc(100dvh-7.5rem)] lg:grid-cols-[minmax(0,1fr)_20rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]"
    >
        @csrf

        <section class="min-h-0 min-w-0">
            <div class="flex h-full min-h-0 flex-col rounded-lg border border-border bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:p-4">
                <div class="mb-3 flex shrink-0 flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <h2 class="font-semibold">Service Catalog</h2>
                        <p class="text-sm text-muted">Tap a service to add it to the cart.</p>
                    </div>

                    <div class="flex h-9 w-full items-center gap-2 rounded-md border border-border px-3 dark:border-gray-800 xl:w-64">
                        <span data-lucide="search" class="h-4 w-4 text-muted"></span>
                        <input type="search" x-model.debounce.200ms="serviceSearch" placeholder="Search services..." class="w-full bg-transparent text-sm outline-none">
                    </div>
                </div>

                <div class="mb-3 flex shrink-0 gap-1 overflow-x-auto rounded-md bg-smoke p-1 dark:bg-gray-950" x-effect="serviceTypes; refreshIcons()">
                    <template x-for="type in serviceTypes" :key="type.value">
                        <button
                            type="button"
                            @click="typeFilter = type.value; refreshIcons()"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-sm px-3 text-sm font-medium"
                            :class="typeFilter === type.value ? 'bg-white text-dark shadow-sm dark:bg-gray-900 dark:text-white' : 'text-muted hover:text-dark dark:hover:text-white'"
                        >
                            <span :data-lucide="type.icon" class="h-3.5 w-3.5"></span>
                            <span x-text="type.label"></span>
                        </button>
                    </template>
                </div>

                <div class="grid min-h-0 flex-1 content-start gap-2 overflow-y-auto pr-1 sm:grid-cols-2 xl:grid-cols-3" x-effect="filteredServices.map(service => service.id).join(','); refreshIcons()">
                    <template x-for="service in filteredServices" :key="service.id">
                        <button
                            type="button"
                            @click="add(service)"
                            class="group rounded-md border border-border p-3 text-left transition hover:border-primary hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                        <span :data-lucide="serviceIcon(service)" class="h-5 w-5"></span>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium" x-text="service.name"></p>
                                        <p class="mt-1 text-xs capitalize text-muted" x-text="service.pricing_type"></p>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-primary shadow-sm dark:bg-gray-900">
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
        </section>

        <aside class="min-h-0 w-full md:self-stretch lg:w-80 xl:w-[22rem] xl:justify-self-end">
            <div class="flex h-full w-full min-w-0 flex-col overflow-hidden rounded-lg border border-border bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="shrink-0 space-y-2 border-b border-border p-3 dark:border-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <h1 class="truncate text-sm font-semibold">New Job Order</h1>
                            <p class="text-[11px] text-muted">Customer and cart</p>
                        </div>
                        <a href="{{ route('admin.job-orders.index') }}" class="inline-flex h-8 shrink-0 items-center rounded-md border border-border px-2.5 text-xs font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            Orders
                        </a>
                    </div>

                    @if(in_array(auth()->user()->role, ['super_admin', 'admin'], true))
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-muted">Branch</label>
                            <select name="branch_id" x-model="branchId" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950" required>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($branchId == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="branch_id" value="{{ $branchId }}">
                    @endif

                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Receiving Production Branch</label>
                        <select name="processing_branch_id" x-model="processingBranchId" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950" required>
                            <template x-for="branch in processingBranches" :key="branch.id">
                                <option :value="branch.id" x-text="`${branch.name} - receives by QR scan`"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-[11px] text-muted" x-text="selectedBranch && selectedBranch.branch_type === 'pickup_dropoff' ? 'This only assigns where the laundry should be received. It enters that branch cycle after they scan the QR.' : 'Production defaults to the selected full-service branch.'"></p>
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium text-muted">Customer</label>
                            <button type="button" @click="quickCustomerOpen = true; refreshIcons()" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border px-2 text-xs font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                                <span data-lucide="plus" class="h-3.5 w-3.5"></span>
                                Add
                            </button>
                        </div>
                        <div class="relative" @click.outside="customerOpen = false">
                            <input type="hidden" name="customer_id" :value="selectedCustomerId">
                            <div class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-3 dark:border-gray-800 dark:bg-gray-950">
                                <span data-lucide="search" class="h-4 w-4 shrink-0 text-muted"></span>
                                <input
                                    type="search"
                                    x-model="customerSearch"
                                    @focus="customerOpen = true"
                                    @input="selectedCustomerId = ''; customerOpen = true"
                                    placeholder="Search customer..."
                                    class="min-w-0 flex-1 bg-transparent text-sm outline-none"
                                    autocomplete="off"
                                >
                                <button type="button" x-show="selectedCustomerId" @click="clearCustomer()" title="Clear customer" aria-label="Clear customer" class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md hover:bg-smoke dark:hover:bg-gray-900">
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

                    <textarea name="notes" rows="2" placeholder="Notes / instructions" class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-950"></textarea>

                    <div class="grid grid-cols-2 gap-1 rounded-md bg-smoke p-1 dark:bg-gray-950">
                        <label class="flex h-8 cursor-pointer items-center justify-center rounded-sm text-xs font-medium has-[:checked]:bg-white has-[:checked]:text-primary has-[:checked]:shadow-sm dark:has-[:checked]:bg-gray-900">
                            <input type="radio" name="transaction_type" value="walk_in" checked class="sr-only">
                            Walk-in
                        </label>
                        <label class="flex h-8 cursor-pointer items-center justify-center rounded-sm text-xs font-medium has-[:checked]:bg-orange-100 has-[:checked]:text-orange-700 has-[:checked]:shadow-sm dark:has-[:checked]:bg-orange-500/10 dark:has-[:checked]:text-orange-300">
                            <input type="radio" name="transaction_type" value="delivery" class="sr-only">
                            Delivery
                        </label>
                    </div>
                </div>

            <div class="flex h-12 shrink-0 items-center justify-between border-b border-border px-3 dark:border-gray-800">
                <div>
                    <h2 class="text-sm font-semibold">Cart</h2>
                    <p class="text-[11px] text-muted"><span x-text="items.length"></span> item<span x-show="items.length !== 1">s</span></p>
                </div>
                <button type="button" x-show="items.length" @click="items = []" title="Clear cart" aria-label="Clear cart" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10">
                    <span data-lucide="trash" class="h-4 w-4"></span>
                </button>
            </div>

            <div class="min-h-[8rem] flex-1 space-y-1.5 overflow-y-auto p-2">
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

            <div class="shrink-0 border-t border-border p-3 dark:border-gray-800">
                <button type="button" @click="showPaymentPanel = true; refreshIcons()" :disabled="items.length === 0 || !selectedCustomerId" class="h-9 w-full rounded-md bg-primary text-sm font-medium text-white hover:opacity-90 disabled:opacity-50">
                    Save Job Order
                </button>
            </div>
            </div>
        </aside>

        <div x-cloak x-show="showPaymentPanel" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div @click.outside="showPaymentPanel = false" class="w-full max-w-md rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="payments" class="h-4 w-4 text-primary"></span>Payment</h2>
                    <button type="button" @click="showPaymentPanel = false" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
                </div>

                <div class="space-y-2 text-sm">
                    <div class="flex justify-between text-xs"><span class="text-muted">Subtotal</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(subtotal)"></span></span></div>
                    <div class="flex h-9 items-center justify-between gap-3">
                        <span class="text-muted">Discount</span>
                        <input name="discount" x-model.number="discount" type="number" min="0" step="0.01" class="h-9 w-28 rounded-md border border-border px-2 text-right text-sm dark:border-gray-800 dark:bg-gray-950">
                    </div>
                    <div class="flex justify-between text-xs"><span class="text-muted">VAT</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(tax)"></span></span></div>
                    <div class="my-2 h-px bg-border dark:bg-gray-800"></div>
                    <div class="flex justify-between text-base font-semibold"><span>Total</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(total)"></span></span></div>
                    <div class="flex h-9 items-center justify-between gap-3">
                        <span class="text-muted">Paid</span>
                        <input name="paid_amount" x-model.number="paid" type="number" min="0" step="0.01" class="h-9 w-28 rounded-md border border-border px-2 text-right text-sm dark:border-gray-800 dark:bg-gray-950">
                    </div>
                    <div class="flex justify-between text-sm font-medium"><span>Balance</span><span>{{ $appSettings?->currency ?? 'PHP' }} <span x-text="money(balance)"></span></span></div>
                    <div class="grid grid-cols-[1fr_auto] gap-2 pt-2">
                        <select name="payment_type" class="h-9 min-w-0 rounded-md border border-border bg-white px-2 text-sm dark:border-gray-800 dark:bg-gray-950">
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                            <option value="bank">Bank</option>
                            <option value="credit">Credit</option>
                            <option value="po">PO</option>
                            <option value="monthly_billing">Monthly Billing</option>
                        </select>
                        <button type="button" @click="paid = total" title="Pay exact total" aria-label="Pay exact total" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">
                            <span data-lucide="payments" class="h-4 w-4"></span>
                        </button>
                    </div>
                    <input name="payment_reference_no" placeholder="Reference no. for GCash/card" class="h-9 w-full rounded-md border border-border bg-white px-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                </div>

                <div class="mt-5 grid grid-cols-2 gap-2">
                    <button type="button" @click="showPaymentPanel = false" class="h-9 rounded-md border border-border text-sm font-medium hover:bg-smoke dark:border-gray-800 dark:hover:bg-gray-950">Cancel</button>
                    <button type="submit" class="h-9 rounded-md bg-primary text-sm font-medium text-white hover:opacity-90">Confirm Save</button>
                </div>
            </div>
        </div>
    </form>

    <div x-cloak x-show="quickCustomerOpen" x-transition class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div @click.outside="quickCustomerOpen = false" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white p-5 shadow-2xl dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="inline-flex items-center gap-2 text-lg font-semibold"><span data-lucide="customers" class="h-4 w-4 text-primary"></span>Add Customer</h2>
                <button type="button" @click="quickCustomerOpen = false" class="rounded-md p-2 hover:bg-smoke dark:hover:bg-gray-800"><span data-lucide="x" class="h-4 w-4"></span></button>
            </div>

            @include('admin.customers.partials.form', [
                'action' => route('admin.job-orders.customers.store'),
                'method' => 'POST',
                'customer' => new \App\Models\Customer(['branch_id' => $branchId, 'billing_type' => 'regular', 'is_active' => true, 'credit_limit' => 0]),
                'redirectTo' => 'pos',
                'branchModel' => 'branchId',
            ])
        </div>
    </div>
</div>

<script>
function posPage(branches, processingBranches, services, customers, vatRate, vatEnabled) {
    return {
        branchId: @js((string) $branchId),
        branches,
        processingBranches,
        processingBranchId: '',
        services,
        customers,
        items: [],
        discount: 0,
        paid: 0,
        showPaymentPanel: false,
        quickCustomerOpen: @js($errors->any() && old('redirect_to') === 'pos'),
        customerOpen: false,
        customerSearch: '',
        selectedCustomerId: @js((string) ($selectedCustomerId ?? '')),
        serviceSearch: '',
        typeFilter: 'all',
        serviceTypes: [
            { value: 'all', label: 'All', icon: 'services' },
            { value: 'kilo', label: 'Kilo', icon: 'scale' },
            { value: 'load', label: 'Load', icon: 'laundry' },
            { value: 'piece', label: 'Piece', icon: 'shirt' },
            { value: 'custom', label: 'Custom', icon: 'sparkles' },
        ],
        init() {
            this.setDefaultProcessingBranch();
            this.syncSelectedCustomer();
            this.$watch('branchId', () => {
                this.items = [];
                this.discount = 0;
                this.paid = 0;
                this.serviceSearch = '';
                this.setDefaultProcessingBranch();

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
            this.$watch('quickCustomerOpen', () => this.refreshIcons());
        },
        get selectedBranch() {
            return this.branches.find(branch => String(branch.id) === String(this.branchId));
        },
        setDefaultProcessingBranch() {
            const branch = this.selectedBranch;
            const fullService = this.processingBranches.find(option => String(option.id) === String(this.branchId));

            if (branch && branch.branch_type !== 'pickup_dropoff' && fullService) {
                this.processingBranchId = fullService.id;
                return;
            }

            this.processingBranchId = this.processingBranches[0]?.id || '';
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
        syncSelectedCustomer() {
            if (!this.selectedCustomerId) {
                return;
            }

            const selected = this.customers.find(customer => String(customer.id) === String(this.selectedCustomerId));
            if (selected) {
                this.selectCustomer(selected);
            }
        },
        formatBilling(value) {
            return String(value || 'regular').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
        },
        serviceIcon(service) {
            const name = String(service.name || '').toLowerCase();

            if (name.includes('dry') || name.includes('dryer')) return 'droplets';
            if (name.includes('fold') || name.includes('iron') || name.includes('press')) return 'shirt';
            if (name.includes('wash') || name.includes('laundry')) return 'laundry';
            if (service.pricing_type === 'kilo') return 'scale';
            if (service.pricing_type === 'piece') return 'shirt';
            if (service.pricing_type === 'custom') return 'sparkles';

            return 'laundry';
        },
        refreshIcons() {
            this.$nextTick(() => window.renderLucideIcons());
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
