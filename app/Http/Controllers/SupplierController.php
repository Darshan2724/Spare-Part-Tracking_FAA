<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('code')->orderBy('name')->paginate(50);

        return response()->json($suppliers);
    }

    public function store(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:suppliers,name'],
            'code' => ['nullable', 'string', 'max:50', 'unique:suppliers,code'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $code = trim($request->input('code') ?? '');
        if (!$code) {
            $count = Supplier::withTrashed()->count();
            $code = sprintf('SUP-%03d', $count + 1);
            while (Supplier::withTrashed()->where('code', $code)->exists()) {
                $count++;
                $code = sprintf('SUP-%03d', $count + 1);
            }
        } else {
            $code = strtoupper($code);
        }

        $supplier = Supplier::create([
            'name' => trim($request->input('name')),
            'code' => $code,
            'contact_person' => $request->input('contact_person'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'address' => $request->input('address'),
            'remarks' => $request->input('remarks'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Supplier added successfully.',
            'supplier' => $supplier,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'PURCHASE']) ?: abort(403);

        $supplier = Supplier::findOrFail($id);

        $request->validate([
            'name' => ['required', 'string', 'max:255', "unique:suppliers,name,{$id}"],
            'code' => ['nullable', 'string', 'max:50', "unique:suppliers,code,{$id}"],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $supplier->update($request->only([
            'name', 'code', 'contact_person', 'phone', 'email', 'address', 'remarks', 'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated successfully.',
            'supplier' => $supplier,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()?->hasAnyRole(['ADMIN']) ?: abort(403);

        $supplier = Supplier::findOrFail($id);
        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}
