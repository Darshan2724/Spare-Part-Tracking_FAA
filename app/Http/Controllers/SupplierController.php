<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierPhone;
use App\Models\SupplierAssignment;
use App\Services\SupplierImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $query = Supplier::query()->with(['phones'])->withCount('supplierAssignments');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%")
                  ->orWhere('contact_person', 'ILIKE', "%{$search}%")
                  ->orWhere('city', 'ILIKE', "%{$search}%")
                  ->orWhere('pincode', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%")
                  ->orWhereHas('phones', function ($pq) use ($search) {
                      $pq->where('phone_number', 'ILIKE', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->where('is_active', true);
            } elseif ($request->input('status') === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->has('is_test_data') && $request->input('is_test_data') !== '') {
            $query->where('is_test_data', $request->boolean('is_test_data'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $suppliers = $query->orderBy('name')->paginate($perPage);

        $activeCount = Supplier::where('is_active', true)->count();
        $inactiveCount = Supplier::where('is_active', false)->count();

        return response()->json([
            'data' => $suppliers->items(),
            'current_page' => $suppliers->currentPage(),
            'last_page' => $suppliers->lastPage(),
            'per_page' => $suppliers->perPage(),
            'total' => $suppliers->total(),
            'active_count' => $activeCount,
            'inactive_count' => $inactiveCount,
        ]);
    }

    /**
     * Compact list of active suppliers for dropdown selectors.
     * Accessible, searchable, and enriched with compact load KPI status.
     */
    public function activeList(Request $request, \App\Services\SupplierLoadService $loadService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE', 'QC']) ?: abort(403);

        $query = Supplier::where('is_active', true)->with('phones')->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%");
            });
        }

        $suppliers = $query->get(['id', 'name', 'code', 'is_test_data', 'city', 'state', 'pincode']);

        // Attach compact load summary
        $loadSummary = $loadService->getSupplierLoadSummary();
        $enriched = $suppliers->map(function ($s) use ($loadSummary) {
            $data = $s->toArray();
            $load = $loadSummary[$s->id] ?? null;
            $data['load_status'] = $load ? $load['load_status'] : 'Low Load';
            $data['total_assignments'] = $load ? $load['total_assignments'] : 0;
            $data['load_pct'] = $load ? $load['load_pct'] : 0;
            return $data;
        });

        return response()->json([
            'success' => true,
            'suppliers' => $enriched,
        ]);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'name' => ['required', 'string', 'max:255', \Illuminate\Validation\Rule::unique('suppliers', 'name')->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:50', \Illuminate\Validation\Rule::unique('suppliers', 'code')->whereNull('deleted_at')],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'is_test_data' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier = DB::transaction(function () use ($request) {
            $primaryPhone = $request->input('phone');
            $phones = $request->input('phones', []);

            if (empty($primaryPhone) && !empty($phones) && is_array($phones)) {
                $primaryPhone = trim((string)$phones[0]);
            }

            $s = Supplier::create([
                'name' => trim($request->input('name')),
                'code' => $request->input('code') ? trim($request->input('code')) : Str::slug(trim($request->input('name'))),
                'contact_person' => $request->input('contact_person'),
                'phone' => $primaryPhone,
                'email' => $request->input('email'),
                'address' => $request->input('address'),
                'city' => $request->input('city'),
                'pincode' => $request->input('pincode'),
                'state' => $request->input('state', 'Maharashtra'),
                'country' => $request->input('country', 'India'),
                'remarks' => $request->input('remarks'),
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
                'is_test_data' => $request->boolean('is_test_data', false),
            ]);

            // Save normalized phone numbers
            if (is_array($phones) && count($phones) > 0) {
                foreach ($phones as $idx => $phoneNum) {
                    $clean = trim((string)$phoneNum);
                    if ($clean === '') continue;

                    SupplierPhone::create([
                        'supplier_id' => $s->id,
                        'phone_number' => $clean,
                        'label' => $idx === 0 ? 'Primary' : 'Office',
                        'is_primary' => $idx === 0,
                    ]);
                }
            } elseif ($primaryPhone) {
                SupplierPhone::create([
                    'supplier_id' => $s->id,
                    'phone_number' => $primaryPhone,
                    'label' => 'Primary',
                    'is_primary' => true,
                ]);
            }

            return $s;
        });

        $supplier->load('phones');

        return response()->json([
            'success' => true,
            'message' => 'Supplier created successfully.',
            'supplier' => $supplier,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $supplier = Supplier::findOrFail($id);

        $request->validate([
            'name' => ['required', 'string', 'max:255', \Illuminate\Validation\Rule::unique('suppliers', 'name')->ignore($id)->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:50', \Illuminate\Validation\Rule::unique('suppliers', 'code')->ignore($id)->whereNull('deleted_at')],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phones' => ['nullable', 'array'],
            'phones.*' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'is_test_data' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($supplier, $request) {
            $supplier->update($request->only([
                'name', 'code', 'contact_person', 'phone', 'email',
                'address', 'city', 'pincode', 'state', 'country', 'remarks', 'is_active', 'is_test_data'
            ]));

            if ($request->has('phones') && is_array($request->input('phones'))) {
                // Replace phone numbers
                $supplier->phones()->delete();
                $phones = $request->input('phones');

                foreach ($phones as $idx => $phoneNum) {
                    $clean = trim((string)$phoneNum);
                    if ($clean === '') continue;

                    SupplierPhone::create([
                        'supplier_id' => $supplier->id,
                        'phone_number' => $clean,
                        'label' => $idx === 0 ? 'Primary' : 'Office',
                        'is_primary' => $idx === 0,
                    ]);
                }
            }
        });

        $supplier->load('phones');

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated successfully.',
            'supplier' => $supplier,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $supplier = Supplier::findOrFail($id);

        // 1. Check if supplier has active assignments on existing projects
        $activeAssignmentsCount = SupplierAssignment::where('supplier_id', $id)
            ->where('status', 'active')
            ->whereHas('project')
            ->count();

        if ($activeAssignmentsCount > 0) {
            // Cannot hard delete active production dependency -> deactivate instead
            $supplier->update(['is_active' => false]);
            event(new \App\Events\SupplierDeactivated($supplier->id, $supplier->name, 'deactivated'));

            return response()->json([
                'success' => true,
                'action' => 'deactivated',
                'message' => "Supplier '{$supplier->name}' has {$activeAssignmentsCount} active assignment(s). Marked as Inactive to protect current production workflow.",
            ]);
        }

        // 2. Check if supplier has historical assignments on existing projects
        $hasHistory = \App\Models\SupplierAssignmentHistory::where(function ($q) use ($id) {
                $q->where('new_supplier_id', $id)
                  ->orWhere('previous_supplier_id', $id);
            })
            ->whereHas('project')
            ->exists()
            || SupplierAssignment::where('supplier_id', $id)->whereHas('project')->exists();

        if ($hasHistory) {
            // Has historical reference -> deactivate to preserve audit history integrity
            $supplier->update(['is_active' => false]);
            event(new \App\Events\SupplierDeactivated($supplier->id, $supplier->name, 'deactivated'));

            return response()->json([
                'success' => true,
                'action' => 'deactivated',
                'message' => "Supplier '{$supplier->name}' has historical allocation records. Marked as Inactive to preserve audit history.",
            ]);
        }

        // 3. Truly unused supplier -> mark inactive and safe soft delete
        $supplierName = $supplier->name;
        $supplier->update(['is_active' => false]);
        $supplier->phones()->delete();
        $supplier->delete();

        event(new \App\Events\SupplierDeactivated($id, $supplierName, 'deleted'));

        return response()->json([
            'success' => true,
            'action' => 'deleted',
            'message' => "Supplier '{$supplierName}' deleted successfully.",
        ]);
    }

    /**
     * List all Supplier Excel import batches.
     */
    public function listImports(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $imports = \App\Models\SupplierImport::with(['importedBy:id,name,email'])
            ->withCount('suppliers')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'imports' => $imports,
        ]);
    }

    /**
     * Delete an Excel import batch and safely handle created suppliers.
     */
    public function deleteImport(Request $request, int $id, SupplierImportService $importService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        try {
            $result = $importService->deleteImport($id, $request->user()?->id);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete supplier import: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Preview Excel file import for Supplier Master.
     */
    public function importPreview(Request $request, SupplierImportService $importService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        if ($request->boolean('use_sample')) {
            $samplePath = base_path('BOM/supplier list 1.xlsx');
            if (!file_exists($samplePath)) {
                return response()->json(['success' => false, 'message' => 'Sample supplier list 1.xlsx file not found in BOM folder.'], 404);
            }
            $preview = $importService->preview($samplePath);
            $preview['filename'] = 'supplier list 1.xlsx';
            return response()->json($preview);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $file = $request->file('file');
            $preview = $importService->preview($file);
            $preview['filename'] = $file->getClientOriginalName();

            return response()->json($preview);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse supplier Excel file: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Commit confirmed Excel rows to PostgreSQL.
     */
    public function commitImport(Request $request, SupplierImportService $importService)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.name' => ['required', 'string'],
            'filename' => ['nullable', 'string'],
            'file_hash' => ['nullable', 'string'],
        ]);

        try {
            $filename = $request->input('filename', 'supplier_list.xlsx');
            $fileHash = $request->input('file_hash');

            $result = $importService->commit(
                $request->input('rows'),
                $request->user()?->id,
                $filename,
                $fileHash
            );

            return response()->json([
                'success' => true,
                'message' => "Successfully imported {$result['created_count']} new supplier(s), updated {$result['updated_count']} existing supplier(s).",
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to commit supplier import: ' . $e->getMessage(),
            ], 500);
        }
    }
}
