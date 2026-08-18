<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('filename');
            $table->bigInteger('file_size_bytes')->nullable()->after('file_hash');
            $table->string('original_filename', 255)->nullable()->after('file_size_bytes');
            $table->jsonb('project_codes')->nullable()->after('original_filename');
        });

        // Backfill existing completed batches with unique derived hashes if file_hash is null
        $existingBatches = DB::table('bom_import_batches')->where('status', 'completed')->get();
        foreach ($existingBatches as $batch) {
            $derivedHash = hash('sha256', $batch->filename . '_' . $batch->id . '_' . $batch->created_at);
            DB::table('bom_import_batches')->where('id', $batch->id)->update([
                'file_hash' => $derivedHash,
                'original_filename' => $batch->filename,
            ]);
        }

        // Add PostgreSQL partial unique index on file_hash for completed imports
        DB::statement("CREATE UNIQUE INDEX bom_import_batches_file_hash_unique ON bom_import_batches (file_hash) WHERE status = 'completed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bom_import_batches_file_hash_unique');

        Schema::table('bom_import_batches', function (Blueprint $table) {
            $table->dropColumn(['file_hash', 'file_size_bytes', 'original_filename', 'project_codes']);
        });
    }
};
