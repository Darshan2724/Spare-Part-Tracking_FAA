<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_queue_items', function (Blueprint $table) {
            $table->unsignedBigInteger('bom_item_id')->nullable()->change();
            $table->unsignedBigInteger('qc_inspection_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_queue_items', function (Blueprint $table) {
            $table->unsignedBigInteger('bom_item_id')->nullable(false)->change();
            $table->unsignedBigInteger('qc_inspection_id')->nullable(false)->change();
        });
    }
};
