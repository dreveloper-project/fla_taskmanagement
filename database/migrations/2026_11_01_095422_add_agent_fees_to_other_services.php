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
        foreach (['bunker_services', 'safety_certificate_services', 'sijil_services', 'ship_nationality_services'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->decimal('agent_fee', 15, 2)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['bunker_services', 'safety_certificate_services', 'sijil_services', 'ship_nationality_services'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('agent_fee');
            });
        }
    }
};
