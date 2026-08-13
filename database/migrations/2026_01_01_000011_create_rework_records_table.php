<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rework_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('quantity');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'returned_to_qc'])->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('rework_description')->nullable();
            $table->text('completion_notes')->nullable();
            $table->integer('cycle_number')->default(1); // Tracks rework loop iterations
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rework_records');
    }
};
