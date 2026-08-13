<?php

namespace App\Services;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class BomImportService
{
    public function previewFromPath(string $path, ?string $filename = null): array
    {
        $rows = $this->readRows($path, $filename);

        $preview = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRow($row, $index + 2);
            $preview[] = $normalized;

            if (!empty($normalized['errors'])) {
                $errors = array_merge($errors, $normalized['errors']);
            }
        }

        return [
            'filename' => $filename ?? basename($path),
            'sheet' => 'Sheet1',
            'rows' => $preview,
            'errors' => $errors,
        ];
    }

    public function importFromPath(string $path, array $data, int $userId): array
    {
        $filename = $data['filename'] ?? basename($path);
        $projectCode = $data['project_code'] ?? null;
        $projectName = $data['project_name'] ?? null;

        $rows = $this->readRows($path, $filename);
        $preview = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRow($row, $index + 2);
            $preview[] = $normalized;

            if (!empty($normalized['errors'])) {
                $errors = array_merge($errors, $normalized['errors']);
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'BOM contains validation errors.',
                'errors' => $errors,
            ];
        }

        return DB::transaction(function () use ($path, $filename, $projectCode, $projectName, $preview, $userId) {
            $project = Project::firstOrCreate(
                ['project_code' => $projectCode ?: 'PROJ-' . strtoupper(Str::random(6))],
                ['name' => $projectName ?: $projectCode ?: 'New Project', 'status' => 'active']
            );

            if ($projectName && $project->name !== $projectName) {
                $project->update(['name' => $projectName]);
            }

            $batch = BomImportBatch::create([
                'project_id' => $project->id,
                'filename' => $filename,
                'imported_by' => $userId,
                'total_rows' => count($preview),
                'successful_rows' => count($preview),
                'status' => 'completed',
            ]);

            foreach ($preview as $row) {
                $supplierId = null;
                if (!empty($row['supplier'])) {
                    $supplier = Supplier::firstOrCreate(
                        ['name' => $row['supplier']],
                        ['code' => Str::slug($row['supplier']), 'is_active' => true]
                    );
                    $supplierId = $supplier->id;
                }

                $bomItem = BomItem::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'standard_part_no' => $row['standard_part_no'],
                    ],
                    [
                        'item_no' => $row['item_no'],
                        'size' => $row['size'],
                        'supplier_id' => $supplierId,
                        'supplier_name_raw' => $row['supplier'],
                        'remarks' => $row['remarks'],
                        'parent' => $row['parent'],
                        'proj_spec_yn' => $row['proj_spec_yn'],
                        'import_batch_id' => $batch->id,
                    ]
                );

                foreach (['RH', 'LH'] as $side) {
                    $quantity = $side === 'RH' ? (int) ($row['qty_rh'] ?? 0) : (int) ($row['qty_lh'] ?? 0);
                    if ($quantity > 0) {
                        BomRequirement::updateOrCreate(
                            [
                                'bom_item_id' => $bomItem->id,
                                'side' => $side,
                            ],
                            [
                                'required_quantity' => $quantity,
                            ]
                        );
                    }
                }
            }

            return [
                'success' => true,
                'message' => 'BOM imported successfully.',
                'project_id' => $project->id,
                'batch_id' => $batch->id,
                'imported_rows' => count($preview),
            ];
        });
    }

    protected function readRows(string $path, ?string $filename = null): array
    {
        $targetForExtension = $filename ?: $path;
        $extension = strtolower(pathinfo($targetForExtension, PATHINFO_EXTENSION));

        if ($extension === 'xlsx') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        } elseif ($extension === 'xls') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        } else {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException('Unsupported BOM file type.');
            }
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($worksheet->toArray() as $row) {
            $rows[] = $row;
        }

        return $this->extractDataRows($rows);
    }

    protected function extractDataRows(array $rows): array
    {
        $normalized = [];
        $headerIndexes = [];

        foreach ($rows as $index => $row) {
            $cleaned = array_map(function ($value) {
                return trim((string) ($value ?? ''));
            }, $row);

            if ($index === 0) {
                $headerIndexes = $this->findHeaderIndexes($cleaned);
                continue;
            }

            if ($this->isEmptyRow($cleaned)) {
                continue;
            }

            $normalized[] = [
                'item_no' => $this->getValue($cleaned, $headerIndexes, 'ItemNo'),
                'standard_part_no' => $this->getValue($cleaned, $headerIndexes, 'StandardPartNo'),
                'qty_rh' => $this->getValue($cleaned, $headerIndexes, 'QTYRH'),
                'qty_lh' => $this->getValue($cleaned, $headerIndexes, 'QTYLH'),
                'size' => $this->getValue($cleaned, $headerIndexes, 'SIZE'),
                'supplier' => $this->getValue($cleaned, $headerIndexes, 'Supplier'),
                'remarks' => $this->getValue($cleaned, $headerIndexes, 'Remarks'),
                'parent' => $this->getValue($cleaned, $headerIndexes, 'Parent'),
                'proj_spec_yn' => $this->getValue($cleaned, $headerIndexes, 'ProjSpecYN'),
            ];
        }

        return $normalized;
    }

    protected function findHeaderIndexes(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $key = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $header)));
            
            // Map variations
            if (in_array($key, ['ITEMNO', 'ITEM', 'SRNO', 'NO'], true)) {
                $map['ITEMNO'] = $index;
            } elseif (in_array($key, ['STANDARDPARTNO', 'STANDARDPARTNUMBER', 'PARTNO', 'PARTNUMBER', 'PARTNAME'], true)) {
                $map['STANDARDPARTNO'] = $index;
            } elseif (in_array($key, ['QTYRH', 'QUANTITYRH', 'RHQTY', 'RH'], true)) {
                $map['QTYRH'] = $index;
            } elseif (in_array($key, ['QTYLH', 'QUANTITYLH', 'LHQTY', 'LH'], true)) {
                $map['QTYLH'] = $index;
            } elseif (in_array($key, ['SIZE', 'DIMENSION', 'SPECIFICATION'], true)) {
                $map['SIZE'] = $index;
            } elseif (in_array($key, ['SUPPLIER', 'SUPPLIERNAME', 'VENDOR'], true)) {
                $map['SUPPLIER'] = $index;
            } elseif (in_array($key, ['REMARKS', 'REMARK', 'NOTE', 'NOTES'], true)) {
                $map['REMARKS'] = $index;
            } elseif (in_array($key, ['PARENT', 'PROJECT', 'ASSEMBLY'], true)) {
                $map['PARENT'] = $index;
            } elseif (in_array($key, ['PROJSPECYN', 'PROJSPEC'], true)) {
                $map['PROJSPECYN'] = $index;
            }
        }

        return $map;
    }

    protected function getValue(array $row, array $headerIndexes, string $field): ?string
    {
        $column = strtoupper($field);
        $index = $headerIndexes[$column] ?? null;
        if ($index === null || !isset($row[$index])) {
            return null;
        }

        return trim((string) $row[$index]);
    }

    protected function normalizeRow(array $row, int $lineNumber): array
    {
        $errors = [];
        $qtyRh = $this->parseQuantity($row['qty_rh'] ?? null);
        $qtyLh = $this->parseQuantity($row['qty_lh'] ?? null);

        if (empty($row['standard_part_no'])) {
            $errors[] = "Line {$lineNumber}: StandardPartNo is required.";
        }

        $parent = !empty($row['parent']) ? $row['parent'] : 'MAIN';

        if ($qtyRh === 0 && $qtyLh === 0) {
            $errors[] = "Line {$lineNumber}: QTYRH and QTYLH cannot both be zero.";
        }

        return [
            'item_no' => $row['item_no'] ?? null,
            'standard_part_no' => $row['standard_part_no'] ?? null,
            'qty_rh' => $qtyRh,
            'qty_lh' => $qtyLh,
            'size' => $row['size'] ?? null,
            'supplier' => $row['supplier'] ?? null,
            'remarks' => $row['remarks'] ?? null,
            'parent' => $parent,
            'proj_spec_yn' => $row['proj_spec_yn'] ?? null,
            'errors' => $errors,
        ];
    }

    protected function parseQuantity(?string $value): int
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    protected function isEmptyRow(array $row): bool
    {
        return empty(array_filter($row, fn ($value) => trim((string) $value) !== ''));
    }
}
