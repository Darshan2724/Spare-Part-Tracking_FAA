<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Department;
use App\Models\Supplier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Departments
        $deptNames = [
            'Administration' => 'ADMIN',
            'Management'     => 'MGMT',
            'Design'         => 'DESIGN',
            'Purchase'       => 'PURCHASE',
            'Store'          => 'STORE',
            'Quality Control'=> 'QC',
            'Rework'         => 'REWORK',
            'Paint'          => 'PAINT',
            'Assembly'       => 'ASSEMBLY',
        ];

        $departments = [];
        foreach ($deptNames as $name => $code) {
            $departments[$name] = Department::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'code' => $code]
            );
        }

        // 2. Create Roles
        $roleNames = [
            'ADMIN',
            'MANAGER',
            'STORE',
            'QC',
            'REWORK',
            'PAINT',
            'ASSEMBLY',
            'PURCHASE'
        ];

        $roles = [];
        foreach ($roleNames as $rName) {
            $roles[$rName] = Role::firstOrCreate(['name' => $rName, 'guard_name' => 'web']);
        }

        // 3. Create Permissions
        $permissions = [
            'manage_system',
            'view_dashboard',
            'import_bom',
            'store_receive',
            'store_view',
            'qc_inspect',
            'qc_view',
            'rework_manage',
            'rework_view',
            'paint_manage',
            'paint_view',
            'assembly_manage',
            'assembly_view',
            'purchase_queue_manage',
            'purchase_queue_export'
        ];

        foreach ($permissions as $pName) {
            Permission::firstOrCreate(['name' => $pName, 'guard_name' => 'web']);
        }

        // Assign permissions to roles
        $roles['ADMIN']->syncPermissions($permissions);
        $roles['MANAGER']->syncPermissions(['view_dashboard', 'import_bom', 'store_view', 'qc_view', 'rework_view', 'paint_view', 'assembly_view', 'purchase_queue_manage', 'purchase_queue_export']);
        $roles['STORE']->syncPermissions(['view_dashboard', 'store_receive', 'store_view']);
        $roles['QC']->syncPermissions(['view_dashboard', 'qc_inspect', 'qc_view']);
        $roles['REWORK']->syncPermissions(['view_dashboard', 'rework_manage', 'rework_view']);
        $roles['PAINT']->syncPermissions(['view_dashboard', 'paint_manage', 'paint_view']);
        $roles['ASSEMBLY']->syncPermissions(['view_dashboard', 'assembly_manage', 'assembly_view']);
        $roles['PURCHASE']->syncPermissions(['view_dashboard', 'purchase_queue_manage', 'purchase_queue_export']);

        // 4. Create Default Users
        $defaultPassword = Hash::make('password123');

        $users = [
            [
                'name' => 'System Admin',
                'email' => 'admin@sparetrack.internal',
                'role' => 'ADMIN',
                'dept' => 'Administration',
            ],
            [
                'name' => 'Plant Manager',
                'email' => 'manager@sparetrack.internal',
                'role' => 'MANAGER',
                'dept' => 'Management',
            ],
            [
                'name' => 'Store Officer',
                'email' => 'store@sparetrack.internal',
                'role' => 'STORE',
                'dept' => 'Store',
            ],
            [
                'name' => 'QC Inspector',
                'email' => 'qc@sparetrack.internal',
                'role' => 'QC',
                'dept' => 'Quality Control',
            ],
            [
                'name' => 'Rework Specialist',
                'email' => 'rework@sparetrack.internal',
                'role' => 'REWORK',
                'dept' => 'Rework',
            ],
            [
                'name' => 'Paint Operator',
                'email' => 'paint@sparetrack.internal',
                'role' => 'PAINT',
                'dept' => 'Paint',
            ],
            [
                'name' => 'Assembly Lead',
                'email' => 'assembly@sparetrack.internal',
                'role' => 'ASSEMBLY',
                'dept' => 'Assembly',
            ],
            [
                'name' => 'Purchase Executive',
                'email' => 'purchase@sparetrack.internal',
                'role' => 'PURCHASE',
                'dept' => 'Purchase',
            ],
        ];

        foreach ($users as $uData) {
            $user = User::firstOrCreate(
                ['email' => $uData['email']],
                [
                    'name' => $uData['name'],
                    'password' => $defaultPassword,
                    'department_id' => $departments[$uData['dept']]->id,
                ]
            );
            $user->syncRoles([$uData['role']]);
        }

        // 5. Create Default Suppliers
        $suppliers = [
            ['code' => 'SUP-001', 'name' => 'Precision Components Ltd', 'contact_person' => 'Rajesh Sharma', 'phone' => '9876543210', 'email' => 'sales@precisioncomp.com'],
            ['code' => 'SUP-002', 'name' => 'Apex Tooling & Dies', 'contact_person' => 'Anil Kumar', 'phone' => '9876543211', 'email' => 'orders@apextooling.com'],
            ['code' => 'SUP-003', 'name' => 'Global Automation Spares', 'contact_person' => 'Sanjay Patel', 'phone' => '9876543212', 'email' => 'support@globalspares.in'],
        ];

        foreach ($suppliers as $sData) {
            Supplier::firstOrCreate(['code' => $sData['code']], $sData);
        }

        // 6. System Settings
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'app_title'],
            ['value' => 'Industrial Spare Parts Tracking & Workflow Management System', 'updated_at' => now()]
        );
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'bom_import_path'],
            ['value' => 'BOM', 'updated_at' => now()]
        );

        // 7. Auto Import Sample BOM files
        $adminUser = User::where('email', 'admin@sparetrack.internal')->first();
        $bomService = app(\App\Services\BomImportService::class);
        $bomDir = base_path('BOM');

        if (\Illuminate\Support\Facades\File::exists($bomDir)) {
            $files = \Illuminate\Support\Facades\File::files($bomDir);
            foreach ($files as $file) {
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['xls', 'xlsx'])) continue;

                try {
                    $filename = $file->getFilename();
                    $filePath = $file->getRealPath();
                    $projectCode = Str::before($filename, '_ERP');
                    if (str_contains($filename, '62800')) {
                        $projectCode = 'FAA-1';
                        $projectName = 'XYZ';
                    } else {
                        $projectName = $projectCode;
                    }

                    $bomService->importFromPath($filePath, [
                        'filename' => $filename,
                        'project_code' => $projectCode,
                        'project_name' => $projectName,
                    ], $adminUser->id);
                } catch (\Throwable $e) {
                    // Ignore error
                }
            }
        }
    }
}
