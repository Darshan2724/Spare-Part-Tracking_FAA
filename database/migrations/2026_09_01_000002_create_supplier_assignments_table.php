<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('jig_no');
            $table->string('unit_no');
            $table->string('category', 20); // BASE, WELDMENT, CHILD_PART
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->date('assignment_date');
            $table->string('status', 20)->default('active'); // active, superseded, removed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Standard indexes
            $table->index(['project_id', 'jig_no', 'unit_no']);
            $table->index('supplier_id');
            $table->index('category');
            $table->index('status');
            $table->index('assignment_date');
            $table->index(['project_id', 'jig_no', 'unit_no', 'category', 'status'], 'idx_sa_lookup');
        });

        // Add check constraint and partial unique index in PostgreSQL
        DB::statement("ALTER TABLE supplier_assignments ADD CONSTRAINT chk_sa_category CHECK (category IN ('BASE', 'WELDMENT', 'CHILD_PART'))");
        DB::statement("ALTER TABLE supplier_assignments ADD CONSTRAINT chk_sa_status CHECK (status IN ('active', 'superseded', 'removed'))");
        DB::statement("CREATE UNIQUE INDEX idx_sa_active_unique ON supplier_assignments (project_id, jig_no, unit_no, category) WHERE (status = 'active')");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_assignments');
    }
};
