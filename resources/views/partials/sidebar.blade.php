@php
    $menuItems = auth()->user()?->accessibleMenuItems() ?? [];
    $menuSections = [
        'dashboard' => 'Operations',
        'payments' => 'Cash Management',
        'employees' => 'Staff',
        'reports' => 'Management',
    ];
    $currentSection = null;
@endphp

<div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-40 bg-black/45 xl:hidden" @click="sidebarOpen = false"></div>

<aside
    class="fixed inset-y-0 left-0 z-50 flex h-screen w-64 -translate-x-full flex-col overflow-hidden border-r border-sky-950/70 bg-sky-950 text-slate-100 shadow-xl transition-transform duration-200 xl:shadow-none"
    :class="sidebarVisible ? 'translate-x-0' : '-translate-x-full'"
>
    <div class="pointer-events-none absolute inset-0 opacity-80">
        <div class="absolute -left-20 top-10 h-44 w-44 rounded-full bg-sky-400/10 blur-3xl"></div>
        <div class="absolute bottom-14 right-[-4rem] h-44 w-44 rounded-full bg-teal-300/10 blur-3xl"></div>
    </div>

    <div class="relative flex h-14 items-center justify-between border-b border-sky-900/70 px-3">
        <div class="flex min-w-0 items-center gap-2">
            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-sky-700/70 bg-sky-900/80">
                <img src="{{ $appBusinessLogo }}" alt="{{ $appBusinessName }} logo" class="h-6 w-6 object-contain">
            </div>
            <div class="min-w-0">
                <h1 class="truncate text-sm font-semibold leading-tight text-white">{{ $appSystemName }}</h1>
                <p class="truncate text-[11px] text-sky-200/80">{{ $appBusinessName }}</p>
            </div>
        </div>

        <button type="button" class="rounded-md p-1.5 text-sky-100 hover:bg-sky-900 hover:text-white" @click="closeSidebar()">
            <span data-lucide="x" class="h-4 w-4"></span>
        </button>
    </div>

    <div class="relative border-b border-sky-900/70 px-3 py-3">
        <div class="rounded-lg border border-sky-800/70 bg-sky-900/60 px-3 py-2 shadow-sm shadow-sky-950/20">
            <p class="truncate text-xs font-medium text-slate-100">{{ auth()->user()->branch?->name ?? 'All Branches' }}</p>
            <p class="truncate text-[11px] text-sky-200/75">{{ str_replace('_', ' ', auth()->user()->role ?? '') }}</p>
        </div>
    </div>

    <nav class="relative flex-1 space-y-0.5 overflow-y-auto p-2">
        @foreach($menuItems as $key => $item)
            @continue(! Route::has($item['route']))

            @php
                $active = request()->routeIs($item['route']) || request()->routeIs(str_replace('.index', '.*', $item['route']));
                $section = $menuSections[$key] ?? null;
            @endphp

            @if($section && $section !== $currentSection)
                @php($currentSection = $section)
                <p class="px-2.5 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wider text-sky-300/70 first:pt-1">{{ $section }}</p>
            @endif

            <a
                href="{{ route($item['route']) }}"
                class="group flex h-9 items-center gap-2 rounded-md px-2.5 text-sm font-medium transition {{ $active ? 'bg-sky-500 text-white shadow-sm shadow-sky-950/20' : 'text-sky-100/85 hover:bg-sky-900/80 hover:text-white' }}"
            >
                <span data-lucide="{{ $item['icon'] ?? 'dashboard' }}" class="h-4 w-4 shrink-0"></span>
                <span class="min-w-0 truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
