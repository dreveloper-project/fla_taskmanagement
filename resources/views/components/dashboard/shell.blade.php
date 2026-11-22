@props(['page' => 'Overview', 'demo' => true])

<div
    x-data="{ sidebarOpen: false, desktop: window.matchMedia('(min-width: 1024px)').matches }"
    x-on:resize.window="desktop = window.matchMedia('(min-width: 1024px)').matches; if (desktop) sidebarOpen = false"
    x-on:keydown.escape.window="sidebarOpen = false"
    class="min-h-dvh"
>
    <a href="#main-content" class="sr-only z-50 rounded-lg bg-white dark:bg-slate-900 p-3 text-blue-700 dark:text-blue-300 focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Lewati ke konten utama</a>

    <div x-cloak x-show="sidebarOpen && !desktop" x-transition.opacity x-on:click="sidebarOpen = false" class="fixed inset-0 z-30 bg-slate-950/45 backdrop-blur-xs" aria-hidden="true"></div>

    <aside
        id="dashboard-sidebar"
        aria-label="Navigasi dashboard"
        x-bind:inert="!desktop && !sidebarOpen"
        x-trap.inert.noscroll="sidebarOpen && !desktop"
        x-bind:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        x-bind:data-open="sidebarOpen"
        class="fixed inset-y-0 left-0 z-40 flex w-64 max-w-[85vw] -translate-x-full flex-col border-r border-slate-200 bg-white text-slate-800 transition-[translate,background-color,color,border-color] duration-300 ease-out motion-reduce:transition-none lg:translate-x-0 dark:border-slate-800 dark:bg-slate-900 dark:text-white"
    >
        <div class="flex h-20 shrink-0 items-center justify-between px-4">
            <a href="{{ route('home') }}" class="sidebar-brand flex items-center gap-3" aria-label="PT Flores Lautan Abadi — Overview">
                <span class="sidebar-brand-light hidden shrink-0"><img src="{{ asset('logo-fla.png') }}" alt="" width="48" height="48" class="size-12 object-contain" /></span>
                <span class="sidebar-brand-dark hidden shrink-0"><img src="{{ asset('logo-fla-white.png') }}" alt="" width="48" height="48" class="size-12 object-contain" /></span>
                <span class="sidebar-brand-default flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-500 text-white"><x-dashboard.icon name="anchor" class="size-6" /></span>
                <span class="sidebar-brand-default"><span class="block text-xl leading-6 font-semibold tracking-tight">FLA<span class="text-blue-400">.</span></span><span class="block text-[9px] tracking-[0.23em] text-slate-500 dark:text-slate-300 uppercase">Flores Lautan Abadi</span></span>
                <span class="sidebar-brand-light sidebar-brand-dark hidden text-xs leading-4 font-semibold text-slate-800 dark:text-white">PT. Flores<br />Lautan Abadi</span>
            </a>
            <button type="button" x-on:click="sidebarOpen = false" class="rounded-md p-2 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 lg:hidden" aria-label="Tutup navigasi"><x-dashboard.icon name="close" /></button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Menu utama">
            <p class="px-3 pb-3 text-[10px] font-medium tracking-[0.15em] text-slate-500 dark:text-slate-400 uppercase">Workspace</p>
            <livewire:dashboard.overview-navigation :active="$page === 'Overview'" :key="'overview-navigation-'.($page === 'Overview' ? 'active' : 'inactive')" />

            <p class="px-3 pt-7 pb-3 text-[10px] font-medium tracking-[0.15em] text-slate-500 dark:text-slate-400 uppercase">Operations</p>
            <details open class="group">
                <summary class="flex list-none items-center gap-3 rounded-lg px-3 py-3 text-[13px] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
                    <x-dashboard.icon name="document" data-sidebar-icon="services" /> Layanan <x-dashboard.icon name="chevron" class="ml-auto size-3.5 transition-transform group-open:rotate-90 motion-reduce:transition-none" />
                </summary>
                <div class="ml-5 border-l border-slate-200 dark:border-white/15 py-1 pl-4">
                    <a href="{{ route('spb.index') }}" @if (request()->routeIs('spb.*')) aria-current="page" @endif @class(['block rounded-lg px-2 py-2.5 text-xs leading-5', 'bg-blue-500 text-white' => request()->routeIs('spb.*'), 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! request()->routeIs('spb.*')])>Surat Persetujuan Berlayar</a>
                    <a href="{{ route('safety-certificates.index') }}" @if (request()->routeIs('safety-certificates.*')) aria-current="page" @endif @class(['block rounded-lg px-2 py-2.5 text-xs leading-5', 'bg-blue-500 text-white' => request()->routeIs('safety-certificates.*'), 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! request()->routeIs('safety-certificates.*')])>Sertifikat Keselamatan Kapal Penumpang</a>
                    <a href="{{ route('bunker.index') }}" @if (request()->routeIs('bunker.*')) aria-current="page" @endif @class(['block rounded-lg px-2 py-2.5 text-xs leading-5', 'bg-blue-500 text-white' => request()->routeIs('bunker.*'), 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! request()->routeIs('bunker.*')])>Izin Bunker</a>
                    <a href="{{ route('sijil.index') }}" @if (request()->routeIs('sijil.*')) aria-current="page" @endif @class(['block rounded-lg px-2 py-2.5 text-xs leading-5', 'bg-blue-500 text-white' => request()->routeIs('sijil.*'), 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! request()->routeIs('sijil.*')])>Sijil Awak Kapal</a>
                    <a href="{{ route('ship-nationality.index') }}" @if (request()->routeIs('ship-nationality.*')) aria-current="page" @endif @class(['block rounded-lg px-2 py-2.5 text-xs leading-5', 'bg-blue-500 text-white' => request()->routeIs('ship-nationality.*'), 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! request()->routeIs('ship-nationality.*')])>Surat Laut / Pas Besar / Pas Kecil</a>
                </div>
            </details>
            <a href="{{ route('dashboard.vessel') }}" @if ($page === 'Vessel') aria-current="page" @endif @class(['mt-1 flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] font-medium', 'bg-blue-500 text-white shadow-sm shadow-blue-950/10' => $page === 'Vessel', 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => $page !== 'Vessel'])>
                <x-dashboard.icon name="ship" data-sidebar-icon="vessel" /> Vessel @if ($page === 'Vessel') <span class="ml-auto size-1.5 rounded-full bg-white"></span> @endif
            </a>
            @can('access-reports')
            <a href="{{ route('reports.index') }}" @if ($page === 'Reports') aria-current="page" @endif @class(['mt-1 flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] font-medium', 'bg-blue-500 text-white shadow-sm shadow-blue-950/10' => $page === 'Reports', 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => $page !== 'Reports'])><x-dashboard.icon name="chart" data-sidebar-icon="reports" /> Reports</a>
            @endcan
            @can('access-finance')
                <a href="{{ route('finance.index') }}" @if (request()->routeIs('finance.index')) aria-current="page" @endif @class(['mt-1 flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] font-medium', 'bg-blue-500 text-white shadow-sm shadow-blue-950/10' => request()->routeIs('finance.index'), 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5' => ! request()->routeIs('finance.index')])><x-dashboard.icon name="wallet" data-sidebar-icon="finance" /> Finance</a>
            @endcan
            @can('access-admin')
                <p class="px-3 pt-7 pb-3 text-[10px] font-medium tracking-[0.15em] text-slate-500 dark:text-slate-400 uppercase">Management</p>
                <a href="{{ route('staff.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5"><x-dashboard.icon name="clients" data-sidebar-icon="staff" /> Staff Management</a>
            @endcan
            <p class="px-3 pt-7 pb-3 text-[10px] font-medium tracking-[0.15em] text-slate-500 dark:text-slate-400 uppercase">Settings</p>
            <a href="{{ route('account.settings') }}" class="flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5"><x-dashboard.icon name="clients" data-sidebar-icon="settings" /> Account Settings</a>
        </nav>

        <div class="shrink-0 border-t border-slate-200 dark:border-white/10 p-5">
            <a href="{{ route('account.settings') }}" aria-label="Pengaturan akun" @if (request()->routeIs('account.settings')) aria-current="page" @endif @class(['flex items-center gap-3 rounded-lg p-2 -m-2 hover:bg-slate-100 dark:hover:bg-white/10', 'bg-slate-100 dark:bg-white/10' => request()->routeIs('account.settings')])>
                <x-auth.avatar :user="auth()->user()" />
                <div class="min-w-0"><p class="truncate text-xs font-medium">{{ auth()->user()->name }}</p><p class="mt-1 truncate text-[10px] text-slate-500 dark:text-slate-400">{{ auth()->user()->username }} · {{ auth()->user()->roleLabel() }}</p></div>
                <x-dashboard.icon name="chevron" class="ml-auto size-4 shrink-0 text-slate-500 dark:text-slate-400" />
            </a>
            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="w-full rounded-lg border border-slate-200 dark:border-white/15 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white">Keluar</button>
            </form>
        </div>
    </aside>

    <div class="min-w-0 lg:pl-64">
        <header class="flex min-h-17 items-center justify-between gap-4 border-b border-slate-200/70 dark:border-slate-700/70 bg-white dark:bg-slate-900 px-4 sm:px-7 lg:px-8">
            <div class="flex items-center gap-3">
                <button type="button" x-on:click="sidebarOpen = true" x-bind:aria-expanded="sidebarOpen" aria-controls="dashboard-sidebar" aria-label="Buka navigasi" class="rounded-lg p-2 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden"><x-dashboard.icon name="menu" /></button>
                <nav aria-label="Breadcrumb" class="flex items-center gap-3 text-xs">
                    <span class="hidden text-slate-400 sm:inline">Workspace</span><x-dashboard.icon name="chevron" class="hidden size-3 text-slate-400 sm:block" /><span class="font-medium text-slate-800 dark:text-slate-100">{{ $page }}</span>
                </nav>
            </div>
            <div class="flex shrink-0 items-center gap-2 sm:gap-4">
                <button type="button" data-theme-toggle aria-label="Tema terang. Ganti ke Tema gelap." title="Ganti ke Tema gelap" class="flex min-h-11 items-center gap-2 rounded-lg px-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">
                    <span aria-hidden="true" class="relative flex h-7 w-12 shrink-0 items-center justify-between rounded-full bg-slate-200 p-1 transition-colors duration-300 motion-reduce:transition-none dark:bg-slate-700">
                        <span class="theme-switch-thumb absolute top-1 left-1 size-5 rounded-full bg-white shadow-sm dark:bg-slate-100"></span>
                        <span class="relative flex size-5 items-center justify-center text-amber-600"><x-dashboard.icon name="sun" class="size-3.5" /></span>
                        <span class="relative flex size-5 items-center justify-center text-blue-600 dark:text-blue-400"><x-dashboard.icon name="moon" class="size-3.5" /></span>
                    </span>
                    <span data-theme-label aria-live="polite" class="hidden sm:inline">Tema terang</span>
                    <span class="sr-only">Pilihan tema: Tema terang, Tema gelap.</span>
                </button>
                <a href="{{ route('account.settings') }}" aria-label="Pengaturan akun" class="rounded-full"><x-auth.avatar :user="auth()->user()" class="size-8" /></a>
            </div>
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto max-w-400 px-4 py-6 sm:px-7 lg:px-8 lg:py-8">
            {{ $slot }}

            <footer class="mt-8 flex flex-wrap items-center justify-between gap-2 border-t border-slate-200/80 dark:border-slate-700/80 pt-4 text-[10px] text-slate-400"><span>© 2026 PT Flores Lautan Abadi. All rights reserved.</span><span class="flex items-center gap-1.5"><x-dashboard.icon name="anchor" class="size-3" /> Keeping your operations on course.</span></footer>
        </main>
    </div>
</div>
