<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::table('finance_invoice_items', function (Blueprint $table): void {
            $table->string('active_service_key', 100)->nullable()->unique();
        });
        DB::table('finance_invoice_items')->orderBy('id')->chunkById(500, function ($items): void {
            foreach ($items as $item) {
                DB::table('finance_invoice_items')->where('id', $item->id)->update(['active_service_key' => $item->service_type.':'.$item->service_id]);
            }
        });
        Schema::table('finance_invoice_items', function (Blueprint $table): void {
            $table->dropUnique(['service_type', 'service_id']);
            $table->index(['service_type', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasDuplicates = DB::table('finance_invoice_items')->select('service_type', 'service_id')->groupBy('service_type', 'service_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($hasDuplicates) {
            throw new RuntimeException('Tidak dapat mengembalikan migrasi karena ada layanan yang telah ditagihkan ulang.');
        }
        Schema::table('finance_invoice_items', function (Blueprint $table): void {
            $table->dropIndex(['service_type', 'service_id']);
            $table->unique(['service_type', 'service_id']);
            $table->dropUnique(['active_service_key']);
            $table->dropColumn('active_service_key');
        });
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
