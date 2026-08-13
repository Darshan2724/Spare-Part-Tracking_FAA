<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->foreignId('receipt_item_id')->nullable()->constrained('receipt_items')->nullOnDelete();
            $table->foreignId('rework_record_id')->nullable(); // Set if reinspection after rework
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('inspected_quantity');
            $table->integer('approved_quantity')->default(0);
            $table->integer('rejected_quantity')->default(0);
            $table->integer('rework_quantity')->default(0);
            $table->enum('result', ['approved', 'rejected', 'rework', 'partial'])->index();
            $table->string('rejection_reason')->nullable();
            $table->string('rework_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_reinspection')->default(false);
            $table->foreignId('inspected_by')->constrained('users');
            $table->timestamp('inspection_date')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspections');
    }
};
