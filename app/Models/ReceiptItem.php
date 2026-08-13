<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_id',
        'bom_item_id',
        'side',
        'received_quantity',
        'status',
        'remarks',
    ];

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function inspection()
    {
        return $this->hasOne(QcInspection::class);
    }
}
