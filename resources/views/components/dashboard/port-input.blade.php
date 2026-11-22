@props(['name', 'label', 'value' => null])

<x-auth.input :name="$name" :label="$label" :value="$value" :list="$name.'-port-options'" hint="Cari dan pilih lokasi, atau ketik lokasi lainnya." {{ $attributes }} />
<datalist id="{{ $name }}-port-options">
    @foreach (config('ports') as $port)
        <option value="{{ $port }}"></option>
    @endforeach
</datalist>
