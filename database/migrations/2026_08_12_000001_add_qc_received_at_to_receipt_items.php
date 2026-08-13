<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('receipt_items', 'qc_received_at')) {
                $table->timestamp('qc_received_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            if (Schema::hasColumn('receipt_items', 'qc_received_at')) {
                $table->dropColumn('qc_received_at');
            }
        });
    }
};
