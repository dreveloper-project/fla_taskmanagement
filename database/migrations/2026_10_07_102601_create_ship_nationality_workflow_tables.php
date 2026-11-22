<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ship_nationality_services', function (Blueprint $table): void {
            $table->id();
            $table->string('service_number')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('document_type', 30);
            $table->string('request_type', 20);
            $table->string('priority', 20);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('previous_document_id')->nullable()->constrained('vessel_documents', indexName: 'nationality_previous_document_foreign')->restrictOnDelete();
            $table->json('previous_document_snapshot')->nullable();
            $table->string('submission_reference')->nullable();
            $table->string('authority')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resubmitted_at')->nullable();
            $table->text('revision_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('issued_document_number')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expired_at')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->unsignedBigInteger('issued_document_id')->nullable();
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
        Schema::create('ship_nationality_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ship_nationality_service_id')->constrained(indexName: 'nationality_document_service_foreign')->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['ship_nationality_service_id', 'document_type', 'id'], 'nationality_documents_type_index');
        });
        Schema::table('ship_nationality_services', function (Blueprint $table): void {
            $table->foreign('issued_document_id', 'nationality_issued_document_foreign')->references('id')->on('ship_nationality_documents')->restrictOnDelete();
        });
        Schema::create('ship_nationality_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ship_nationality_service_id')->constrained(indexName: 'nationality_history_service_foreign')->restrictOnDelete();
            $table->string('event');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        foreach (['vessel_documents' => 'document', 'vessel_document_versions' => 'version'] as $name => $suffix) {
            Schema::table($name, function (Blueprint $table) use ($suffix): void {
                $table->foreignId('source_ship_nationality_service_id')->nullable()
                    ->constrained('ship_nationality_services', indexName: 'vessel_'.$suffix.'_nationality_source_foreign')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['vessel_documents' => 'document', 'vessel_document_versions' => 'version'] as $name => $suffix) {
            Schema::table($name, function (Blueprint $table) use ($suffix): void {
                $table->dropForeign('vessel_'.$suffix.'_nationality_source_foreign');
                $table->dropColumn('source_ship_nationality_service_id');
            });
        }
        Schema::dropIfExists('ship_nationality_status_histories');
        Schema::table('ship_nationality_services', function (Blueprint $table): void {
            $table->dropForeign('nationality_issued_document_foreign');
        });
        Schema::dropIfExists('ship_nationality_documents');
        Schema::dropIfExists('ship_nationality_services');
    }
};
