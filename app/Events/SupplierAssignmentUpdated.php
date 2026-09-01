<?php

namespace App\Events;

use App\Models\SupplierAssignment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierAssignmentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $assignmentId;
    public $projectId;
    public $jigNo;
    public $unitNo;
    public $category;
    public $supplierId;
    public $supplierName;
    public $assignmentDate;
    public $action;

    public function __construct(SupplierAssignment $assignment, string $action = 'updated')
    {
        $this->assignmentId = $assignment->id;
        $this->projectId = $assignment->project_id;
        $this->jigNo = $assignment->jig_no;
        $this->unitNo = $assignment->unit_no;
        $this->category = $assignment->category;
        $this->supplierId = $assignment->supplier_id;
        $this->supplierName = $assignment->supplier?->name;
        $this->assignmentDate = $assignment->assignment_date?->format('Y-m-d');
        $this->action = $action;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('workflow'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'supplier.assignment.updated';
    }
}
