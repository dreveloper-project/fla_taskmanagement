@props(['name'])

<svg {{ $attributes->class(['size-5 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('clients')
            <circle cx="9" cy="8" r="3" /><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3" />
            @break
        @case('attachment')
            <path d="m8 12 6-6a3 3 0 0 1 4 4l-8 8a5 5 0 0 1-7-7l9-9m-6 12 8-8" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" />
            @break
        @case('download')
            <path d="M12 3v12m-4-4 4 4 4-4M5 16v4a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4" />
            @break
        @case('edit')
            <path d="m15 5 4 4M4 20l4-1 12-12a2.8 2.8 0 0 0-4-4L4 15zM12 20h8" />
            @break
        @case('power')
            <path d="M12 3v9M6.3 5.7a9 9 0 1 0 11.4 0" />
            @break
        @case('overview')
            <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
            @break
        @case('tasks')
            <rect x="5" y="4" width="15" height="17" rx="2" /><path d="M9 3h7v3H9zM9 11l1 1 2-2m3 1h2m-8 6 1 1 2-2m3 1h2" />
            @break
        @case('document')
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8m-8 4h5" />
            @break
        @case('spreadsheet')
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6" /><rect x="8" y="12" width="8" height="6" rx=".5" /><path d="M8 15h8m-4-3v6" />
            @break
        @case('ship')
            <path d="M5 13V7h14v6M9 7V3h6v4M12 10v10M3 14l9-4 9 4-3 6M3 14l3 6M2 21c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2" />
            @break
        @case('anchor')
            <circle cx="12" cy="5" r="2" /><path d="M12 7v14M7 11h10M3 14v3c0 5 18 5 18 0v-3M1 16l2-2 2 2m14 0 2-2 2 2" />
            @break
        @case('chart')
            <path d="M4 3v18h17M8 16v-4m5 4V7m5 9v-6" />
            @break
        @case('wallet')
            <path d="M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v12H5a3 3 0 0 1-3-3V6M20 12h-5v5h5" /><path d="M16 14.5h.01" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            @break
        @case('alert')
            <path d="m10.3 4-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0ZM12 9v4m0 4h.01" />
            @break
        @case('check')
            <circle cx="12" cy="12" r="9" /><path d="m8 12 3 3 5-6" />
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4m10-4v4M3 11h18m-13 4h2m4 0h2" />
            @break
        @case('arrow')
            <path d="M4 12h16m-5-5 5 5-5 5" />
            @break
        @case('trend')
            <path d="m3 16 6-6 4 4 8-9m-6 0h6v6" />
            @break
        @case('chevron')
            <path d="m9 5 7 7-7 7" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('close')
            <path d="m6 6 12 12M6 18 18 6" />
            @break
        @case('pin')
            <path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" />
            @break
        @case('sun')
            <circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            @break
        @case('moon')
            <path d="M20.9 13a9 9 0 0 1-9.9-9.9A9 9 0 1 0 20.9 13Z" />
            @break
        @case('help')
            <circle cx="12" cy="12" r="9" /><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 3h.01" />
            @break
        @case('trash')
            <path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7" />
            @break
    @endswitch
</svg>
