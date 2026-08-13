<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseQueueItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'qc_inspection_id',
        'project_id',
        'standard_part_no',
        'side',
        'rejected_quantity',
        'rejection_reason',
        'rejected_by',
        'rejected_at',
        'status',
        'exported_at',
        'exported_by',
        'remarks',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function exporter()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
