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
        Schema::table('spb_services', function (Blueprint $table) {
            $table->boolean('arrival_spb_enabled')->default(false);
            $table->string('arrival_billing_code_labuh')->nullable();
            $table->string('arrival_billing_code_rambu')->nullable();
            $table->string('arrival_billing_code_mastercable')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table) {
            $table->dropColumn(['arrival_spb_enabled', 'arrival_billing_code_labuh', 'arrival_billing_code_rambu', 'arrival_billing_code_mastercable']);
        });
    }
};
