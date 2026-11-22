<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceAssignment
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function prepare(?Model $service, array $input): array
    {
        Gate::authorize('manage-services');
        if ($service?->exists) {
            Gate::authorize('work', $service->fresh() ?? $service);
        }

        $assignedId = $service?->exists ? $service->getAttribute('assigned_staff_id') : auth()->id();
        $requestedId = $input['assigned_staff_id'] ?? null;
        Validator::make(['assigned_staff_id' => $requestedId], ['assigned_staff_id' => ['nullable', 'integer']])->validate();
        if ($requestedId !== null && $requestedId !== '' && (int) $requestedId !== $assignedId) {
            throw ValidationException::withMessages(['assigned_staff_id' => 'PIC layanan tidak dapat ditugaskan kepada orang lain.']);
        }

        $input['assigned_staff_id'] = $assignedId;

        return $input;
    }
}
