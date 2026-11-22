@props(['user'])

<span {{ $attributes->class(['inline-flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-100 dark:bg-blue-950/40 text-xs font-semibold text-blue-700 dark:text-blue-300']) }} aria-label="{{ $user->name }}">
    @if ($user->profile_picture_path)
        <img src="{{ route('profile.picture') }}" alt="Foto profil {{ $user->name }}" class="size-full object-cover">
    @else
        {{ $user->initials() }}
    @endif
</span>