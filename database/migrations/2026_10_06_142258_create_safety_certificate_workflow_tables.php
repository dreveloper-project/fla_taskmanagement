<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_certificate_services', function (Blueprint $table): void {
            $table->id();
            $table->string('service_number')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_staff_id')->constrained('users')->restrictOnDelete();
            $table->string('request_type', 20);
            $table->string('priority', 20);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('current_certificate_document_id')->nullable()->constrained('vessel_documents', indexName: 'safety_previous_document_foreign')->restrictOnDelete();
            $table->json('previous_certificate_snapshot')->nullable();
            $table->string('submission_reference')->nullable();
            $table->string('authority')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resubmitted_at')->nullable();
            $table->text('revision_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('inspection_required')->default(false);
            $table->dateTime('inspection_scheduled_at')->nullable();
            $table->string('inspection_location')->nullable();
            $table->string('inspector_name')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->dateTime('inspection_completed_at')->nullable();
            $table->string('inspection_result', 30)->nullable();
            $table->text('findings')->nullable();
            $table->string('certificate_number')->nullable();
            $table->date('certificate_issued_at')->nullable();
            $table->date('certificate_expired_at')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->unsignedBigInteger('issued_certificate_document_id')->nullable();
            $table->dateTime('handed_over_at')->nullable();
            $table->string('handed_over_to')->nullable();
            $table->string('recipient_role')->nullable();
            $table->foreignId('handed_over_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('safety_certificate_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safety_certificate_service_id')->constrained(indexName: 'safety_document_service_foreign')->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['safety_certificate_service_id', 'document_type', 'id'], 'safety_documents_type_index');
        });
        Schema::table('safety_certificate_services', function (Blueprint $table): void {
            $table->foreign('issued_certificate_document_id', 'safety_issued_document_foreign')->references('id')->on('safety_certificate_documents')->restrictOnDelete();
        });
        Schema::create('safety_certificate_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safety_certificate_service_id')->constrained(indexName: 'safety_history_service_foreign')->restrictOnDelete();
            $table->string('event');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::table('vessel_documents', function (Blueprint $table): void {
            $table->string('certificate_number')->nullable();
            $table->foreignId('source_safety_certificate_service_id')->nullable()->constrained('safety_certificate_services', indexName: 'vessel_document_safety_source_foreign')->restrictOnDelete();
        });
        Schema::create('vessel_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vessel_document_id')->constrained()->restrictOnDelete();
            $table->string('certificate_number')->nullable();
            $table->string('file_path');
            $table->string('original_filename');
            $table->date('issued_date')->nullable();
            $table->date('expired_date')->nullable();
            $table->foreignId('source_safety_certificate_service_id')->nullable()->constrained('safety_certificate_services', indexName: 'vessel_version_safety_source_foreign')->restrictOnDelete();
            $table->string('status', 20)->default('superseded');
            $table->dateTime('archived_at');
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_document_versions');
        Schema::table('vessel_documents', function (Blueprint $table): void {
            $table->dropForeign('vessel_document_safety_source_foreign');
            $table->dropColumn(['certificate_number', 'source_safety_certificate_service_id']);
        });
        Schema::dropIfExists('safety_certificate_status_histories');
        Schema::table('safety_certificate_services', function (Blueprint $table): void {
            $table->dropForeign('safety_issued_document_foreign');
        });
        Schema::dropIfExists('safety_certificate_documents');
        Schema::dropIfExists('safety_certificate_services');
    }
};
