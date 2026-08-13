<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('standard_part_no')->index();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('rejected_quantity');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('rejected_by')->constrained('users');
            $table->timestamp('rejected_at')->useCurrent();
            $table->enum('status', ['pending_purchase', 'exported', 'reordered', 'closed'])->default('pending_purchase')->index();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_queue_items');
    }
};
