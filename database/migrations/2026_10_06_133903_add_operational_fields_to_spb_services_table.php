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
            $table->string('cargo_status', 30)->nullable();
            $table->text('cargo_description')->nullable();
            $table->string('cargo_type')->nullable();
            $table->decimal('cargo_quantity', 15, 3)->nullable();
            $table->string('cargo_unit', 50)->nullable();
            $table->boolean('port_cargo_activity_applicable')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table) {
            $table->dropColumn(['cargo_status', 'cargo_description', 'cargo_type', 'cargo_quantity', 'cargo_unit', 'port_cargo_activity_applicable']);
        });
    }
};
