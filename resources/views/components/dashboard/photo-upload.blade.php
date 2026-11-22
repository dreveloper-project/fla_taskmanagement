@props(['photo' => null, 'currentUrl' => null, 'removed' => false, 'label' => 'Foto profil', 'dropzone' => false])

@php
    $hasSelection = $photo && ! $errors->has('profilePhoto');
    $preview = $hasSelection ? $photo->temporaryUrl() : ($removed ? null : $currentUrl);
@endphp
<div @if ($dropzone) data-file-dropzone tabindex="0" @endif class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:border-slate-700 dark:bg-slate-800/40">
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <label for="profile-photo" class="text-sm font-semibold">{{ $label }}</label>
        <span class="rounded-full bg-white px-2 py-0.5 text-[10px] text-slate-500 dark:bg-slate-900 dark:text-slate-400">Opsional</span>
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <div @class(['flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed', 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/40' => $preview, 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900' => ! $preview])>
            @if ($preview)
                <img src="{{ $preview }}" alt="Pratinjau {{ strtolower($label) }}" class="size-full object-cover" />
            @else
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-8 text-slate-400"><rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/></svg>
            @endif
        </div>
        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <label for="profile-photo" class="relative inline-flex cursor-pointer items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 focus-within:ring-2 focus-within:ring-blue-500 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-900/50">
                    <x-dashboard.icon name="plus" class="size-3.5" />
                    {{ $preview ? 'Ganti foto' : 'Pilih foto' }}
                    <input id="profile-photo" name="profilePhoto" type="file" wire:model="profilePhoto" accept="image/jpeg,image/png,image/webp" data-max-file-kb="4096" data-max-image-dimension="4096" data-compress-photo aria-describedby="profile-photo-hint profile-photo-error" class="absolute inset-0 size-full cursor-pointer opacity-0" />
                </label>
                @if ($photo || ($currentUrl && ! $removed))
                    <button type="button" wire:click="removeProfilePhoto" class="rounded-lg px-2 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950/40">Hapus foto</button>
                @elseif ($removed && $currentUrl)
                    <button type="button" wire:click="$set('removePhoto', false)" class="rounded-lg px-2 py-2 text-xs font-medium text-blue-600 dark:text-blue-300">Batalkan penghapusan</button>
                @endif
            </div>
            <p id="profile-photo-hint" class="text-[11px] leading-5 text-slate-500 dark:text-slate-400">JPG, PNG, atau WebP. Maksimal 4 MB.</p>
            @if ($dropzone)<p class="text-xs text-slate-500 dark:text-slate-400">Seret foto ke kotak ini atau fokus pada kotak lalu paste file (Ctrl+V).</p>@endif
        </div>
    </div>
    @if ($hasSelection)
        <p class="mt-3 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><x-dashboard.icon name="check" class="size-3.5 shrink-0" /><span class="min-w-0 break-all">{{ $photo->getClientOriginalName() }}<span class="mt-0.5 block text-[10px]">Foto siap disimpan</span></span></p>
    @elseif ($removed)
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Foto akan dihapus saat perubahan disimpan.</p>
    @endif
    <p wire:loading wire:target="profilePhoto" role="status" class="mt-3 text-xs text-blue-600 dark:text-blue-300">Mengunggah foto...</p>
    @error('profilePhoto')<p id="profile-photo-error" role="alert" class="mt-3 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p>@enderror
</div>
