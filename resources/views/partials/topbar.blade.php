<header class="sticky top-0 z-30 border-b border-border bg-white/90 backdrop-blur dark:border-gray-800 dark:bg-gray-950/90">
    <div class="flex h-14 items-center justify-between gap-3 px-3 lg:px-5">
        <div class="flex min-w-0 items-center gap-2">
            <button type="button" class="rounded-md border border-border bg-white p-2 dark:border-gray-800 dark:bg-gray-900 lg:hidden" @click="sidebarOpen = true">
                <span data-lucide="menu" class="h-4 w-4"></span>
            </button>

            <div class="min-w-0">
                <h2 class="truncate text-base font-semibold">@yield('page_title', 'Dashboard')</h2>
                @if(in_array(auth()->user()->role, ['branch_manager', 'cashier'], true))
                    <p class="hidden truncate text-xs text-muted sm:block">Welcome back, {{ auth()->user()->name ?? 'User' }}</p>
                @else
                    <p class="hidden truncate text-xs text-muted sm:block">Administration workspace</p>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="$store.theme.toggle()"
                class="flex h-9 w-9 items-center justify-center rounded-md border border-border bg-white transition hover:bg-smoke dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800"
                aria-label="Toggle theme"
            >
                <span x-show="!$store.theme.dark" data-lucide="moon" class="h-4 w-4"></span>
                <span x-show="$store.theme.dark" data-lucide="sun" class="h-4 w-4"></span>
            </button>

            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" class="flex h-9 items-center gap-2 rounded-md border border-border bg-white px-1.5 pr-2 transition hover:bg-smoke dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-800">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-primary text-xs font-semibold text-white">
                        {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                    </span>
                    <span class="hidden max-w-28 truncate text-sm font-medium sm:block">{{ auth()->user()->name ?? 'User' }}</span>
                    <span data-lucide="chevronDown" class="hidden h-4 w-4 text-muted sm:block"></span>
                </button>

                <div
                    x-cloak
                    x-show="open"
                    x-transition
                    @click.outside="open = false"
                    class="absolute right-0 mt-2 w-56 rounded-md border border-border bg-white p-1 shadow-lg dark:border-gray-800 dark:bg-gray-900"
                >
                    <div class="px-2 py-2">
                        <p class="truncate text-sm font-medium">{{ auth()->user()->name ?? 'User' }}</p>
                        <p class="truncate text-xs text-muted">{{ auth()->user()->email ?? '' }}</p>
                    </div>

                    <div class="my-1 h-px bg-border dark:bg-gray-800"></div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="flex h-8 w-full items-center gap-2 rounded-sm px-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">
                            <span data-lucide="logout" class="h-4 w-4"></span>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
