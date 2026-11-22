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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_frequency', 20)->default('manual');
            $table->unsignedTinyInteger('billing_day')->nullable();
            $table->date('next_billing_on')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['next_billing_on']);
            $table->dropColumn(['billing_frequency', 'billing_day', 'next_billing_on']);
        });
    }
};
