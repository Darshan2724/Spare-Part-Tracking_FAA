<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'bom_item_id',
        'project_id',
        'user_id',
        'department_id',
        'event_type',
        'side',
        'quantity',
        'previous_state',
        'new_state',
        'remarks',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function bomItem()
    {
        return $this->belongsTo(BomItem::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
