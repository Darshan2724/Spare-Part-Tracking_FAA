<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ecn_requirements DROP CONSTRAINT IF EXISTS ecn_requirements_side_family_check;");
            DB::statement("
                ALTER TABLE ecn_requirements 
                ADD CONSTRAINT ecn_requirements_side_family_check 
                CHECK (side_family::text IN ('LEFT', 'RIGHT', 'COMMON'));
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ecn_requirements DROP CONSTRAINT IF EXISTS ecn_requirements_side_family_check;");
            DB::statement("
                ALTER TABLE ecn_requirements 
                ADD CONSTRAINT ecn_requirements_side_family_check 
                CHECK (side_family::text IN ('LEFT', 'RIGHT'));
            ");
        }
    }
};
