<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomImportBatch;
use Illuminate\Support\Str;

class ProjectIdentityResolver
{
    /**
     * Normalize a filename or project string for matching.
     */
    public function normalizeString(?string $input): string
    {
        if ($input === null) {
            return '';
        }

        // 1. Remove file extensions
        $clean = preg_replace('/\.(xlsx|xls|csv)$/i', '', trim($input));

        // 2. Remove duplicate/revision markers like (1), (2), ( 1 ), _v1, _v2, _rev1, _copy, _final
        $clean = preg_replace('/\s*\(\s*\d+\s*\)\s*/i', ' ', $clean);
        $clean = preg_replace('/[_\-\s]+(v\d+|rev\d+|revised|copy|final|draft)[_\-\s]*/i', ' ', $clean);

        // 3. Normalize repeated whitespace and punctuation
        $clean = preg_replace('/[_\s]+/', ' ', $clean);
        $clean = strtolower(trim($clean));

        return $clean;
    }

    /**
     * Extract normalized base project code candidates.
     * Workbook contents (sheetProjectCode / projectName) are authoritative.
     */
    public function extractCandidates(string $sheetProjectCode, ?string $filename = null, ?string $projectName = null): array
    {
        $candidates = [];

        if (!empty($sheetProjectCode)) {
            $candidates[] = trim($sheetProjectCode);
            $candidates[] = $this->normalizeString($sheetProjectCode);

            // Extract pattern like FA-279 from "FA-279 NEW MFG"
            if (preg_match('/^(FA[\s\-_]*\d+)/i', $sheetProjectCode, $matches)) {
                $code = strtoupper(preg_replace('/[\s_]+/', '-', $matches[1]));
                $candidates[] = $code;
                $candidates[] = strtolower($code);
            }
        }

        if (!empty($projectName)) {
            $candidates[] = trim($projectName);
            $candidates[] = $this->normalizeString($projectName);
        }

        // Only consider filename if sheetProjectCode is completely empty (legacy safety)
        if (empty($sheetProjectCode) && !empty($filename)) {
            $normFilename = $this->normalizeString($filename);
            $candidates[] = $normFilename;

            // Remove trailing "bom", "mfg bom", "new mfg bom"
            $stripped = preg_replace('/\s*(new\s+mfg\s+bom|mfg\s+bom|bom)\s*$/i', '', $normFilename);
            if (!empty($stripped)) {
                $candidates[] = trim($stripped);
            }

            if (preg_match('/^(fa[\s\-_]*\d+)/i', $filename, $matches)) {
                $code = strtoupper(preg_replace('/[\s_]+/', '-', $matches[1]));
                $candidates[] = $code;
                $candidates[] = strtolower($code);
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Resolve existing Project model by multi-tier matching order.
     *
     * The Project Identity MUST be resolved strictly from the Excel workbook contents
     * (the sheet's Project Code / Project Name), never inferred or guessed from the filename.
     *
     * Matching order:
     * 1. Exact normalized Project Code from sheet in DB
     * 2. Exact normalized Project Name from sheet in DB
     * 3. Canonical FA-xxx regex pattern from sheet Project Code
     * 4. Legacy fallback only if sheet Project Code was completely blank
     *
     * Returns matching Project or null if it's a genuinely new Project.
     */
    public function resolveProject(string $sheetProjectCode, ?string $filename = null, ?string $projectName = null): ?Project
    {
        $candidates = $this->extractCandidates($sheetProjectCode, $filename, $projectName);

        // 1. Exact match on project_code
        foreach ($candidates as $candidate) {
            $project = Project::whereRaw('LOWER(TRIM(project_code)) = ?', [strtolower(trim($candidate))])->first();
            if ($project) {
                return $project;
            }
        }

        // 2. Exact match on project name
        foreach ($candidates as $candidate) {
            $project = Project::whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($candidate))])->first();
            if ($project) {
                return $project;
            }
        }

        // 3. Search by normalized code if format is like FA-279
        if (!empty($sheetProjectCode) && preg_match('/^(FA[\s\-_]*\d+)/i', $sheetProjectCode, $matches)) {
            $canonicalCode = strtoupper(preg_replace('/[\s_]+/', '-', $matches[1]));
            $project = Project::whereRaw('LOWER(TRIM(project_code)) = ?', [strtolower($canonicalCode)])
                ->orWhereRaw('LOWER(TRIM(name)) = ?', [strtolower($canonicalCode)])
                ->first();
            if ($project) {
                return $project;
            }
        }

        // If sheetProjectCode was present, it is authoritative: DO NOT infer from historical batch filenames!
        if (!empty($sheetProjectCode)) {
            return null;
        }

        // 4. Check historical batch project codes (only if sheet had no project code)
        if (!empty($filename)) {
            $normFile = $this->normalizeString($filename);
            $historicalBatch = BomImportBatch::whereNotNull('project_id')
                ->where('status', 'completed')
                ->get()
                ->first(function ($batch) use ($normFile) {
                    $orig = $this->normalizeString($batch->original_filename ?? $batch->filename);
                    return !empty($orig) && $orig === $normFile;
                });

            if ($historicalBatch && $historicalBatch->project) {
                return $historicalBatch->project;
            }
        }

        return null;
    }
}
