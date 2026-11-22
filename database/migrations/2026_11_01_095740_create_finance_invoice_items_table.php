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
        Schema::create('finance_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_invoice_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 40);
            $table->unsignedBigInteger('service_id');
            $table->string('service_number');
            $table->string('vessel_name');
            $table->decimal('original_agent_fee', 15, 2);
            $table->decimal('prepaid_amount', 15, 2)->default(0);
            $table->decimal('settled_amount', 15, 2)->nullable();
            $table->unique(['service_type', 'service_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_invoice_items');
    }
};
