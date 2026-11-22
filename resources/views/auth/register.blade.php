<x-auth.layout title="Buat Superadmin pertama" description="Lengkapi data diri Anda untuk bergabung di ruang kerja PT Flores Lautan Abadi.">
    <form novalidate method="POST" action="{{ route('register.store') }}" enctype="multipart/form-data" class="flex flex-col gap-5" x-data="{ submitting: false, photoUrl: null }" x-on:submit="submitting = true">
        <x-form-errors id="register-1-errors" />
        @csrf
        <div class="flex items-center gap-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
            <span class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-50 dark:bg-blue-950/40 text-blue-500 dark:text-blue-400">
                <img x-cloak x-show="photoUrl" x-bind:src="photoUrl" alt="Pratinjau foto profil" class="size-full object-cover">
                <span x-show="!photoUrl"><x-dashboard.icon name="anchor" class="size-7" /></span>
            </span>
            <div class="min-w-0">
                <label for="profile_picture" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Foto profil <span class="font-normal text-slate-400">(opsional)</span></label>
                <input id="profile_picture" name="profile_picture" type="file" data-max-file-kb="4096" data-max-image-dimension="4096" data-compress-photo accept="image/jpeg,image/png,image/webp" aria-describedby="photo-help{{ $errors->has('profile_picture') ? ' profile_picture-error' : '' }}" aria-invalid="{{ $errors->has('profile_picture') ? 'true' : 'false' }}" class="block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-2 file:cursor-pointer file:rounded-md file:border-0 file:bg-blue-50 dark:file:bg-blue-950/40 file:px-2.5 file:py-1.5 file:font-medium file:text-blue-700 dark:file:text-blue-300 hover:file:bg-blue-100 dark:hover:file:bg-blue-950/40" x-on:change="if (photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                <p id="photo-help" class="mt-2 text-[11px] leading-5 text-slate-400">JPG, PNG, WebP · Maks. 4 MB / 4096 × 4096 px</p>
                
            </div>
        </div>
        <x-auth.input name="fullname" label="Nama lengkap" autocomplete="name" maxlength="255" required autofocus placeholder="Nama lengkap Anda" />
        <div class="grid gap-5 sm:grid-cols-2">
            <x-auth.input name="username" label="Username" autocomplete="username" minlength="3" maxlength="50" required placeholder="contoh: andi.pratama" hint="3–50 karakter: huruf, angka, titik, atau _." />
            <x-auth.input name="phone" label="Nomor telepon" type="tel" autocomplete="tel" maxlength="30" placeholder="081234567890" />
        </div>
        <x-auth.input name="email" label="Email" type="email" autocomplete="email" maxlength="255" required placeholder="nama@perusahaan.com" />
        <div class="grid gap-5 sm:grid-cols-2">
            <x-auth.input name="password" label="Password" type="password" autocomplete="new-password" required :minlength="app()->isProduction() ? 12 : 8" :hint="app()->isProduction() ? 'Minimal 12 karakter: huruf besar, kecil, angka, dan simbol.' : 'Minimal 8 karakter.'" />
            <x-auth.input name="password_confirmation" label="Konfirmasi password" type="password" autocomplete="new-password" required />
        </div>
        <button type="submit" x-bind:disabled="submitting" class="flex w-full items-center justify-center rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60">
            <span x-show="!submitting">Buat akun Superadmin</span><span x-cloak x-show="submitting">Membuat akun…</span>
        </button>
    </form>
    <p class="mt-7 text-center text-sm text-slate-500 dark:text-slate-400">Sudah punya akun? <a href="{{ route('login') }}" class="font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">Masuk</a></p>
</x-auth.layout>
