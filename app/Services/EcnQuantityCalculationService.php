<?php

namespace App\Services;

use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EcnQuantityCalculationService
{
    public const VALID_RECEIPT_STATUSES = [
        'received',
        'store_received',
        'pending_qc',
        'sent_to_qc',
        'qc_received',
        'qc_approved',
        'qc_rejected',
        'qc_rework',
        'paint_completed',
        'assembly_completed',
    ];

    /**
     * Get single aggregate ECN count for main dashboard KPI card.
     */
    public function getEcnTotalForDashboardCard(array $filters = []): int
    {
        $query = EcnRequirement::query();

        if (!empty($filters['project_id'])) {
            $query->where('project_id', (int)$filters['project_id']);
        }

        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $query->where(function ($q) use ($side) {
                $q->where('side', $side)
                  ->orWhere('side_display', $side);
            });
        }

        return (int)$query->sum('required_qty');
    }

    /**
     * Calculate 9 executive KPI cards for dedicated ECN Reports page.
     */
    public function calculateEcnDashboardSummary(array $filters = []): array
    {
        $reqQuery = EcnRequirement::query();
        $receiptQuery = EcnReceiptItem::query()->whereIn('status', self::VALID_RECEIPT_STATUSES);
        $wfQuery = EcnWorkflowRecord::query();

        if (!empty($filters['project_id'])) {
            $projId = (int)$filters['project_id'];
            $reqQuery->where('project_id', $projId);
            $receiptQuery->where('project_id', $projId);
            $wfQuery->where('project_id', $projId);
        }

        if (!empty($filters['ecn_number'])) {
            $ecnNum = trim($filters['ecn_number']);
            $reqQuery->where('ecn_number', $ecnNum);
            $receiptQuery->where('ecn_number', $ecnNum);
            $wfQuery->where('ecn_number', $ecnNum);
        }

        if (!empty($filters['jig_no'])) {
            $jig = trim($filters['jig_no']);
            $reqQuery->where('jig_no', $jig);
        }

        if (!empty($filters['unit_no'])) {
            $unit = trim($filters['unit_no']);
            $reqQuery->where('unit_no', $unit);
        }

        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $reqQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
            $receiptQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
            $wfQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
        }

        if (!empty($filters['date_from'])) {
            $reqQuery->where('created_at', '>=', $filters['date_from']);
            $receiptQuery->where('created_at', '>=', $filters['date_from']);
            $wfQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $reqQuery->where('created_at', '<=', $filters['date_to']);
            $receiptQuery->where('created_at', '<=', $filters['date_to']);
            $wfQuery->where('created_at', '<=', $filters['date_to']);
        }

        $totalParts = (int)$reqQuery->sum('required_qty');
        $totalReceived = (int)$receiptQuery->sum('received_quantity');
        $partsPending = max(0, $totalParts - $totalReceived);

        // Store Bay (received in store, not yet sent to QC)
        $storeItems = (clone $receiptQuery)->whereIn('status', ['received', 'store_received'])->sum('received_quantity');

        // QC Queue (sent to QC, awaiting inspection)
        $qcItems = (clone $receiptQuery)->whereIn('status', ['sent_to_qc', 'qc_received'])->sum('received_quantity');

        // QC Rejected
        $qcRejected = (int)(clone $wfQuery)->where('department', 'QC')->where('action', 'qc_rejected')->sum('rejected_quantity');

        // Rework Queue
        $reworkActive = (int)(clone $wfQuery)->where('department', 'REWORK')->where('status', 'in_progress')->sum('quantity');

        // Paint Shop Queue
        $paintActive = (int)(clone $wfQuery)->where('department', 'PAINT')->where('status', 'in_progress')->sum('quantity');

        // Assembly Queue
        $assemblyActive = (int)(clone $wfQuery)->where('department', 'ASSEMBLY')->where('status', 'in_progress')->sum('quantity');

        // Assembly Completed
        $assemblyCompleted = (int)(clone $wfQuery)->where('department', 'ASSEMBLY')->where('action', 'assembly_completed')->sum('quantity');

        $completionPct = $totalParts > 0 ? round(($assemblyCompleted / $totalParts) * 100, 1) : 0;

        return [
            'total_parts' => $totalParts,
            'total_received' => $totalReceived,
            'total_parts_received' => $totalReceived,
            'parts_pending' => $partsPending,
            'parts_in_store' => (int)$storeItems,
            'parts_in_qc' => (int)$qcItems,
            'qc_rejected' => $qcRejected,
            'parts_in_rework' => $reworkActive,
            'parts_in_paint' => $paintActive,
            'parts_in_assembly' => $assemblyActive,
            'assembly_completed' => $assemblyCompleted,
            'completion_pct' => $completionPct,
        ];
    }

    /**
     * Get ECN counts for hierarchy cards at any level with optional department residency filtering.
     */
    public function getEcnCountsForHierarchy(
        ?int $projectId = null,
        ?string $jigNo = null,
        ?string $unitNo = null,
        ?string $sideDisplay = null,
        string $department = 'manager'
    ): int {
        $dept = strtolower(trim($department));

        if (!$projectId) {
            $q = EcnRequirement::query();
            if ($dept !== 'manager' && !empty($dept)) {
                if ($dept === 'store') {
                    $q->where(function ($sq) {
                        $sq->where('current_state', 'PENDING')
                           ->orWhereRaw('required_qty > received_qty');
                    });
                    return max(0, (int)$q->sum('required_qty') - (int)$q->sum('received_qty'));
                } elseif ($dept === 'store_resident' || $dept === 'qc_arrival') {
                    $q->whereIn('current_state', ['STORE', 'SENT_TO_QC']);
                    return (int)$q->sum('received_qty');
                } elseif ($dept === 'qc' || $dept === 'qc_inspection') {
                    $q->where('current_state', 'QC');
                    return (int)$q->sum('received_qty');
                } elseif ($dept === 'rework') {
                    $q->where('current_state', 'REWORK');
                    return (int)$q->sum('received_qty');
                } elseif ($dept === 'paint') {
                    $q->where('current_state', 'PAINT');
                    return (int)$q->sum('received_qty');
                } elseif ($dept === 'assembly') {
                    $q->whereIn('current_state', ['ASSEMBLY', 'ASSEMBLY_COMPLETED']);
                    return (int)$q->sum('received_qty');
                }
                return (int)$q->sum('received_qty');
            }
            return (int)$q->sum('required_qty');
        }

        if ($dept !== 'manager' && !empty($dept)) {
            $deptMap = $this->preloadProjectDepartmentEcnMap($projectId, $dept);
            $jKey = $jigNo !== null ? strtoupper(trim($jigNo)) : null;

            if ($jigNo !== null && $unitNo !== null && $sideDisplay !== null) {
                $rawU = trim(str_ireplace('unit', '', $unitNo));
                $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;
                $side = strtoupper(trim($sideDisplay));
                $sideNorm = in_array($side, ['LH', 'LA', 'AL', 'L', 'LEFT']) ? 'LH' : 'RH';

                return $deptMap['sides'][$jigNo . '|' . $rawU . '|' . $side]
                    ?? ($deptMap['sides'][$jKey . '|' . $rawU . '|' . $side]
                    ?? ($deptMap['sides'][$jigNo . '|Unit ' . $rawU . '|' . $side]
                    ?? ($deptMap['sides'][$jKey . '|Unit ' . $rawU . '|' . $side]
                    ?? ($deptMap['sides'][$jigNo . '|Unit ' . $paddedU . '|' . $side]
                    ?? ($deptMap['sides'][$jKey . '|Unit ' . $paddedU . '|' . $side]
                    ?? ($deptMap['sides'][$jigNo . '|' . $rawU . '|' . $sideNorm]
                    ?? ($deptMap['sides'][$jKey . '|' . $rawU . '|' . $sideNorm]
                    ?? 0)))))));
            } elseif ($jigNo !== null && $unitNo !== null) {
                $rawU = trim(str_ireplace('unit', '', $unitNo));
                $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;

                return $deptMap['units'][$jigNo . '|' . $rawU]
                    ?? ($deptMap['units'][$jKey . '|' . $rawU]
                    ?? ($deptMap['units'][$jigNo . '|Unit ' . $rawU]
                    ?? ($deptMap['units'][$jKey . '|Unit ' . $rawU]
                    ?? ($deptMap['units'][$jigNo . '|Unit ' . $paddedU]
                    ?? ($deptMap['units'][$jKey . '|Unit ' . $paddedU]
                    ?? 0)))));
            } elseif ($jigNo !== null) {
                return $deptMap['jigs'][$jigNo] ?? ($deptMap['jigs'][$jKey] ?? 0);
            }
            return $deptMap['project_total'] ?? 0;
        }

        $query = EcnRequirement::where('project_id', $projectId);

        if ($jigNo !== null) {
            $query->where(function ($q) use ($jigNo) {
                $q->where('jig_no', $jigNo)
                  ->orWhere('jig_no', strtoupper(trim($jigNo)));
            });
        }

        if ($unitNo !== null) {
            $rawU = trim(str_ireplace('unit', '', $unitNo));
            $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;
            $query->where(function ($q) use ($unitNo, $rawU, $paddedU) {
                $q->where('unit_no', $unitNo)
                  ->orWhere('unit_no', $rawU)
                  ->orWhere('unit_no', 'Unit ' . $rawU)
                  ->orWhere('unit_no', 'Unit ' . $paddedU);
            });
        }

        if ($sideDisplay !== null) {
            $side = strtoupper(trim($sideDisplay));
            $query->where(function ($q) use ($side) {
                $q->where('side_display', $side)->orWhere('side', $side);
            });
        }

        return (int)$query->sum('required_qty');
    }

    public static function formatEcnSummaryDisplay(array $ecnSummary): ?string
    {
        if (empty($ecnSummary)) {
            return null;
        }
        $totalParts = 0;
        foreach ($ecnSummary as $item) {
            $totalParts += (int)($item['part_count'] ?? 0);
        }
        if ($totalParts <= 0) {
            return null;
        }
        $unitWord = $totalParts === 1 ? 'part' : 'parts';
        return "ECN ({$totalParts} {$unitWord})";
    }

    public static function formatEcnDetailedDisplay(array $ecnSummary): ?string
    {
        if (empty($ecnSummary)) {
            return null;
        }
        $parts = [];
        foreach ($ecnSummary as $item) {
            $num = $item['ecn_number'];
            $cnt = (int)$item['part_count'];
            $unitWord = $cnt === 1 ? 'part' : 'parts';
            $parts[] = "{$num} • {$cnt} {$unitWord}";
        }
        return implode(', ', $parts);
    }

    /**
     * Bulk preloader for project ECN hierarchy counts to prevent N+1 queries.
     */
    public function preloadProjectEcnHierarchyMap(int $projectId): array
    {
        $reqs = EcnRequirement::where('project_id', $projectId)->get();

        $map = [
            'project_total' => 0,
            'project_ecn_numbers' => [],
            'project_ecn_breakdown' => [],
            'project_ecn_summary' => [],
            'project_ecn_display' => null,
            'jigs' => [],
            'jig_ecn_numbers' => [],
            'jig_ecn_breakdown' => [],
            'jig_ecn_summary' => [],
            'jig_ecn_display' => [],
            'units' => [],
            'unit_ecn_numbers' => [],
            'unit_ecn_breakdown' => [],
            'unit_ecn_summary' => [],
            'unit_ecn_display' => [],
            'sides' => [],
        ];

        foreach ($reqs as $r) {
            $qty = (int)$r->required_qty;
            if ($qty <= 0) continue;

            $ecnNum = trim((string)$r->ecn_number);

            $map['project_total'] += $qty;
            if ($ecnNum !== '') {
                if (!in_array($ecnNum, $map['project_ecn_numbers'])) {
                    $map['project_ecn_numbers'][] = $ecnNum;
                }
                $map['project_ecn_breakdown'][$ecnNum] = ($map['project_ecn_breakdown'][$ecnNum] ?? 0) + $qty;
            }

            // Jig aliases
            $jigNo = (string)$r->jig_no;
            $jigUpper = strtoupper(trim($jigNo));
            $jigAliases = array_unique([$jigNo, $jigUpper]);

            foreach ($jigAliases as $jKey) {
                $map['jigs'][$jKey] = ($map['jigs'][$jKey] ?? 0) + $qty;
                if ($ecnNum !== '') {
                    $map['jig_ecn_numbers'][$jKey] = $map['jig_ecn_numbers'][$jKey] ?? [];
                    if (!in_array($ecnNum, $map['jig_ecn_numbers'][$jKey])) {
                        $map['jig_ecn_numbers'][$jKey][] = $ecnNum;
                    }
                    $map['jig_ecn_breakdown'][$jKey][$ecnNum] = ($map['jig_ecn_breakdown'][$jKey][$ecnNum] ?? 0) + $qty;
                }
            }

            // Unit (key: jig|unit)
            $unitNo = (string)$r->unit_no;
            $rawUnit = trim(str_ireplace('unit', '', $unitNo));
            $paddedUnit = is_numeric($rawUnit) ? sprintf('%02d', (int)$rawUnit) : $rawUnit;

            $unitAliases = [];
            foreach ($jigAliases as $jKey) {
                $unitAliases[] = $jKey . '|' . $unitNo;
                $unitAliases[] = $jKey . '|' . $rawUnit;
                $unitAliases[] = $jKey . '|Unit ' . $rawUnit;
                $unitAliases[] = $jKey . '|Unit ' . $paddedUnit;
            }
            $unitAliases = array_unique($unitAliases);

            foreach ($unitAliases as $uKey) {
                $map['units'][$uKey] = ($map['units'][$uKey] ?? 0) + $qty;
                if ($ecnNum !== '') {
                    $map['unit_ecn_numbers'][$uKey] = $map['unit_ecn_numbers'][$uKey] ?? [];
                    if (!in_array($ecnNum, $map['unit_ecn_numbers'][$uKey])) {
                        $map['unit_ecn_numbers'][$uKey][] = $ecnNum;
                    }
                    $map['unit_ecn_breakdown'][$uKey][$ecnNum] = ($map['unit_ecn_breakdown'][$uKey][$ecnNum] ?? 0) + $qty;
                }
            }

            // Side (key: jig|unit|side)
            $sideDisp = strtoupper(trim($r->side_display ?: $r->side));
            $sideNorm = in_array($sideDisp, ['LH', 'LA', 'AL', 'L', 'LEFT']) ? 'LH' : 'RH';

            foreach ($unitAliases as $uKey) {
                $map['sides'][$uKey . '|' . $r->side_display] = ($map['sides'][$uKey . '|' . $r->side_display] ?? 0) + $qty;
                $map['sides'][$uKey . '|' . $sideNorm] = ($map['sides'][$uKey . '|' . $sideNorm] ?? 0) + $qty;
                if ($r->side && $r->side !== $r->side_display && $r->side !== $sideNorm) {
                    $map['sides'][$uKey . '|' . $r->side] = ($map['sides'][$uKey . '|' . $r->side] ?? 0) + $qty;
                }
            }
        }

        // Build Project level summary & display
        foreach ($map['project_ecn_breakdown'] as $num => $cnt) {
            $map['project_ecn_summary'][] = ['ecn_number' => $num, 'part_count' => $cnt];
        }
        $map['project_ecn_display'] = self::formatEcnSummaryDisplay($map['project_ecn_summary']);

        // Build Jig level summary & display
        foreach ($map['jig_ecn_breakdown'] as $jigKey => $breakdown) {
            $jSum = [];
            foreach ($breakdown as $num => $cnt) {
                $jSum[] = ['ecn_number' => $num, 'part_count' => $cnt];
            }
            $map['jig_ecn_summary'][$jigKey] = $jSum;
            $map['jig_ecn_display'][$jigKey] = self::formatEcnSummaryDisplay($jSum);
        }

        // Build Unit level summary & display
        foreach ($map['unit_ecn_breakdown'] as $uKey => $breakdown) {
            $uSum = [];
            foreach ($breakdown as $num => $cnt) {
                $uSum[] = ['ecn_number' => $num, 'part_count' => $cnt];
            }
            $map['unit_ecn_summary'][$uKey] = $uSum;
            $map['unit_ecn_display'][$uKey] = self::formatEcnSummaryDisplay($uSum);
        }

        return $map;
    }

    /**
     * Preloads department-specific ECN hierarchy counts.
     * Counts only ECN items resident/eligible in $department.
     */
    public function preloadProjectDepartmentEcnMap(int $projectId, string $department = 'manager'): array
    {
        $dept = strtolower(trim($department));

        // When viewing manager or general overview, show total required ECN count
        if ($dept === 'manager' || empty($dept)) {
            return $this->preloadProjectEcnHierarchyMap($projectId);
        }

        $query = EcnRequirement::with(['workflowRecords' => fn($wq) => $wq->where('status', 'in_progress')])->where('project_id', $projectId);

        if ($dept === 'store') {
            $query->where(function ($q) {
                $q->where('current_state', 'PENDING')
                  ->orWhereRaw('required_qty > received_qty');
            });
        } elseif ($dept === 'store_resident' || $dept === 'qc_arrival') {
            $query->whereIn('current_state', ['STORE', 'SENT_TO_QC']);
        } elseif ($dept === 'qc' || $dept === 'qc_inspection') {
            $query->where('current_state', 'QC');
        } elseif ($dept === 'rework') {
            $query->where(function ($q) {
                $q->where('current_state', 'REWORK')
                  ->orWhereHas('workflowRecords', fn($wq) => $wq->where('department', 'REWORK')->where('status', 'in_progress'));
            });
        } elseif ($dept === 'paint') {
            $query->where(function ($q) {
                $q->where('current_state', 'PAINT')
                  ->orWhereHas('workflowRecords', fn($wq) => $wq->where('department', 'PAINT')->where('status', 'in_progress'));
            });
        } elseif ($dept === 'assembly') {
            $query->where(function ($q) {
                $q->whereIn('current_state', ['ASSEMBLY', 'ASSEMBLY_COMPLETED'])
                  ->orWhereHas('workflowRecords', fn($wq) => $wq->where('department', 'ASSEMBLY')->where('status', 'in_progress'));
            });
        }

        $reqs = $query->get();

        $map = [
            'project_total' => 0,
            'project_ecn_numbers' => [],
            'project_ecn_breakdown' => [],
            'project_ecn_summary' => [],
            'project_ecn_display' => null,
            'jigs' => [],
            'jig_ecn_numbers' => [],
            'jig_ecn_breakdown' => [],
            'jig_ecn_summary' => [],
            'jig_ecn_display' => [],
            'units' => [],
            'unit_ecn_numbers' => [],
            'unit_ecn_breakdown' => [],
            'unit_ecn_summary' => [],
            'unit_ecn_display' => [],
            'sides' => [],
        ];

        foreach ($reqs as $r) {
            $qty = match ($dept) {
                'store' => (int)max(0, (int)$r->required_qty - (int)$r->received_qty),
                'store_resident', 'qc_arrival' => (int)(in_array($r->current_state, ['STORE', 'SENT_TO_QC']) ? ($r->received_qty ?: $r->required_qty) : 0),
                'qc', 'qc_inspection' => (int)($r->current_state === 'QC' ? ($r->received_qty ?: $r->required_qty) : 0),
                'rework' => (int)($r->workflowRecords->where('department', 'REWORK')->where('status', 'in_progress')->sum('quantity') ?: ($r->current_state === 'REWORK' ? ($r->received_qty ?: $r->required_qty) : 0)),
                'paint' => (int)($r->workflowRecords->where('department', 'PAINT')->where('status', 'in_progress')->sum('quantity') ?: ($r->current_state === 'PAINT' ? ($r->received_qty ?: $r->required_qty) : 0)),
                'assembly' => (int)($r->workflowRecords->where('department', 'ASSEMBLY')->where('status', 'in_progress')->sum('quantity') ?: (in_array($r->current_state, ['ASSEMBLY', 'ASSEMBLY_COMPLETED']) ? ($r->received_qty ?: $r->required_qty) : 0)),
                default => (int)($r->received_qty ?: $r->required_qty),
            };

            if ($qty <= 0) continue;

            $ecnNum = trim((string)$r->ecn_number);

            $map['project_total'] += $qty;
            if ($ecnNum !== '') {
                if (!in_array($ecnNum, $map['project_ecn_numbers'])) {
                    $map['project_ecn_numbers'][] = $ecnNum;
                }
                $map['project_ecn_breakdown'][$ecnNum] = ($map['project_ecn_breakdown'][$ecnNum] ?? 0) + $qty;
            }

            // Jig aliases
            $jigNo = (string)$r->jig_no;
            $jigUpper = strtoupper(trim($jigNo));
            $jigAliases = array_unique([$jigNo, $jigUpper]);

            foreach ($jigAliases as $jKey) {
                $map['jigs'][$jKey] = ($map['jigs'][$jKey] ?? 0) + $qty;
                if ($ecnNum !== '') {
                    $map['jig_ecn_numbers'][$jKey] = $map['jig_ecn_numbers'][$jKey] ?? [];
                    if (!in_array($ecnNum, $map['jig_ecn_numbers'][$jKey])) {
                        $map['jig_ecn_numbers'][$jKey][] = $ecnNum;
                    }
                    $map['jig_ecn_breakdown'][$jKey][$ecnNum] = ($map['jig_ecn_breakdown'][$jKey][$ecnNum] ?? 0) + $qty;
                }
            }

            // Unit (key: jig|unit)
            $unitNo = (string)$r->unit_no;
            $rawUnit = trim(str_ireplace('unit', '', $unitNo));
            $paddedUnit = is_numeric($rawUnit) ? sprintf('%02d', (int)$rawUnit) : $rawUnit;

            $unitAliases = [];
            foreach ($jigAliases as $jKey) {
                $unitAliases[] = $jKey . '|' . $unitNo;
                $unitAliases[] = $jKey . '|' . $rawUnit;
                $unitAliases[] = $jKey . '|Unit ' . $rawUnit;
                $unitAliases[] = $jKey . '|Unit ' . $paddedUnit;
            }
            $unitAliases = array_unique($unitAliases);

            foreach ($unitAliases as $uKey) {
                $map['units'][$uKey] = ($map['units'][$uKey] ?? 0) + $qty;
                if ($ecnNum !== '') {
                    $map['unit_ecn_numbers'][$uKey] = $map['unit_ecn_numbers'][$uKey] ?? [];
                    if (!in_array($ecnNum, $map['unit_ecn_numbers'][$uKey])) {
                        $map['unit_ecn_numbers'][$uKey][] = $ecnNum;
                    }
                    $map['unit_ecn_breakdown'][$uKey][$ecnNum] = ($map['unit_ecn_breakdown'][$uKey][$ecnNum] ?? 0) + $qty;
                }
            }

            // Side (key: jig|unit|side)
            $sideDisp = strtoupper(trim($r->side_display ?: $r->side));
            $sideNorm = in_array($sideDisp, ['LH', 'LA', 'AL', 'L', 'LEFT']) ? 'LH' : 'RH';

            foreach ($unitAliases as $uKey) {
                $map['sides'][$uKey . '|' . $r->side_display] = ($map['sides'][$uKey . '|' . $r->side_display] ?? 0) + $qty;
                $map['sides'][$uKey . '|' . $sideNorm] = ($map['sides'][$uKey . '|' . $sideNorm] ?? 0) + $qty;
                if ($r->side && $r->side !== $r->side_display && $r->side !== $sideNorm) {
                    $map['sides'][$uKey . '|' . $r->side] = ($map['sides'][$uKey . '|' . $r->side] ?? 0) + $qty;
                }
            }
        }

        // Build Project level summary & display
        foreach ($map['project_ecn_breakdown'] as $num => $cnt) {
            $map['project_ecn_summary'][] = ['ecn_number' => $num, 'part_count' => $cnt];
        }
        $map['project_ecn_display'] = self::formatEcnSummaryDisplay($map['project_ecn_summary']);

        // Build Jig level summary & display
        foreach ($map['jig_ecn_breakdown'] as $jigKey => $breakdown) {
            $jSum = [];
            foreach ($breakdown as $num => $cnt) {
                $jSum[] = ['ecn_number' => $num, 'part_count' => $cnt];
            }
            $map['jig_ecn_summary'][$jigKey] = $jSum;
            $map['jig_ecn_display'][$jigKey] = self::formatEcnSummaryDisplay($jSum);
        }

        // Build Unit level summary & display
        foreach ($map['unit_ecn_breakdown'] as $uKey => $breakdown) {
            $uSum = [];
            foreach ($breakdown as $num => $cnt) {
                $uSum[] = ['ecn_number' => $num, 'part_count' => $cnt];
            }
            $map['unit_ecn_summary'][$uKey] = $uSum;
            $map['unit_ecn_display'][$uKey] = self::formatEcnSummaryDisplay($uSum);
        }

        return $map;
    }

    public function getEcnNumbersForHierarchy(
        ?int $projectId = null,
        ?string $jigNo = null,
        ?string $unitNo = null,
        string $department = 'manager'
    ): array {
        $dept = strtolower(trim($department));
        if (!$projectId) {
            return [];
        }
        $deptMap = $this->preloadProjectDepartmentEcnMap($projectId, $dept);
        $jKey = $jigNo !== null ? strtoupper(trim($jigNo)) : null;

        if ($jigNo !== null && $unitNo !== null) {
            $rawU = trim(str_ireplace('unit', '', $unitNo));
            $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;

            return $deptMap['unit_ecn_numbers'][$jigNo . '|' . $rawU]
                ?? ($deptMap['unit_ecn_numbers'][$jKey . '|' . $rawU]
                ?? ($deptMap['unit_ecn_numbers'][$jigNo . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_numbers'][$jKey . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_numbers'][$jigNo . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_numbers'][$jKey . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_numbers'][$jigNo . '|Unit ' . $paddedU]
                ?? ($deptMap['unit_ecn_numbers'][$jKey . '|Unit ' . $paddedU]
                ?? [])))))));
        } elseif ($jigNo !== null) {
            return $deptMap['jig_ecn_numbers'][$jigNo] ?? ($deptMap['jig_ecn_numbers'][$jKey] ?? []);
        }
        return $deptMap['project_ecn_numbers'] ?? [];
    }

    public function getEcnDisplayForHierarchy(
        ?int $projectId = null,
        ?string $jigNo = null,
        ?string $unitNo = null,
        string $department = 'manager'
    ): ?string {
        $dept = strtolower(trim($department));
        if (!$projectId) {
            return null;
        }
        $deptMap = $this->preloadProjectDepartmentEcnMap($projectId, $dept);
        $jKey = $jigNo !== null ? strtoupper(trim($jigNo)) : null;

        if ($jigNo !== null && $unitNo !== null) {
            $rawU = trim(str_ireplace('unit', '', $unitNo));
            $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;

            return $deptMap['unit_ecn_display'][$jigNo . '|' . $rawU]
                ?? ($deptMap['unit_ecn_display'][$jKey . '|' . $rawU]
                ?? ($deptMap['unit_ecn_display'][$jigNo . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_display'][$jKey . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_display'][$jigNo . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_display'][$jKey . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_display'][$jigNo . '|Unit ' . $paddedU]
                ?? ($deptMap['unit_ecn_display'][$jKey . '|Unit ' . $paddedU]
                ?? null)))))));
        } elseif ($jigNo !== null) {
            return $deptMap['jig_ecn_display'][$jigNo] ?? ($deptMap['jig_ecn_display'][$jKey] ?? null);
        }
        return $deptMap['project_ecn_display'] ?? null;
    }

    public function getEcnSummaryForHierarchy(
        ?int $projectId = null,
        ?string $jigNo = null,
        ?string $unitNo = null,
        string $department = 'manager'
    ): array {
        $dept = strtolower(trim($department));
        if (!$projectId) {
            return [];
        }
        $deptMap = $this->preloadProjectDepartmentEcnMap($projectId, $dept);
        $jKey = $jigNo !== null ? strtoupper(trim($jigNo)) : null;

        if ($jigNo !== null && $unitNo !== null) {
            $rawU = trim(str_ireplace('unit', '', $unitNo));
            $paddedU = is_numeric($rawU) ? sprintf('%02d', (int)$rawU) : $rawU;

            return $deptMap['unit_ecn_summary'][$jigNo . '|' . $rawU]
                ?? ($deptMap['unit_ecn_summary'][$jKey . '|' . $rawU]
                ?? ($deptMap['unit_ecn_summary'][$jigNo . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_summary'][$jKey . '|' . $unitNo]
                ?? ($deptMap['unit_ecn_summary'][$jigNo . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_summary'][$jKey . '|Unit ' . $rawU]
                ?? ($deptMap['unit_ecn_summary'][$jigNo . '|Unit ' . $paddedU]
                ?? ($deptMap['unit_ecn_summary'][$jKey . '|Unit ' . $paddedU]
                ?? [])))))));
        } elseif ($jigNo !== null) {
            return $deptMap['jig_ecn_summary'][$jigNo] ?? ($deptMap['jig_ecn_summary'][$jKey] ?? []);
        }
        return $deptMap['project_ecn_summary'] ?? [];
    }

    /**
     * Single canonical backend helper for getting current ECN quantity by department and scope.
     */
    public function getCanonicalDepartmentEcnCount(int $projectId, string $department = 'manager'): int
    {
        return $this->getEcnCountsForHierarchy($projectId, null, null, null, $department);
    }

    /**
     * Single canonical summary by project and department hierarchy.
     */
    public function getCanonicalEcnSummary(array $filters = []): array
    {
        return $this->calculateEcnDashboardSummary($filters);
    }

    /**
     * Build ECN hierarchy breakdown for ECN Reports page.
     */
    public function getEcnHierarchy(?int $projectId = null, array $filters = []): array
    {
        $projects = Project::orderBy('name')->get();
        $activeProjects = $projects->where('status', 'active')->values();
        $completedProjects = $projects->where('status', 'completed')->values();

        if (!$projectId) {
            return [
                'is_hierarchical' => false,
                'projects' => $projects,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'summary' => $this->calculateEcnDashboardSummary($filters),
            ];
        }

        $project = Project::find($projectId);
        if (!$project) {
            return [
                'is_hierarchical' => false,
                'projects' => $projects,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'summary' => $this->calculateEcnDashboardSummary($filters),
            ];
        }

        $query = EcnRequirement::where('project_id', $projectId)
            ->with(['importBatch', 'receiptItems']);

        if (!empty($filters['ecn_number'])) {
            $query->where('ecn_number', trim($filters['ecn_number']));
        }
        if (!empty($filters['jig_no'])) {
            $query->where('jig_no', trim($filters['jig_no']));
        }
        if (!empty($filters['unit_no'])) {
            $query->where('unit_no', trim($filters['unit_no']));
        }
        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $query->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
        }

        $ecnReqs = $query->orderBy('ecn_number')->orderBy('jig_no')->orderBy('unit_no')->orderBy('part_no')->get();

        // Group into ECN -> Jig -> Unit -> Parts
        $ecnGroups = $ecnReqs->groupBy('ecn_number');
        $hierarchyNodes = [];

        foreach ($ecnGroups as $ecnNum => $reqsInEcn) {
            $jigGroups = $reqsInEcn->groupBy('jig_no');
            $jigNodes = [];

            foreach ($jigGroups as $jigNo => $reqsInJig) {
                $unitGroups = $reqsInJig->groupBy('unit_no');
                $unitNodes = [];

                foreach ($unitGroups as $unitNo => $reqsInUnit) {
                    $parts = $reqsInUnit->map(function ($req) {
                        return [
                            'id' => $req->id,
                            'is_ecn' => true,
                            'classification' => 'ECN',
                            'ecn_number' => $req->ecn_number,
                            'jig_no' => $req->jig_no,
                            'unit_no' => $req->unit_no,
                            'part_no' => $req->part_no,
                            'side' => $req->side,
                            'original_side' => $req->side,
                            'source_side' => $req->side,
                            'side_display' => $req->side,
                            'normalized_side' => $req->side_display,
                            'combined_identifier' => "{$req->ecn_number} | {$req->jig_no} | Unit {$req->unit_no} | Part {$req->part_no} | {$req->side}",
                            'required_qty' => $req->required_qty,
                            'received_qty' => $req->received_qty,
                            'current_state' => $req->current_state,
                            'status' => $req->current_state,
                        ];
                    })->values()->toArray();

                    $unitNodes[] = [
                        'unit_no' => $unitNo,
                        'total_required' => $reqsInUnit->sum('required_qty'),
                        'total_received' => $reqsInUnit->sum('received_qty'),
                        'parts_count' => count($parts),
                        'parts' => $parts,
                    ];
                }

                $jigNodes[] = [
                    'jig_no' => $jigNo,
                    'total_required' => $reqsInJig->sum('required_qty'),
                    'total_received' => $reqsInJig->sum('received_qty'),
                    'units' => $unitNodes,
                ];
            }

            $hierarchyNodes[] = [
                'ecn_number' => $ecnNum,
                'total_required' => $reqsInEcn->sum('required_qty'),
                'total_received' => $reqsInEcn->sum('received_qty'),
                'jigs' => $jigNodes,
            ];
        }

        return [
            'is_hierarchical' => true,
            'project' => $project,
            'summary' => $this->calculateEcnDashboardSummary(array_merge($filters, ['project_id' => $projectId])),
            'ecn_nodes' => $hierarchyNodes,
        ];
    }
}
