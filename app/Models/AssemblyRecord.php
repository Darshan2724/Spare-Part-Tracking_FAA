<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssemblyRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'paint_record_id',
        'side',
        'quantity',
        'assembled_by',
        'status',
        'started_at',
        'completed_at',
        'remarks',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function paintRecord()
    {
        return $this->belongsTo(PaintRecord::class);
    }

    public function assembler()
    {
        return $this->belongsTo(User::class, 'assembled_by');
    }
}
