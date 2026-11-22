@props(['form', 'vessel' => null])

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2"><x-auth.input name="form.vessel_name" label="Nama kapal *" wire:model="form.vessel_name" :value="$form['vessel_name'] ?? ''" required maxlength="255" placeholder="Contoh: KM. Nusantara" /></div>

    @foreach (['vessel_type' => ['Jenis kapal *', \App\Models\Vessel::TYPES], 'operational_status' => ['Status kapal *', \App\Models\Vessel::STATUSES]] as $field => [$label, $options])
        <div wire:key="vessel-select-{{ $field }}">
            <label for="vessel-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
            <select id="vessel-{{ $field }}" wire:model="form.{{ $field }}" required aria-describedby="form.{{ $field }}-error" aria-invalid="{{ $errors->has('form.'.$field) ? 'true' : 'false' }}" class="block w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3.5 py-3 text-sm">
                <option value="">Pilih {{ $label }}</option>
                @foreach ($options as $value => $option)
                    <option wire:key="{{ $field }}-{{ $value }}" value="{{ $value }}">{{ $option }}</option>
                @endforeach
            </select>
            @if ($field === 'vessel_type' && $vessel && ! isset(\App\Models\Vessel::TYPES[$vessel->vessel_type]))
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Jenis sebelumnya: {{ $vessel->typeLabel() }}. Pilih Kapal Barang atau Kapal Penumpang untuk menyimpan perubahan.</p>
            @endif
        </div>
    @endforeach

    <div class="sm:col-span-2">
        <x-auth.input name="form.owner" label="Pemilik / operator (opsional)" wire:model="form.owner" :value="$form['owner'] ?? ''" maxlength="255" />
    </div>

    <x-auth.input name="form.passenger_capacity" label="Kapasitas penumpang (orang, opsional)" type="number" wire:model="form.passenger_capacity" :value="$form['passenger_capacity'] ?? ''" min="0" max="1000000" step="1" />
    <p class="text-xs text-slate-500 dark:text-slate-400 sm:col-span-2">Nakhoda dan jumlah crew mengikuti awak aktif yang dicatat melalui Sijil.</p>
    <x-auth.input name="form.flag" label="Flag / Bendera *" wire:model="form.flag" :value="$form['flag'] ?? ''" required maxlength="255" />
    <x-dashboard.port-input name="form.port_of_registry" label="Port of Registry (opsional)" wire:model="form.port_of_registry" :value="$form['port_of_registry'] ?? ''" maxlength="255" />
    @foreach (['registry_number' => 'Registry Number', 'call_sign' => 'Call sign'] as $field => $label)
        <div wire:key="vessel-text-{{ $field }}"><x-auth.input name="form.{{ $field }}" :label="$label.' (opsional)'" wire:model="form.{{ $field }}" :value="$form[$field] ?? ''" :maxlength="$field === 'call_sign' ? 50 : 255" /></div>
    @endforeach
    @foreach (['gross_tonnage' => 'Gross Tonnage (GT)', 'net_tonnage' => 'Net Tonnage (NT)', 'loa' => 'LOA / Panjang kapal (m)', 'breadth' => 'Breadth / Lebar kapal (m)', 'draft' => 'Draft (m)'] as $field => $label)
        <div wire:key="vessel-number-{{ $field }}"><x-auth.input name="form.{{ $field }}" :label="$label.' (opsional)'" type="number" wire:model="form.{{ $field }}" :value="$form[$field] ?? ''" min="0" step="0.01" /></div>
    @endforeach
    <x-auth.input name="form.build_year" label="Tahun pembuatan (opsional)" type="number" wire:model="form.build_year" :value="$form['build_year'] ?? ''" min="1000" :max="now()->year" step="1" />
</div>
