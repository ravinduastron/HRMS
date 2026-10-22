<header class="fixed inset-x-0 top-0 z-40 flex h-14 items-center justify-between border-b border-slate-200 bg-white px-3 sm:px-4">
    {{-- Left: menu button (mobile), logo, system name --}}
    <div class="flex items-center gap-2">
        <button type="button" @click="sidebarOpen = !sidebarOpen" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Toggle navigation">
            <x-icon name="menu" />
        </button>
        <a href="{{ route('home') }}" class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
            <x-brand-logo size="h-8" :show-name="true" />
        </a>
    </div>

    {{-- Right: account menu (notifications and settings return when those features exist) --}}
    <div class="flex items-center gap-1">
        <x-dropdown width="w-56" label="Account menu" trigger-class="flex items-center gap-2.5 rounded-md py-1 pl-1 pr-2 hover:bg-slate-100">
            <x-slot:trigger>
                <x-avatar :name="auth()->user()->name" />
                <span class="hidden text-sm font-medium text-slate-800 sm:block">{{ auth()->user()->name }}</span>
                <x-icon name="chevron-down" class="hidden size-4 text-slate-400 sm:block" />
            </x-slot:trigger>
            <div class="border-b border-slate-100 px-3.5 py-2.5">
                <p class="truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ auth()->user()->username }}</p>
            </div>
            <x-dropdown-link :href="route('profile')" icon="user">My profile</x-dropdown-link>
            <x-dropdown-link :href="route('password.edit')" icon="lock">Change password</x-dropdown-link>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 hover:bg-slate-50">
                    <x-icon name="logout" class="size-4 text-slate-400" /> Sign out
                </button>
            </form>
        </x-dropdown>
    </div>
</header>
