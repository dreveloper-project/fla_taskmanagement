@props(['title', 'description', 'centered' => false])

@component('layouts.app', ['title' => $title, 'forcedTheme' => request()->routeIs('login') ? 'light' : null])
    @if ($centered)
        <main class="flex min-h-dvh flex-col items-center justify-center bg-white px-6 py-4 sm:px-10 sm:py-6">
            <div class="w-full max-w-sm">
                <header class="flex flex-col items-center text-center">
                    <img src="{{ asset('logo-fla.png') }}" alt="Logo FLA" width="64" height="64" class="size-16 object-contain motion-safe:transition-transform motion-safe:duration-300 motion-safe:hover:scale-105" />
                    <p class="mt-2 text-sm font-bold tracking-wide text-slate-800">PT. FLORES LAUTAN ABADI</p>
                    <p class="mt-1 text-xs font-medium tracking-widest text-slate-400 uppercase">Portal staf</p>
                    <div class="my-3 h-1 w-8 rounded-full bg-blue-600"></div>
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
                    <p class="mt-2 max-w-xs text-sm leading-5 text-slate-500">{{ $description }}</p>
                </header>
                <div class="mt-5">{{ $slot }}</div>
                <footer class="mt-5 border-t border-slate-100 pt-3 text-center text-xs leading-5 text-slate-400">
                    &copy; {{ now()->year }} PT. Flores Lautan Abadi
                </footer>
            </div>
        </main>
    @else
    <div class="grid min-h-dvh lg:grid-cols-5">
        <aside class="auth-brand-panel relative hidden flex-col justify-between overflow-hidden bg-[#293638] p-12 text-white lg:col-span-2 lg:flex">
            <div class="flex items-center gap-3">
                <img src="{{ asset('logo-fla-white.png') }}" alt="" width="48" height="48" class="size-12 shrink-0 object-contain" />
                <p class="text-2xl font-semibold">PT. FLORES LAUTAN ABADI</p>
            </div>
            <div class="flex flex-col gap-6 py-16">
                <span class="w-fit rounded-full border border-white/15 px-3 py-1 text-xs text-blue-200">PT Flores Lautan Abadi workspace</span>
                <h2 class="max-w-md text-4xl leading-tight font-semibold tracking-tight">Semua operasional.<br>Satu ruang kerja.</h2>
                <p class="max-w-sm text-sm leading-7 text-slate-300">Pantau tugas, kelola kapal, dan jaga kelancaran layanan maritim bersama tim FLA.</p>
                <div class="mt-5 border-t border-white/15 pt-6 text-xs text-slate-400">PT. FLORES LAUTAN ABADI</div>
            </div>
            <p class="text-xs text-slate-400">Keeping your operations on course.</p>
        </aside>
        <main class="flex items-center justify-center px-5 py-10 sm:px-10 lg:col-span-3 lg:py-12">
            <div class="w-full max-w-lg">
                <div class="mb-8 flex items-center gap-3 text-lg font-semibold lg:hidden">
                    <img src="{{ asset('logo-fla.png') }}" alt="" width="48" height="48" class="size-12 shrink-0 object-contain dark:hidden" />
                    <img src="{{ asset('logo-fla-white.png') }}" alt="" width="48" height="48" class="hidden size-12 shrink-0 object-contain dark:block" />
                    PT. FLORES LAUTAN ABADI
                </div>
                <p class="mb-2 text-xs font-semibold tracking-widest text-blue-600 dark:text-blue-400 uppercase">Portal staf</p>
                <h1 class="text-3xl font-semibold tracking-tight">{{ $title }}</h1>
                <p class="mt-3 text-sm leading-6 text-slate-500 dark:text-slate-400">{{ $description }}</p>
                <div class="mt-8">{{ $slot }}</div>
            </div>
        </main>
    </div>
    @endif
@endcomponent
