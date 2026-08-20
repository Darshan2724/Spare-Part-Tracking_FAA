<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'receipt_items_status_created_idx');
        });

        Schema::table('workflow_events', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'event_type'], 'workflow_events_bom_side_event_idx');
            $table->index(['project_id', 'event_type', 'created_at'], 'workflow_events_proj_event_created_idx');
        });

        Schema::table('bom_requirements', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'required_quantity'], 'bom_req_item_side_qty_idx');
        });

        Schema::table('bom_items', function (Blueprint $table) {
            $table->index(['project_id', 'jig_no', 'unit_no'], 'bom_items_proj_jig_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('receipt_items_status_created_idx');
        });

        Schema::table('workflow_events', function (Blueprint $table) {
            $table->dropIndex('workflow_events_bom_side_event_idx');
            $table->dropIndex('workflow_events_proj_event_created_idx');
        });

        Schema::table('bom_requirements', function (Blueprint $table) {
            $table->dropIndex('bom_req_item_side_qty_idx');
        });

        Schema::table('bom_items', function (Blueprint $table) {
            $table->dropIndex('bom_items_proj_jig_unit_idx');
        });
    }
};
