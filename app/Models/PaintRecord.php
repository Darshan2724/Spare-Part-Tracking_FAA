<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaintRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'qc_inspection_id',
        'side',
        'quantity',
        'painted_by',
        'status',
        'started_at',
        'completed_at',
        'paint_type',
        'color_code',
        'remarks',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function qcInspection()
    {
        return $this->belongsTo(QcInspection::class);
    }

    public function painter()
    {
        return $this->belongsTo(User::class, 'painted_by');
    }
}
