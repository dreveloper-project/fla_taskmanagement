<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kalender operasional')] class extends Component
{
    public function boot(): void
    {
        Gate::authorize('access-system');
    }
};
?>

<main id="main-content" class="min-h-dvh min-w-0 p-4 sm:p-6 lg:flex lg:h-dvh lg:min-h-0 lg:flex-col lg:overflow-hidden lg:p-4">
    <livewire:dashboard.operational-calendar :expanded="true" />
</main>
