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
        Schema::create('spb_services', function (Blueprint $table) {
            $table->id();
            $table->string('service_number')->unique();
            $table->string('service_type')->default('spb_passenger');
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('priority', 20);
            $table->unsignedInteger('total_crew');
            $table->unsignedInteger('total_passengers');
            $table->string('departure_port');
            $table->string('destination');
            $table->dateTime('estimated_departure_at')->index();
            $table->dateTime('estimated_port_activity_completion_at');
            $table->string('status', 30)->default('draft')->index();
            $table->string('inaportnet_reference')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resubmitted_at')->nullable();
            $table->dateTime('revision_ready_at')->nullable();
            $table->text('revision_notes')->nullable();
            $table->text('revision_items')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('spb_number')->nullable();
            $table->dateTime('spb_issued_at')->nullable();
            $table->dateTime('ready_to_sail_at')->nullable();
            $table->dateTime('actual_departure_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('sailing_result', 20)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spb_services');
    }
};
