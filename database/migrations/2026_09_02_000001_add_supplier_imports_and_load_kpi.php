<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create supplier_imports table to track Excel import batches
        Schema::create('supplier_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('file_hash', 64)->nullable(); // SHA-256 hash for duplicate detection
            $table->integer('total_rows')->default(0);
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('file_hash');
            $table->index('imported_by');
        });

        // 2. Add supplier_import_id FK to suppliers table
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('supplier_import_id')
                ->nullable()
                ->after('is_test_data')
                ->constrained('supplier_imports')
                ->nullOnDelete();

            $table->index('supplier_import_id');
            // Composite index for active supplier dropdown queries
            $table->index(['is_active', 'deleted_at'], 'idx_suppliers_active_dropdown');
        });

        // 3. Performance indexes for Supplier Load KPI aggregation queries
        // These support GROUP BY supplier_id WHERE status = 'active' queries
        if (!$this->indexExists('supplier_assignments', 'idx_sa_load_kpi')) {
            Schema::table('supplier_assignments', function (Blueprint $table) {
                $table->index(['status', 'supplier_id', 'category'], 'idx_sa_load_kpi');
            });
        }
    }

    public function down(): void
    {
        Schema::table('supplier_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_sa_load_kpi');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('idx_suppliers_active_dropdown');
            $table->dropForeign(['supplier_import_id']);
            $table->dropColumn('supplier_import_id');
        });

        Schema::dropIfExists('supplier_imports');
    }

    /**
     * Helper to check if an index already exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $result = \Illuminate\Support\Facades\DB::select(
                "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$table, $indexName]
            );
            return count($result) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
};
