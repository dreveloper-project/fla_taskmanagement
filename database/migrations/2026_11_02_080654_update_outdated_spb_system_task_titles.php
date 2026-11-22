<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $titles = [
            'ready_to_apply' => ['Ajukan SPB', 'Catat balasan Inaportnet'],
            'ready_to_sail' => ['Konfirmasi keberangkatan kapal', 'Catat hasil SPB'],
            'sailing' => ['Pantau pelayaran dan selesaikan layanan SPB', 'Catat hasil SPB'],
        ];

        foreach ($titles as $status => [$oldTitle, $newTitle]) {
            DB::table('tasks')->join('spb_services', 'spb_services.id', '=', 'tasks.taskable_id')
                ->where('tasks.taskable_type', 'App\\Models\\SpbService')
                ->where('tasks.is_system_generated', true)
                ->whereIn('tasks.status', ['pending', 'in_progress', 'in_review'])
                ->where('tasks.title', $oldTitle)->where('spb_services.status', $status)
                ->select('tasks.id', 'tasks.status')->orderBy('tasks.id')
                ->chunkById(100, function ($tasks) use ($oldTitle, $newTitle): void {
                    DB::transaction(function () use ($tasks, $oldTitle, $newTitle): void {
                        foreach ($tasks as $task) {
                            $updated = DB::table('tasks')->where('id', $task->id)->where('title', $oldTitle)
                                ->whereIn('status', ['pending', 'in_progress', 'in_review'])
                                ->update(['title' => $newTitle, 'updated_at' => now()]);
                            if ($updated === 1) {
                                DB::table('task_histories')->insert([
                                    'task_id' => $task->id, 'event' => 'updated', 'from_status' => $task->status,
                                    'to_status' => $task->status, 'changed_by' => null, 'notes' => 'Penyesuaian alur SPB',
                                    'metadata' => json_encode(['before' => $oldTitle, 'after' => $newTitle], JSON_THROW_ON_ERROR),
                                    'created_at' => now(),
                                ]);
                            }
                        }
                    });
                }, 'tasks.id', 'id');
        }
    }

    /** Task history and later edits cannot be safely reversed automatically. */
    public function down(): void {}
};
