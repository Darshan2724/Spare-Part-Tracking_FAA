<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierImport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'filename',
        'file_hash',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'imported_by',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
    ];

    /**
     * Suppliers created or updated by this import batch.
     */
    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'supplier_import_id');
    }

    /**
     * Only suppliers that were newly created by this import (not pre-existing).
     */
    public function createdSuppliers()
    {
        return $this->hasMany(Supplier::class, 'supplier_import_id');
    }

    /**
     * The user who performed this import.
     */
    public function importedBy()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
