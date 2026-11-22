<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('vessels')->where('vessel_type', 'tanker')->update(['vessel_type' => 'cargo']);
    }

    /**
     * Retain the classification because cargo rows no longer identify former tankers.
     */
    public function down(): void {}
};
