@php
    $menuItems = auth()->user()?->accessibleMenuItems() ?? [];
@endphp

<div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-40 bg-black/45 lg:hidden" @click="sidebarOpen = false"></div>

<aside
    class="fixed inset-y-0 left-0 z-50 flex h-screen w-64 -translate-x-full flex-col border-r border-slate-800 bg-slate-950 text-slate-100 shadow-xl transition-transform duration-200 lg:translate-x-0 lg:shadow-none"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
>
    <div class="flex h-14 items-center justify-between border-b border-slate-800 px-3">
        <div class="flex min-w-0 items-center gap-2">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-700 bg-slate-900">
                <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-6 w-6 object-contain">
            </div>
            <div class="min-w-0">
                <h1 class="truncate text-sm font-semibold leading-tight text-white">{{ $appBusinessName }}</h1>
                <p class="truncate text-[11px] text-slate-400">Management</p>
            </div>
        </div>

        <button type="button" class="rounded-md p-1.5 text-slate-300 hover:bg-slate-900 hover:text-white lg:hidden" @click="sidebarOpen = false">
            <span data-lucide="x" class="h-4 w-4"></span>
        </button>
    </div>

    <div class="border-b border-slate-800 px-3 py-3">
        <div class="rounded-lg border border-slate-800 bg-slate-900/70 px-3 py-2">
            <p class="truncate text-xs font-medium text-slate-100">{{ auth()->user()->branch?->name ?? 'All Branches' }}</p>
            <p class="truncate text-[11px] text-slate-400">{{ str_replace('_', ' ', auth()->user()->role ?? '') }}</p>
        </div>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto p-2">
        @foreach($menuItems as $key => $item)
            @continue(! Route::has($item['route']))

            @php
                $active = request()->routeIs($item['route']) || request()->routeIs(str_replace('.index', '.*', $item['route']));
            @endphp

            <a
                href="{{ route($item['route']) }}"
                class="group flex h-9 items-center gap-2 rounded-md px-2.5 text-sm font-medium transition {{ $active ? 'bg-primary text-white' : 'text-slate-300 hover:bg-slate-900 hover:text-white' }}"
            >
                <span data-lucide="{{ $item['icon'] ?? 'dashboard' }}" class="h-4 w-4 shrink-0"></span>
                <span class="min-w-0 truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
