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
        Schema::create('finance_invoice_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_invoice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finance_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('previous_fee', 15, 2);
            $table->decimal('reviewed_fee', 15, 2);
            $table->text('notes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_invoice_item_reviews');
    }
};
