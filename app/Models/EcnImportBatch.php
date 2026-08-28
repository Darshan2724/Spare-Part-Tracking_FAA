<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EcnImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'filename',
        'original_filename',
        'file_hash',
        'file_size_bytes',
        'imported_by',
        'total_rows',
        'successful_rows',
        'failed_rows',
        'added_rows_count',
        'updated_rows_count',
        'skipped_rows_count',
        'conflict_rows_count',
        'ecn_numbers',
        'diff_summary',
        'errors',
        'status',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'total_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'added_rows_count' => 'integer',
        'updated_rows_count' => 'integer',
        'skipped_rows_count' => 'integer',
        'conflict_rows_count' => 'integer',
        'ecn_numbers' => 'array',
        'diff_summary' => 'array',
        'errors' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function requirements()
    {
        return $this->hasMany(EcnRequirement::class, 'ecn_import_batch_id');
    }
}
