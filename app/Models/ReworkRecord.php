<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReworkRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'qc_inspection_id',
        'bom_item_id',
        'side',
        'quantity',
        'assigned_to',
        'status',
        'started_at',
        'completed_at',
        'rework_description',
        'completion_notes',
        'cycle_number',
    ];

    public function qcInspection()
    {
        return $this->belongsTo(QcInspection::class);
    }

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
