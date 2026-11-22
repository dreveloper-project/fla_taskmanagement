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
            $table->decimal('arrival_pnbp_labuh', 15, 2)->nullable();
            $table->decimal('arrival_pnbp_mastercable', 15, 2)->nullable();
            $table->string('arrival_spb_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table) {
            $table->dropColumn(['arrival_pnbp_labuh', 'arrival_pnbp_mastercable', 'arrival_spb_number']);
        });
    }
};
