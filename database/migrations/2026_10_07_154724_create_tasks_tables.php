<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('taskable');
            $table->foreignId('vessel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_key', 100)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            foreach (['start_at', 'due_at', 'started_at', 'completed_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            $table->text('notes')->nullable();
            $table->boolean('is_system_generated')->default(false);
            $table->timestamps();
            $table->unique(['taskable_type', 'taskable_id', 'task_key'], 'tasks_service_key_unique');
            $table->index(['status', 'due_at']);
            $table->index(['status', 'completed_at']);
            $table->index(['assigned_to', 'status']);
        });
        Schema::create('task_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('task_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_histories');
        Schema::dropIfExists('tasks');
    }
};
