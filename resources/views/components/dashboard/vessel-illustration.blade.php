@props(['type' => 'passenger'])

<svg {{ $attributes->class(['h-28 w-full']) }} viewBox="0 0 320 120" fill="none" aria-hidden="true">
    <circle cx="235" cy="42" r="31" fill="currentColor" opacity=".05" />
    <path d="M25 87h270M43 97h224M71 107h165" stroke="currentColor" stroke-opacity=".12" stroke-linecap="round" />
    <path d="M50 69h179l40-8-21 27H76L50 69Z" fill="currentColor" opacity=".9" />
    <path d="M70 82h179l-5 6H76l-6-6Z" fill="currentColor" opacity=".35" />
    <path d="M93 48h48v21H93z" fill="white" /><path d="M93 48h48v21H93z" stroke="currentColor" stroke-width="1.5" />
    <path d="M102 37h26v11h-26z" fill="currentColor" opacity=".3" />
    <path d="M113 37V24m0 2h14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
    <path d="M102 56h5m6 0h5m6 0h5" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    @if ($type === 'tanker')
        <rect x="152" y="47" width="29" height="22" rx="7" fill="currentColor" opacity=".25" />
        <rect x="188" y="47" width="29" height="22" rx="7" fill="currentColor" opacity=".25" />
        <path d="M146 63h79m-58-21v5m36-5v5" stroke="currentColor" stroke-width="1.5" />
    @elseif ($type === 'cargo')
        <path d="M149 49h32v20h-32zM185 49h32v20h-32zM167 30h32v16h-32z" fill="currentColor" opacity=".25" />
        <path d="M156 53v12m8-12v12m9-12v12m19-12v12m8-12v12m9-12v12m-35-31v8m8-8v8m9-8v8" stroke="currentColor" stroke-opacity=".35" />
    @else
        <path d="M143 48h64l17 21h-81V48Z" fill="white" stroke="currentColor" stroke-width="1.5" />
        <path d="M144 37h48l15 11h-63V37Z" fill="currentColor" opacity=".2" />
        <path d="M151 57h5m7 0h5m7 0h5m7 0h5m7 0h5" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
    @endif
    <circle cx="239" cy="73" r="2" fill="white" />
    <path d="M34 89c9-6 13 6 22 0s13 6 22 0m160 1c9-6 13 6 22 0s13 6 22 0" stroke="currentColor" stroke-opacity=".35" stroke-width="1.5" stroke-linecap="round" />
</svg>
