<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->index(['bom_item_id', 'side', 'status'], 'receipt_items_bom_side_status_idx');
            $table->index(['status', 'side'], 'receipt_items_status_side_idx');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropIndex('receipt_items_bom_side_status_idx');
            $table->dropIndex('receipt_items_status_side_idx');
        });
    }
};
