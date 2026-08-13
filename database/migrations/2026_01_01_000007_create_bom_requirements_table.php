<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_item_id')->constrained('bom_items')->cascadeOnDelete();
            $table->enum('side', ['RH', 'LH', 'COMMON'])->index();
            $table->integer('required_quantity')->default(0);
            $table->timestamps();

            $table->unique(['bom_item_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_requirements');
    }
};
