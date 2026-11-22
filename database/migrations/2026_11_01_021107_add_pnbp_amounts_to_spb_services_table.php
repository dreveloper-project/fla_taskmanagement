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
            $table->decimal('pnbp_labuh', 15, 2)->nullable();
            $table->decimal('pnbp_tambat', 15, 2)->nullable();
            $table->decimal('pnbp_mastercable', 15, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table) {
            $table->dropColumn(['pnbp_labuh', 'pnbp_tambat', 'pnbp_mastercable']);
        });
    }
};
