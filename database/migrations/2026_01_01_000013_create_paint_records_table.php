<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paint_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->foreignId('qc_inspection_id')->constrained('qc_inspections')->cascadeOnDelete();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('quantity');
            $table->foreignId('painted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'assembled'])->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('paint_type')->nullable();
            $table->string('color_code')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paint_records');
    }
};
