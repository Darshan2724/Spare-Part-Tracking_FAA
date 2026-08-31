<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcnWorkflowRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'ecn_receipt_item_id',
        'ecn_requirement_id',
        'project_id',
        'ecn_number',
        'department',
        'action',
        'side',
        'side_display',
        'quantity',
        'destination',
        'approved_quantity',
        'rejected_quantity',
        'rework_quantity',
        'remarks',
        'processed_by',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'approved_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'rework_quantity' => 'integer',
    ];

    protected static function booted()
    {
        static::saving(function ($model) {
            if (empty($model->side_display)) {
                $model->side_display = in_array(strtoupper(trim($model->side ?? '')), ['LH', 'LA', 'AL', 'L', 'LEFT']) ? 'LH' : 'RH';
            }
        });
    }

    public function receiptItem()
    {
        return $this->belongsTo(EcnReceiptItem::class, 'ecn_receipt_item_id');
    }

    public function requirement()
    {
        return $this->belongsTo(EcnRequirement::class, 'ecn_requirement_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
