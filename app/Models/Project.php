<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_code',
        'name',
        'description',
        'customer_name',
        'status',
        'start_date',
        'target_completion_date',
        'actual_completion_date',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'actual_completion_date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class);
    }

    public function importBatches()
    {
        return $this->hasMany(BomImportBatch::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function purchaseQueueItems()
    {
        return $this->hasMany(PurchaseQueueItem::class);
    }

    public function ecnRequirements()
    {
        return $this->hasMany(EcnRequirement::class);
    }

    public function ecnImportBatches()
    {
        return $this->hasMany(EcnImportBatch::class);
    }

    public function ecnReceiptItems()
    {
        return $this->hasMany(EcnReceiptItem::class);
    }
}
