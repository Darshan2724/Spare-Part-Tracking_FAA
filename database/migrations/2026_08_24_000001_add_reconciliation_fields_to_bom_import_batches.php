<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->string('import_type', 50)->default('initial')->after('status'); // initial, incremental, revision, duplicate
            $table->integer('added_rows_count')->default(0)->after('successful_rows');
            $table->integer('updated_rows_count')->default(0)->after('added_rows_count');
            $table->integer('skipped_rows_count')->default(0)->after('updated_rows_count');
            $table->integer('conflict_rows_count')->default(0)->after('skipped_rows_count');
            $table->jsonb('diff_summary')->nullable()->after('conflict_rows_count');
        });
    }

    public function down(): void
    {
        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'import_type',
                'added_rows_count',
                'updated_rows_count',
                'skipped_rows_count',
                'conflict_rows_count',
                'diff_summary',
            ]);
        });
    }
};
