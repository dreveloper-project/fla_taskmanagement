<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['spb_services', 'safety_certificate_services', 'bunker_services', 'sijil_services', 'ship_nationality_services'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->index('created_at');
                $table->index('completed_at');
            });
        }
        Schema::table('tasks', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('vessel_documents', fn (Blueprint $table) => $table->index('expired_date'));
        Schema::table('vessel_document_versions', fn (Blueprint $table) => $table->index('expired_date'));
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['created_at']);
                $table->dropIndex(['completed_at']);
            });
        }
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('vessel_documents', fn (Blueprint $table) => $table->dropIndex(['expired_date']));
        Schema::table('vessel_document_versions', fn (Blueprint $table) => $table->dropIndex(['expired_date']));
    }
};
