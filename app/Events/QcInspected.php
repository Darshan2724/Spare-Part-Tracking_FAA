<?php

namespace App\Events;

use App\Models\QcInspection;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QcInspected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $inspectionId;
    public $bomItemId;
    public $result;
    public $approvedQty;
    public $rejectedQty;
    public $reworkQty;

    public function __construct(QcInspection $inspection)
    {
        $this->inspectionId = $inspection->id;
        $this->bomItemId = $inspection->bom_item_id;
        $this->result = $inspection->result;
        $this->approvedQty = $inspection->approved_quantity;
        $this->rejectedQty = $inspection->rejected_quantity;
        $this->reworkQty = $inspection->rework_quantity;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('workflow'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'qc.inspected';
    }
}
