<?php

use App\Models\VesselDocument;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public bool $active = false;

    public function boot(): void
    {
        Gate::authorize('access-system');
    }

    public function refreshWarnings(): void
    {
        unset($this->warningLevel, $this->warningCount);
    }

    #[Computed]
    public function warningCount(): int
    {
        return VesselDocument::whereDate('expired_date', '<=', today(config('app.timezone'))->addDays(20)->toDateString())->count();
    }

    #[Computed]
    public function warningLevel(): ?string
    {
        $today = today(config('app.timezone'));
        if (VesselDocument::whereDate('expired_date', '<', $today->toDateString())->exists()) {
            return 'expired';
        }

        return VesselDocument::whereDate('expired_date', '>=', $today->toDateString())
            ->whereDate('expired_date', '<=', $today->copy()->addDays(20)->toDateString())->exists()
            ? 'expiring'
            : null;
    }
};
?>

<a wire:poll.60s="refreshWarnings" href="{{ route('home') }}"
    @if ($active) aria-current="page" @endif
    @if (! $active && $this->warningLevel) title="{{ $this->warningLevel === 'expired' ? 'Ada dokumen kapal yang sudah kedaluwarsa' : 'Ada dokumen kapal yang akan kedaluwarsa dalam 20 hari' }}" @endif
    @class(['flex items-center gap-3 rounded-lg px-3 py-3 text-[13px] font-medium',
        'bg-blue-500 text-white shadow-sm shadow-blue-950/10' => $active,
        'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/5' => ! $active && ! $this->warningLevel,
        'overview-warning overview-warning-expired' => ! $active && $this->warningLevel === 'expired',
        'overview-warning overview-warning-expiring' => ! $active && $this->warningLevel === 'expiring'])>
    <x-dashboard.icon name="overview" data-sidebar-icon="overview" /> <span>Overview</span>
    @if (! $active && $this->warningLevel)
        <span class="ml-auto flex shrink-0 items-center gap-1.5" role="status">
            <span class="overview-warning-bell"><x-dashboard.icon name="bell" class="size-5" /></span>
            <span data-testid="overview-warning-count" aria-hidden="true" class="min-w-5 rounded-full bg-current/10 px-1.5 py-0.5 text-center text-[10px] font-semibold tabular-nums">{{ $this->warningCount }}</span>
            <span class="sr-only">{{ $this->warningCount }} dokumen kapal kedaluwarsa atau akan kedaluwarsa dalam 20 hari</span>
        </span>
    @elseif ($active)
        <span class="ml-auto size-1.5 rounded-full bg-white"></span>
    @endif
</a>
