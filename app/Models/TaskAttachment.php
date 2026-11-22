<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_id
 * @property string $file_path
 * @property string $original_filename
 */
#[Fillable(['task_id', 'file_path', 'original_filename', 'uploaded_by'])]
class TaskAttachment extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
