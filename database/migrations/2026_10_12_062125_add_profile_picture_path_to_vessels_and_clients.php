<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vessels', 'clients'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('profile_picture_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['vessels', 'clients'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('profile_picture_path');
            });
        }
    }
};
