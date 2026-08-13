<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE receipt_items DROP CONSTRAINT IF EXISTS receipt_items_status_check;");
        DB::statement("
            ALTER TABLE receipt_items 
            ADD CONSTRAINT receipt_items_status_check 
            CHECK (status::text IN (
                'received',
                'sent_to_qc',
                'qc_received',
                'qc_approved',
                'qc_rejected',
                'qc_rework',
                'qc_inspected',
                'paint_completed',
                'assembly_completed',
                'reverted'
            ));
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE receipt_items DROP CONSTRAINT IF EXISTS receipt_items_status_check;");
    }
};
