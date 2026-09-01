<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'pincode',
        'state',
        'country',
        'is_active',
        'is_test_data',
        'remarks',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_test_data' => 'boolean',
    ];

    public function phones()
    {
        return $this->hasMany(SupplierPhone::class)->orderBy('id');
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class);
    }

    public function supplierAssignments()
    {
        return $this->hasMany(SupplierAssignment::class);
    }

    public function activeSupplierAssignments()
    {
        return $this->hasMany(SupplierAssignment::class)->where('status', 'active');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
