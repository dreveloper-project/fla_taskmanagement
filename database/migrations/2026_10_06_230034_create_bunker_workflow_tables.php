<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bunker_services', function (Blueprint $table): void {
            $table->id();
            $table->string('service_number')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_staff_id')->constrained('users')->restrictOnDelete();
            $table->string('supplier')->nullable();
            $table->string('bunker_location')->nullable();
            $table->string('bunker_type')->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('unit_other', 50)->nullable();
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->string('priority', 20);
            $table->string('status', 30)->default('draft')->index();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resubmitted_at')->nullable();
            $table->string('submission_reference')->nullable();
            $table->string('authority')->nullable();
            $table->text('revision_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('approval_number')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('actual_bunker_at')->nullable();
            $table->decimal('actual_quantity', 15, 3)->nullable();
            $table->string('actual_unit', 20)->nullable();
            $table->string('actual_unit_other', 50)->nullable();
            $table->text('activity_notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('bunker_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bunker_service_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['bunker_service_id', 'document_type', 'id']);
        });
        Schema::create('bunker_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bunker_service_id')->constrained()->restrictOnDelete();
            $table->string('event');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunker_status_histories');
        Schema::dropIfExists('bunker_documents');
        Schema::dropIfExists('bunker_services');
    }
};
