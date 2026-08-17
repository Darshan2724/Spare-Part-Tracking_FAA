<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'severity',
        'category',
        'module',
        'user_id',
        'user_role',
        'trace_id',
        'endpoint',
        'method',
        'status_code',
        'ip_address',
        'user_agent',
        'message',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'details' => 'array',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeSeverity($query, $severity)
    {
        return $query->where('severity', strtoupper($severity));
    }

    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeModule($query, $module)
    {
        return $query->where('module', $module);
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
