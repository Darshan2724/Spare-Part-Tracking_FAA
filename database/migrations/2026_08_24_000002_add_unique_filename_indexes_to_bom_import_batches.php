<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Clean up any historical duplicate completed batches by keeping only the latest batch per original_filename
        $duplicateFilenames = DB::table('bom_import_batches')
            ->where('status', 'completed')
            ->whereNotNull('original_filename')
            ->select('original_filename', DB::raw('COUNT(*) as count'))
            ->groupBy('original_filename')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateFilenames as $dup) {
            $batches = DB::table('bom_import_batches')
                ->where('status', 'completed')
                ->where('original_filename', $dup->original_filename)
                ->orderByDesc('id')
                ->get();

            // Keep the first (latest ID), mark older ones as 'failed' so only one 'completed' remains
            $first = true;
            foreach ($batches as $b) {
                if ($first) {
                    $first = false;
                    continue;
                }
                DB::table('bom_import_batches')
                    ->where('id', $b->id)
                    ->update(['status' => 'failed']);
            }
        }

        // Also check if any duplicate 'filename' column exists without original_filename
        $duplicateOldFilenames = DB::table('bom_import_batches')
            ->where('status', 'completed')
            ->select('filename', DB::raw('COUNT(*) as count'))
            ->groupBy('filename')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateOldFilenames as $dup) {
            $batches = DB::table('bom_import_batches')
                ->where('status', 'completed')
                ->where('filename', $dup->filename)
                ->orderByDesc('id')
                ->get();

            $first = true;
            foreach ($batches as $b) {
                if ($first) {
                    $first = false;
                    continue;
                }
                DB::table('bom_import_batches')
                    ->where('id', $b->id)
                    ->update(['status' => 'failed']);
            }
        }

        // 2. Add PostgreSQL partial unique index on original_filename for completed imports
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS bom_import_batches_original_filename_unique ON bom_import_batches (original_filename) WHERE status = 'completed'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bom_import_batches_original_filename_unique');
    }
};
