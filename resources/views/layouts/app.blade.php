<!DOCTYPE html>
<html lang="id" data-forced-theme="{{ $forcedTheme ?? '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#293638">
        <script>
            (() => {
                let theme;
                try { theme = localStorage.getItem('fla-theme'); } catch {}
                theme = document.documentElement.getAttribute('data-forced-theme') || theme;
                if (!['light', 'dark'].includes(theme)) theme = 'light';
                const dark = theme === 'dark';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>
        <title>{{ $title ?? 'Overview' }} · PT Flores Lautan Abadi</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body data-forced-theme="{{ $forcedTheme ?? '' }}" class="bg-[#f5f6f8] font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100">
        {{ $slot }}
        @persist('page-loading')
            <x-page-loading />
        @endpersist
        <x-form-loading />
        <x-archive-download-progress />
        <x-form-notifications />
        @livewireScripts
    </body>
</html>
