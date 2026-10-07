@php
    $visible = fn (array $item) => ! isset($item['can']) || auth()->user()?->can($item['can']);
    $matches = fn (string $url) => $url === '/' ? request()->is('/') : request()->is(trim($url, '/'), trim($url, '/').'/*');

    // Only the most specific (longest) matching link is active, so /employees/sync does not also light up /employees.
    $bestUrl = collect(config('navigation'))->flatMap(fn ($section) => $section['items'])
        ->flatMap(fn ($item) => $item['children'] ?? [$item])->filter($visible)->pluck('url')
        ->filter($matches)->sortByDesc(fn ($url) => strlen($url))->first();
    $isActive = fn (string $url) => $url === $bestUrl;
@endphp

{{-- Mobile backdrop --}}
<div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false" class="fixed inset-x-0 bottom-0 top-14 z-30 bg-slate-900/50 lg:hidden"></div>

<aside class="fixed bottom-0 left-0 top-14 z-30 flex w-64 flex-col bg-brand-950 transition-all duration-200"
    :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0', collapsed ? 'lg:w-[4.5rem]' : 'lg:w-64']"
    aria-label="Main navigation">

    <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
        @foreach (config('navigation') as $section)
            @php $items = collect($section['items'])->filter($visible); @endphp
            @continue($items->isEmpty())

            <div>
                <p x-show="!compact" class="mb-1.5 px-3 text-xs font-medium text-brand-300/60">{{ $section['heading'] }}</p>
                <div x-show="compact" x-cloak class="mx-3 mb-2 border-t border-white/10"></div>

                <div class="space-y-0.5">
                    @foreach ($items as $item)
                        @if (isset($item['children']))
                            @php $kids = collect($item['children'])->filter($visible); $groupActive = $kids->contains(fn ($c) => $isActive($c['url'])); @endphp
                            @continue($kids->isEmpty())
                            <x-nav-group :label="$item['label']" :icon="$item['icon']" :active="$groupActive">
                                @foreach ($kids as $child)
                                    <x-nav-link :href="url($child['url'])" :active="$isActive($child['url'])" :child="true">{{ $child['label'] }}</x-nav-link>
                                @endforeach
                            </x-nav-group>
                        @else
                            <x-nav-link :href="url($item['url'])" :icon="$item['icon']" :active="$isActive($item['url'])">{{ $item['label'] }}</x-nav-link>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    {{-- Collapse toggle (desktop only) --}}
    <div class="hidden border-t border-white/10 p-3 lg:block">
        <button type="button" @click="collapsed = !collapsed" class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm text-brand-100/75 hover:bg-white/5 hover:text-white" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">
            <x-icon name="chevrons-left" class="transition-transform" ::class="collapsed && 'rotate-180'" />
            <span x-show="!compact">Collapse</span>
        </button>
    </div>
</aside>
