<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Supplier;

class TestSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $testSuppliers = [
            [
                'code' => 'SUP-TEST-ALPHA',
                'name' => 'Supplier Alpha',
                'contact_person' => 'Alpha Representative',
                'phone' => '+91 99000 11001',
                'email' => 'contact@supplier-alpha.test',
                'address' => 'Plot 101, Test Industrial Area, Phase 1',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
                'remarks' => 'Temporary test supplier for supplier allocation verification',
            ],
            [
                'code' => 'SUP-TEST-BETA',
                'name' => 'Supplier Beta',
                'contact_person' => 'Beta Representative',
                'phone' => '+91 99000 11002',
                'email' => 'contact@supplier-beta.test',
                'address' => 'Plot 102, Test Industrial Area, Phase 1',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
                'remarks' => 'Temporary test supplier for supplier allocation verification',
            ],
            [
                'code' => 'SUP-TEST-GAMMA',
                'name' => 'Supplier Gamma',
                'contact_person' => 'Gamma Representative',
                'phone' => '+91 99000 11003',
                'email' => 'contact@supplier-gamma.test',
                'address' => 'Plot 103, Test Industrial Area, Phase 2',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
                'remarks' => 'Temporary test supplier for supplier allocation verification',
            ],
            [
                'code' => 'SUP-TEST-DELTA',
                'name' => 'Supplier Delta',
                'contact_person' => 'Delta Representative',
                'phone' => '+91 99000 11004',
                'email' => 'contact@supplier-delta.test',
                'address' => 'Plot 104, Test Industrial Area, Phase 2',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
                'remarks' => 'Temporary test supplier for supplier allocation verification',
            ],
        ];

        foreach ($testSuppliers as $data) {
            Supplier::updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
