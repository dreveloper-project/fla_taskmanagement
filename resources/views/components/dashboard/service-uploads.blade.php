@props(['types', 'prefix' => 'service', 'model' => 'documents', 'maxKb' => 2048, 'existing' => null, 'selected' => [], 'dropzone' => false, 'disabledTypes' => []])
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($types as $type => $label)
        @php
            $saved = $existing[$type] ?? null;
            $available = $saved['available'] ?? false;
            $file = $selected[$type] ?? null;
            $hasSelection = $file instanceof \Illuminate\Http\UploadedFile && ! $errors->has($model.'.'.$type);
            $isDisabled = in_array($type, $disabledTypes, true);
        @endphp
        <div wire:key="{{ $prefix }}-upload-{{ $model }}-{{ $type }}"
            x-data="{ uploading: false, progress: 0, failed: false, ready: false, fileName: '' }"
            x-on:change="failed = false; ready = false"
            x-on:livewire-upload-start="fileName = $event.target.files[0]?.name || ''; uploading = true; progress = 0; failed = false; ready = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false; progress = 100; ready = true"
            x-on:livewire-upload-error="uploading = false; failed = true; ready = false"
            x-on:livewire-upload-cancel="uploading = false; progress = 0; ready = false"
            @class([
            'rounded-xl border p-4 transition-colors motion-reduce:transition-none',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500' => $dropzone,
            'border-emerald-200 bg-emerald-50/50 dark:border-emerald-800 dark:bg-emerald-950/20' => $available,
            'border-amber-200 bg-amber-50/50 dark:border-amber-800 dark:bg-amber-950/20' => $saved && ! $available,
            'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' => ! $saved,
        ]) @if ($dropzone && ! $isDisabled) data-file-dropzone tabindex="0" @endif data-document-state="{{ $existing === null ? 'untracked' : ($available ? 'saved' : ($saved ? 'missing' : 'empty')) }}">
            <label for="{{ $prefix }}-file-{{ $type }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
            @if ($existing !== null)
                <div class="mb-3">
                    @if ($available)
                        <p class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300"><x-dashboard.icon name="check" class="size-3" />Sudah diunggah</p>
                        <p class="mt-2 break-all text-xs text-slate-600 dark:text-slate-300">{{ $saved['filename'] }}</p>
                        <x-dashboard.download-button :href="$saved['url']" :aria-label="'Unduh '.$label" class="mt-2">Unduh dokumen tersimpan</x-dashboard.download-button>
                    @elseif ($saved)
                        <p class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">File tidak tersedia</p>
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">Unggah ulang dokumen ini.</p>
                    @else
                        <p class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">Belum diunggah</p>
                    @endif
                </div>
            @endif
            <label for="{{ $prefix }}-file-{{ $type }}" class="mb-2 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ $available ? 'Ganti dokumen' : 'Pilih dokumen' }}</label>
            @if ($isDisabled)<p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Tidak diperlukan karena penumpang nihil.</p>@elseif ($dropzone)<p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Seret file ke kotak ini atau fokus pada kotak lalu paste file (Ctrl+V).</p>@endif
            <div wire:ignore wire:key="{{ $prefix }}-file-control-{{ $model }}-{{ $type }}-{{ $isDisabled ? 'disabled' : 'enabled' }}">
                <input id="{{ $prefix }}-file-{{ $type }}" type="file" wire:model="{{ $model }}.{{ $type }}" x-bind:disabled="uploading || {{ $isDisabled ? 'true' : 'false' }}" @disabled($isDisabled)
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx" data-max-file-kb="{{ $maxKb }}"
                    aria-describedby="{{ $prefix }}-file-hint-{{ $type }} {{ $model }}.{{ $type }}-error"
                    class="block min-h-11 w-full min-w-0 cursor-pointer rounded-lg border border-slate-200 bg-slate-50 text-xs text-slate-600 file:mr-3 file:min-h-11 file:cursor-pointer file:border-0 file:border-r file:border-slate-200 file:bg-white file:px-3 file:font-semibold file:text-blue-700 hover:file:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-wait disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300 dark:file:border-slate-700 dark:file:bg-slate-800 dark:file:text-blue-300 dark:hover:file:bg-slate-700" />
            </div>
            <div x-show="uploading" style="display: none" role="status" class="mt-3 space-y-2">
                <p x-text="fileName" class="break-all text-xs text-slate-600 dark:text-slate-300"></p>
                <p class="flex items-center justify-between gap-2 text-xs text-blue-600 dark:text-blue-400"><span>Mengunggah file…</span><span x-text="progress + '%'" class="tabular-nums"></span></p>
                <progress max="100" x-bind:value="progress" aria-label="Progres unggah dokumen" class="block h-1.5 w-full overflow-hidden rounded-full [&::-webkit-progress-bar]:bg-slate-100 [&::-webkit-progress-value]:bg-blue-500 [&::-moz-progress-bar]:bg-blue-500 dark:[&::-webkit-progress-bar]:bg-slate-800"></progress>
            </div>
            @if ($hasSelection)
                <p x-show="!uploading && !failed" class="mt-3 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700 dark:bg-blue-950/50 dark:text-blue-300"><span class="block font-semibold">File baru dipilih · Siap disimpan</span><span class="mt-1 block break-all">{{ $file->getClientOriginalName() }}</span></p>
            @else
                <p x-show="ready && !uploading && !failed" style="display: none" class="mt-3 text-xs font-medium text-emerald-700 dark:text-emerald-300">Unggahan selesai. File siap disimpan.</p>
            @endif
            <p x-show="failed" style="display: none" class="mt-3 text-xs text-rose-600 dark:text-rose-300">Unggahan gagal. Pilih ulang file untuk mencoba kembali.</p>
            <p id="{{ $prefix }}-file-hint-{{ $type }}" class="mt-2 text-[10px] leading-5 text-slate-500 dark:text-slate-400">PDF, JPG, JPEG, PNG, WebP, GIF, DOC, atau DOCX. Maksimal {{ $maxKb / 1024 }} MB. {{ $available ? 'Pengganti berlaku setelah disimpan.' : 'File tersimpan setelah form disimpan.' }}</p>
        </div>
    @endforeach
</div>
