<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Reorder Queue</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #333; }
        h2 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
        .meta { margin-bottom: 15px; font-size: 11px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background-color: #34495e; color: #fff; font-weight: bold; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .badge-danger { background-color: #e74c3c; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
    </style>
</head>
<body>
    <h2>Industrial Spare Parts — Purchase Reorder Queue</h2>
    <div class="meta">
        <strong>Generated Date:</strong> {{ $date }} | 
        <strong>Total Rejected Items:</strong> {{ count($items) }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Project</th>
                <th>Standard Part No</th>
                <th>Side</th>
                <th>Qty</th>
                <th>Rejection Reason</th>
                <th>Supplier</th>
                <th>Rejected By</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td>{{ $item->project?->project_code ?? 'N/A' }}</td>
                    <td><strong>{{ $item->standard_part_no }}</strong></td>
                    <td><span class="badge-danger">{{ $item->side }}</span></td>
                    <td>{{ $item->rejected_quantity }}</td>
                    <td>{{ $item->rejection_reason ?? 'QC Defect' }}</td>
                    <td>{{ $item->bomItem?->supplier?->name ?? $item->bomItem?->supplier_name_raw ?? 'N/A' }}</td>
                    <td>{{ $item->rejectedBy?->name ?? 'QC' }}</td>
                    <td>{{ $item->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center;">No pending rejected items in purchase queue.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
