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
        Schema::table('spb_services', function (Blueprint $table): void {
            $table->dateTime('expected_arrival_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table): void {
            $table->dropColumn('expected_arrival_at');
        });
    }
};
