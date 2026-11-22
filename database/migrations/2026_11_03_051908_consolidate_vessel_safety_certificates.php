<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('vessel_documents')->whereIn('document_type', ['skkp', 'skp'])->orderBy('id')->get()->each(function (object $legacy): void {
                $current = DB::table('vessel_documents')->where('vessel_id', $legacy->vessel_id)->where('document_type', 'safety_certificate')->first();

                if ($current === null) {
                    DB::table('vessel_documents')->where('id', $legacy->id)->update(['document_type' => 'safety_certificate']);

                    return;
                }

                DB::table('vessel_document_versions')->where('vessel_document_id', $legacy->id)->update(['vessel_document_id' => $current->id]);
                DB::table('vessel_document_versions')->insert([
                    'vessel_document_id' => $current->id,
                    'certificate_number' => $legacy->certificate_number,
                    'file_path' => $legacy->file_path,
                    'original_filename' => $legacy->original_filename,
                    'issued_date' => $legacy->issued_date,
                    'expired_date' => $legacy->expired_date,
                    'source_safety_certificate_service_id' => $legacy->source_safety_certificate_service_id,
                    'source_ship_nationality_service_id' => $legacy->source_ship_nationality_service_id,
                    'equipment' => $legacy->equipment,
                    'status' => 'superseded',
                    'archived_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('safety_certificate_services')->where('current_certificate_document_id', $legacy->id)->update(['current_certificate_document_id' => $current->id]);
                DB::table('ship_nationality_services')->where('previous_document_id', $legacy->id)->update(['previous_document_id' => $current->id]);
                DB::table('vessel_documents')->where('id', $legacy->id)->delete();
            });
        });
    }

    public function down(): void
    {
        // Consolidated documents and their history cannot be separated reliably.
    }
};
