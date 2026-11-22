<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spb_services', function (Blueprint $table): void {
            $table->dateTime('estimated_port_activity_completion_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('spb_services')->whereNull('estimated_port_activity_completion_at')
            ->update(['estimated_port_activity_completion_at' => DB::raw('estimated_departure_at')]);
        Schema::table('spb_services', function (Blueprint $table): void {
            $table->dateTime('estimated_port_activity_completion_at')->nullable(false)->change();
        });
    }
};
