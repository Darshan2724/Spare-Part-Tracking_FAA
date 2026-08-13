<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('event_type')->index(); // received, sent_to_qc, qc_approved, qc_rejected, qc_rework, rework_started, rework_completed, returned_to_qc, paint_started, paint_completed, assembly_started, assembly_completed
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('quantity');
            $table->string('previous_state')->nullable();
            $table->string('new_state')->nullable();
            $table->text('remarks')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
    }
};
