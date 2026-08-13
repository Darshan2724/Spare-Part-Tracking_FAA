<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BomRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'side',
        'required_quantity',
    ];

    protected $casts = [
        'required_quantity' => 'integer',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }
}
