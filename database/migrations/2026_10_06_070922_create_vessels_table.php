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
        Schema::create('vessels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vessel_name');
            $table->string('vessel_type', 30);
            $table->string('owner')->nullable();
            $table->string('flag')->default('Indonesia');
            $table->string('port_of_registry')->nullable();
            $table->string('registry_number')->nullable();
            $table->string('call_sign', 50)->nullable();
            $table->decimal('gross_tonnage', 12, 2)->nullable();
            $table->decimal('net_tonnage', 12, 2)->nullable();
            $table->decimal('loa', 10, 2)->nullable();
            $table->decimal('breadth', 10, 2)->nullable();
            $table->decimal('draft', 10, 2)->nullable();
            $table->unsignedSmallInteger('build_year')->nullable();
            $table->string('operational_status', 30);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
