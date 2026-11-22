<?php

namespace App\Services;

use App\Models\User;
use App\Models\VesselDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-type CalendarEvent array{id: string, kind: string, title: string, subtitle: string, date: string, time: string|null, overdue: bool, url: string, work_url: string|null, work_label: string|null, owner: string|null}
 */
class OperationalCalendar
{
    /** @return Collection<int, CalendarEvent> */
    public function events(User $user, CarbonImmutable $from, CarbonImmutable $through): Collection
    {
        Gate::forUser($user)->authorize('access-system');
        $events = collect();
        $today = CarbonImmutable::today(config('spb.timezone'))->toDateString();
        $documents = VesselDocument::query()->with('vessel:id,vessel_name')
            ->whereBetween('expired_date', [$from->toDateString(), $through->toDateString()])
            ->orderBy('expired_date')->orderBy('id')->get();
        foreach ($documents as $document) {
            $events->push([
                'id' => 'document:'.$document->id, 'kind' => 'document',
                'title' => VesselDocument::TYPES[$document->document_type] ?? $document->document_type,
                'subtitle' => $document->vessel->vessel_name,
                'date' => $document->expired_date->toDateString(), 'time' => null,
                'overdue' => $document->expired_date->toDateString() < $today,
                'url' => route('dashboard.vessel', ['vessel' => $document->vessel_id]),
                'work_url' => null, 'work_label' => null, 'owner' => null,
            ]);
        }

        return $events;
    }
}
