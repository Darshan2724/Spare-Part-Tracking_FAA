<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierAssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'supplier_assignment_history';

    public $timestamps = false; // Has created_at only

    protected $fillable = [
        'supplier_assignment_id',
        'project_id',
        'jig_no',
        'unit_no',
        'category',
        'previous_supplier_id',
        'new_supplier_id',
        'previous_date',
        'new_date',
        'action',
        'changed_by',
        'created_at',
    ];

    protected $casts = [
        'previous_date' => 'date',
        'new_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(SupplierAssignment::class, 'supplier_assignment_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function previousSupplier()
    {
        return $this->belongsTo(Supplier::class, 'previous_supplier_id');
    }

    public function newSupplier()
    {
        return $this->belongsTo(Supplier::class, 'new_supplier_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
