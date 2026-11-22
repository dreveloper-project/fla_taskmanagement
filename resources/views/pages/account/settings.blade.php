@component('layouts.app', ['title' => 'Pengaturan Akun'])
    <x-dashboard.shell page="Pengaturan Akun">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
            <div>
                <p class="mb-1.5 text-xs text-slate-500 dark:text-slate-400">Akun pribadi</p>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-[28px]">Pengaturan akun</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Kelola informasi profil dan keamanan akun Anda.</p>
            </div>
            <a href="{{ route('home') }}" class="w-fit rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2.5 text-xs font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Kembali ke dashboard</a>
        </div>

        <div class="mt-7 grid items-start gap-6 xl:grid-cols-3">
            <section aria-labelledby="profile-heading" class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 shadow-xs sm:p-7 xl:col-span-2">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 dark:border-slate-700 pb-5">
                    <div>
                        <h2 id="profile-heading" class="text-base font-semibold">Informasi profil</h2>
                        <p class="mt-1.5 text-xs leading-5 text-slate-500 dark:text-slate-400">Perbarui data diri dan foto yang tampil di ruang kerja.</p>
                    </div>
                    <span class="rounded-full bg-blue-50 dark:bg-blue-950/40 px-3 py-1 text-xs font-medium text-blue-600 dark:text-blue-400">{{ $user->roleLabel() }}</span>
                </div>

                @if (session('profile_status'))
                    <p data-form-success role="status" class="mt-5 rounded-lg border border-emerald-100 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">{{ session('profile_status') }}</p>
                @endif

                <form novalidate method="POST" action="{{ route('account.update') }}" enctype="multipart/form-data" class="mt-6 flex flex-col gap-5" x-data="{ submitting: false, photoUrl: null, removePhoto: @js((bool) old('remove_picture', false)) }" x-on:submit="submitting = true">
        <x-form-errors id="account-profile-errors" :exclude="['current_password', 'password', 'password_confirmation']" />
                    @csrf
                    @method('PATCH')
                    <div class="flex flex-col gap-4 rounded-xl bg-slate-50 dark:bg-slate-800 p-4 sm:flex-row sm:items-center">
                        <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-100 dark:bg-blue-950/40 text-xl font-semibold text-blue-700 dark:text-blue-300">
                            <img x-cloak x-show="photoUrl" x-bind:src="photoUrl" alt="Pratinjau foto baru" class="size-full object-cover">
                            <div x-show="!photoUrl && !removePhoto" class="flex size-full items-center justify-center">
                                <x-auth.avatar :user="$user" class="size-full text-xl" />
                            </div>
                            <span x-cloak x-show="!photoUrl && removePhoto">{{ $user->initials() }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <label for="profile_picture" class="mb-2 block text-sm font-medium">Foto profil</label>
                            <input id="profile_picture" name="profile_picture" type="file" data-max-file-kb="4096" data-max-image-dimension="4096" data-compress-photo accept="image/jpeg,image/png,image/webp" aria-invalid="{{ $errors->has('profile_picture') ? 'true' : 'false' }}" aria-describedby="photo-help{{ $errors->has('profile_picture') ? ' profile_picture-error' : '' }}" class="block w-full text-xs text-slate-500 dark:text-slate-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-white dark:file:bg-slate-900 file:px-3 file:py-2 file:font-medium file:text-blue-600 dark:file:text-blue-400 hover:file:bg-blue-50 dark:hover:file:bg-blue-950/40" x-on:change="if (photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; if (photoUrl) removePhoto = false">
                            <p id="photo-help" class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">JPG, PNG, atau WebP. Maksimal 4 MB dan 4096 × 4096 piksel.</p>
                            
                            @if ($user->profile_picture_path)
                                <label class="mt-3 flex w-fit items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" name="remove_picture" value="1" x-model="removePhoto" x-bind:disabled="!!photoUrl" class="size-4 rounded border-slate-300 dark:border-slate-700 accent-blue-600">
                                    Hapus foto saat disimpan
                                </label>
                            @endif
                            
                        </div>
                    </div>
                    <x-auth.input name="fullname" label="Nama lengkap" :value="$user->name" autocomplete="name" maxlength="255" required />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-auth.input name="username" label="Username" :value="$user->username" autocomplete="username" minlength="3" maxlength="50" required hint="Gunakan username ini untuk login." />
                        <x-auth.input name="phone" label="Nomor telepon" :value="$user->phone" type="tel" autocomplete="tel" maxlength="30" required />
                    </div>
                    <x-auth.input name="email" label="Email" :value="$user->email" type="email" autocomplete="email" maxlength="255" required />
                    <div class="flex justify-end border-t border-slate-100 dark:border-slate-700 pt-5">
                        <button type="submit" x-bind:disabled="submitting" class="w-full rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                            <span x-show="!submitting">Simpan perubahan</span><span x-cloak x-show="submitting">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </section>

            <section aria-labelledby="password-heading" class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 shadow-xs sm:p-7">
                <div class="border-b border-slate-100 dark:border-slate-700 pb-5">
                    <h2 id="password-heading" class="text-base font-semibold">Ubah password</h2>
                    <p class="mt-1.5 text-xs leading-5 text-slate-500 dark:text-slate-400">Konfirmasi password saat ini untuk menggantinya dengan password baru.</p>
                </div>
                @if (session('password_status'))
                    <p data-form-success role="status" class="mt-5 rounded-lg border border-emerald-100 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300">{{ session('password_status') }}</p>
                @endif
                <form novalidate method="POST" action="{{ route('account.password.update') }}" class="mt-6 flex flex-col gap-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
        <x-form-errors id="account-password-errors" :fields="['current_password', 'password', 'password_confirmation']" />
                    @csrf
                    @method('PUT')
                    <x-auth.input name="current_password" label="Password saat ini" type="password" autocomplete="current-password" required />
                    <x-auth.input name="password" label="Password baru" type="password" autocomplete="new-password" required :minlength="app()->isProduction() ? 12 : 8" :hint="app()->isProduction() ? 'Minimal 12 karakter: huruf besar, kecil, angka, dan simbol.' : 'Minimal 8 karakter.'" />
                    <x-auth.input name="password_confirmation" label="Konfirmasi password baru" type="password" autocomplete="new-password" required />
                    <div class="border-t border-slate-100 dark:border-slate-700 pt-5">
                        <button type="submit" x-bind:disabled="submitting" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-5 py-3 text-sm font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            <span x-show="!submitting">Perbarui password</span><span x-cloak x-show="submitting">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </x-dashboard.shell>
@endcomponent
