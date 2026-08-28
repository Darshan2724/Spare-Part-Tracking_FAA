<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcnReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'ecn_requirement_id',
        'project_id',
        'ecn_number',
        'side',
        'side_display',
        'received_quantity',
        'status',
        'remarks',
        'processed_by',
    ];

    protected $casts = [
        'received_quantity' => 'integer',
    ];

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

    public function workflowRecords()
    {
        return $this->hasMany(EcnWorkflowRecord::class, 'ecn_receipt_item_id');
    }
}
