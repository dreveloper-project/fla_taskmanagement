<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('spb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spb_service_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 50);
            $table->string('source_document_type', 50)->nullable();
            $table->string('file_path');
            $table->string('original_filename');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at');
            $table->json('metadata')->nullable();
            $table->index(['spb_service_id', 'document_type', 'id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spb_documents');
    }
};
