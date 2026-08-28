<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecn_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecn_requirement_id')->constrained('ecn_requirements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->index();
            $table->string('ecn_number')->index();
            $table->string('side', 10)->index();
            $table->string('side_display', 10)->index();
            $table->integer('received_quantity');
            $table->string('status')->default('received')->index(); // received, pending_qc, qc_received, qc_approved, qc_rejected, qc_rework, paint_completed, assembly_completed, reverted
            $table->text('remarks')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status'], 'ecn_receipt_proj_status_idx');
            $table->index(['ecn_requirement_id', 'status'], 'ecn_receipt_req_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecn_receipt_items');
    }
};
