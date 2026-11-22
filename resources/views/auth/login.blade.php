<x-auth.layout :centered="true" title="Selamat datang kembali" description="Masuk untuk melanjutkan ke ruang kerja Anda.">
    <form novalidate method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" x-data="{ submitting: false }" x-on:submit="submitting = true" x-bind:aria-busy="submitting">
        <x-form-errors id="login-1-errors" />
        @csrf
        <x-auth.input name="username" label="Username" autocomplete="username" maxlength="50" required autofocus placeholder="Masukkan username" class="transition-shadow duration-200 hover:border-slate-300" />
        <x-auth.input name="password" label="Password" type="password" autocomplete="current-password" required placeholder="Masukkan password" class="transition-shadow duration-200 hover:border-slate-300" />
        <label class="flex w-fit cursor-pointer items-center gap-2.5 text-sm text-slate-600 transition-colors hover:text-slate-900">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-slate-300 dark:border-slate-700 accent-blue-600">
            Ingat saya
        </label>
        <button type="submit" x-bind:disabled="submitting" class="group flex w-full items-center justify-center gap-2.5 rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus-visible:outline-offset-4 active:bg-blue-800 disabled:cursor-wait disabled:opacity-60">
            <span x-show="!submitting">Masuk</span><span x-cloak x-show="submitting">Sedang masuk…</span>
            <svg x-show="!submitting" class="size-4 motion-safe:transition-transform motion-safe:group-hover:translate-x-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
            <svg x-cloak x-show="submitting" class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" opacity=".25" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
        </button>
    </form>
    @if (! \App\Models\User::query()->exists())
        <p class="mt-4 text-center text-sm text-slate-500 dark:text-slate-400"><a href="{{ route('register') }}" class="font-semibold text-blue-600 dark:text-blue-400">Buat Admin pertama</a></p>
    @else
        <p class="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">Hubungi Admin untuk pembuatan akun Staff.</p>
    @endif
</x-auth.layout>
