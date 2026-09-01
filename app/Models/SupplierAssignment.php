<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'jig_no',
        'unit_no',
        'category',
        'supplier_id',
        'assignment_date',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'assignment_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function history()
    {
        return $this->hasMany(SupplierAssignmentHistory::class, 'supplier_assignment_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
