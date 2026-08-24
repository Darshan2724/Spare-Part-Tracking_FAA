<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BomImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'filename',
        'file_hash',
        'file_size_bytes',
        'original_filename',
        'project_codes',
        'imported_by',
        'total_rows',
        'successful_rows',
        'failed_rows',
        'added_rows_count',
        'updated_rows_count',
        'skipped_rows_count',
        'conflict_rows_count',
        'diff_summary',
        'import_type',
        'errors',
        'status',
    ];

    protected $casts = [
        'errors' => 'array',
        'project_codes' => 'array',
        'diff_summary' => 'array',
        'file_size_bytes' => 'integer',
        'added_rows_count' => 'integer',
        'updated_rows_count' => 'integer',
        'skipped_rows_count' => 'integer',
        'conflict_rows_count' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class, 'import_batch_id');
    }
}
