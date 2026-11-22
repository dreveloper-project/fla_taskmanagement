<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crews', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            $table->string('seafarer_book_number')->nullable()->unique();
            $table->string('competency_certificate_number')->nullable();
            $table->timestamps();
        });
        Schema::create('sijil_services', function (Blueprint $table): void {
            $table->id();
            $table->string('service_number')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_staff_id')->constrained('users')->restrictOnDelete();
            $table->string('process_type', 30);
            $table->string('priority', 20);
            $table->string('status', 30)->default('draft')->index();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resubmitted_at')->nullable();
            $table->string('submission_reference')->nullable();
            $table->string('authority')->nullable();
            $table->text('revision_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('result_reference')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('effective_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('vessel_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('crew_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_crew_id')->nullable()->unique()->constrained('crews')->restrictOnDelete();
            $table->string('full_name');
            $table->string('rank');
            $table->string('seafarer_book_number')->nullable();
            $table->string('competency_certificate_number')->nullable();
            $table->date('sign_on_at');
            $table->date('sign_off_at')->nullable();
            $table->date('last_changed_at');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('sign_on_service_id')->constrained('sijil_services')->restrictOnDelete();
            $table->foreignId('sign_off_service_id')->nullable()->constrained('sijil_services')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('sijil_service_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sijil_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('crew_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vessel_crew_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('expected_revision')->nullable();
            $table->string('full_name')->nullable();
            $table->string('rank')->nullable();
            $table->string('seafarer_book_number')->nullable();
            $table->string('competency_certificate_number')->nullable();
            $table->string('action_type', 20);
            $table->text('notes')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('sijil_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sijil_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('sijil_service_crew_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('crew_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['sijil_service_id', 'document_type', 'id'], 'sijil_documents_type_index');
        });
        Schema::create('sijil_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sijil_service_id')->constrained()->restrictOnDelete();
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
        Schema::dropIfExists('sijil_status_histories');
        Schema::dropIfExists('sijil_documents');
        Schema::dropIfExists('sijil_service_crews');
        Schema::dropIfExists('vessel_crews');
        Schema::dropIfExists('sijil_services');
        Schema::dropIfExists('crews');
    }
};
