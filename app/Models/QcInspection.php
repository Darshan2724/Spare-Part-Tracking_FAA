<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QcInspection extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'receipt_item_id',
        'rework_record_id',
        'side',
        'inspected_quantity',
        'approved_quantity',
        'rejected_quantity',
        'rework_quantity',
        'result',
        'destination',
        'rejection_reason',
        'rework_reason',
        'remarks',
        'is_reinspection',
        'inspected_by',
        'inspection_date',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function receiptItem()
    {
        return $this->belongsTo(ReceiptItem::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function reworkRecord()
    {
        return $this->belongsTo(ReworkRecord::class);
    }

    public function paintRecord()
    {
        return $this->hasOne(PaintRecord::class, 'qc_inspection_id');
    }

    public function paintRecords()
    {
        return $this->hasMany(PaintRecord::class, 'qc_inspection_id');
    }

    public function assemblyRecord()
    {
        return $this->hasOne(AssemblyRecord::class, 'qc_inspection_id');
    }

    public function assemblyRecords()
    {
        return $this->hasMany(AssemblyRecord::class, 'qc_inspection_id');
    }
}
