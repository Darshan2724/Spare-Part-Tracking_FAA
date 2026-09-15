<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assembly_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->string('side', 10)->default('COMMON');
            $table->string('bom_type', 10);
            $table->integer('allocated_quantity');
            $table->string('status', 20)->default('active');
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            // Composite indexes for fast query lookups
            $table->index(['project_id', 'bom_type', 'status'], 'assembly_alloc_proj_type_status_idx');
            $table->index(['bom_item_id', 'status'], 'assembly_alloc_bom_status_idx');
            $table->index('allocated_by', 'assembly_alloc_user_idx');
        });

        // PostgreSQL check constraints
        DB::statement("ALTER TABLE assembly_allocations ADD CONSTRAINT chk_assembly_allocations_bom_type CHECK (bom_type IN ('BOP', 'STD'))");
        DB::statement("ALTER TABLE assembly_allocations ADD CONSTRAINT chk_assembly_allocations_quantity CHECK (allocated_quantity > 0)");
        DB::statement("ALTER TABLE assembly_allocations ADD CONSTRAINT chk_assembly_allocations_status CHECK (status IN ('active', 'consumed', 'released'))");
        DB::statement("ALTER TABLE assembly_allocations ADD CONSTRAINT chk_assembly_allocations_side CHECK (side IN ('COMMON', 'RH', 'LH'))");

        // Partial unique index: at most one active allocation per (bom_item_id, side)
        DB::statement("CREATE UNIQUE INDEX assembly_allocations_active_bom_side_idx ON assembly_allocations (bom_item_id, side) WHERE status = 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS assembly_allocations_active_bom_side_idx");
        Schema::dropIfExists('assembly_allocations');
    }
};
