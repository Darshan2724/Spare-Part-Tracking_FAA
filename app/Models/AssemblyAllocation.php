<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssemblyAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'bom_item_id',
        'side',
        'bom_type',
        'allocated_quantity',
        'status',
        'allocated_by',
        'remarks',
    ];

    protected $casts = [
        'allocated_quantity' => 'integer',
        'project_id' => 'integer',
        'bom_item_id' => 'integer',
        'allocated_by' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function allocator()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForBomType($query, string $type)
    {
        return $query->where('bom_type', strtoupper($type));
    }
}
