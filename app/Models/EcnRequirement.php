<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EcnRequirement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'ecn_import_batch_id',
        'ecn_number',
        'jig_no',
        'unit_no',
        'part_no',
        'side',
        'side_display',
        'side_family',
        'required_qty',
        'received_qty',
        'current_state',
    ];

    protected $casts = [
        'required_qty' => 'integer',
        'received_qty' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(EcnImportBatch::class, 'ecn_import_batch_id');
    }

    public function receiptItems()
    {
        return $this->hasMany(EcnReceiptItem::class, 'ecn_requirement_id');
    }

    public function workflowRecords()
    {
        return $this->hasMany(EcnWorkflowRecord::class, 'ecn_requirement_id');
    }

    public function workflowEvents()
    {
        return $this->hasMany(EcnWorkflowEvent::class, 'ecn_requirement_id');
    }
}
