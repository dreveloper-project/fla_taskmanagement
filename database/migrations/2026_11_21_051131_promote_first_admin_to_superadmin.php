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
        if (! DB::table('users')->where('role', 'superadmin')->exists()) {
            $firstAdmin = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
            if ($firstAdmin !== null) {
                DB::table('users')->where('id', $firstAdmin)->update(['role' => 'superadmin']);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Preserve account privileges on rollback.
    }
};
