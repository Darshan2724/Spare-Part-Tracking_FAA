<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BomItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'jig_no',
        'unit_no',
        'item_no',
        'standard_part_no',
        'size',
        'supplier_id',
        'supplier_name_raw',
        'remarks',
        'proj_spec_yn',
        'import_batch_id',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requirements()
    {
        return $this->hasMany(BomRequirement::class);
    }

    public function rhRequirement()
    {
        return $this->hasOne(BomRequirement::class)->where('side', 'RH');
    }

    public function lhRequirement()
    {
        return $this->hasOne(BomRequirement::class)->where('side', 'LH');
    }

    public function commonRequirement()
    {
        return $this->hasOne(BomRequirement::class)->where('side', 'COMMON');
    }

    public function receiptItems()
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function qcInspections()
    {
        return $this->hasMany(QcInspection::class);
    }

    public function reworkRecords()
    {
        return $this->hasMany(ReworkRecord::class);
    }

    public function purchaseQueueItems()
    {
        return $this->hasMany(PurchaseQueueItem::class);
    }

    public function paintRecords()
    {
        return $this->hasMany(PaintRecord::class);
    }

    public function assemblyRecords()
    {
        return $this->hasMany(AssemblyRecord::class);
    }

    public function workflowEvents()
    {
        return $this->hasMany(WorkflowEvent::class);
    }
}
