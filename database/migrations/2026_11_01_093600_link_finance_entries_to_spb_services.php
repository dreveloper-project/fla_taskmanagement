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
        Schema::table('finance_entries', function (Blueprint $table) {
            $table->foreignId('spb_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('payment_token')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spb_service_id');
            $table->dropUnique(['payment_token']);
            $table->dropColumn('payment_token');
        });
    }
};
