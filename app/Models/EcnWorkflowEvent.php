<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcnWorkflowEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'ecn_requirement_id',
        'project_id',
        'ecn_number',
        'user_id',
        'event_type',
        'side',
        'side_display',
        'quantity',
        'previous_state',
        'new_state',
        'remarks',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'metadata' => 'array',
    ];

    public function requirement()
    {
        return $this->belongsTo(EcnRequirement::class, 'ecn_requirement_id');
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
