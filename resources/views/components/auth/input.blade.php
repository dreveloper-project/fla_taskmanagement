@props(['name', 'label', 'type' => 'text', 'hint' => null, 'value' => null])

<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ $label }}</label>
    @if (in_array($type, ['date', 'datetime-local'], true))
        <x-date-time-input :name="$name" :label="$label" :type="$type" :value="$value" :attributes="$attributes->merge(['aria-invalid' => $errors->has($name) ? 'true' : 'false', 'aria-describedby' => $hint ? $name.'-hint' : ''])" />
    @else
    <x-password-field :enabled="$type === 'password'" :input-id="$name">
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type === 'password') x-bind:type="passwordVisible ? 'text' : 'password'" @endif
        @if ($type !== 'password') value="{{ is_scalar(old($name, $value)) ? old($name, $value) : '' }}" @endif
        aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}"
        @if ($errors->has($name)) aria-describedby="{{ $name }}-error" @elseif ($hint) aria-describedby="{{ $name }}-hint" @endif
        {{ $attributes->class(['block w-full rounded-xl border bg-white dark:bg-slate-900 px-3.5 py-3 text-sm shadow-xs outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20', 'pr-12' => $type === 'password', 'border-rose-400' => $errors->has($name), 'border-slate-200 dark:border-slate-700' => ! $errors->has($name)]) }}
    >
    </x-password-field>
    @endif
    @if ($hint)<p id="{{ $name }}-hint" class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $hint }}</p>@endif
    
</div>
