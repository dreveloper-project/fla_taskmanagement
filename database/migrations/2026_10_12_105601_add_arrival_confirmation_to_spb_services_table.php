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
            $table->dateTime('actual_arrival_at')->nullable();
            $table->foreignId('arrival_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spb_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arrival_confirmed_by');
            $table->dropColumn('actual_arrival_at');
        });
    }
};
