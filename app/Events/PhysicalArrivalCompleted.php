<?php

namespace App\Events;

use App\Models\ReceiptItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PhysicalArrivalCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $receiptItemId;
    public int $bomItemId;
    public ?int $projectId;
    public string $side;
    public int $quantity;
    public string $status;
    public string $timestamp;

    public function __construct(ReceiptItem $receiptItem)
    {
        $this->receiptItemId = $receiptItem->id;
        $this->bomItemId = $receiptItem->bom_item_id;
        $this->projectId = $receiptItem->bomItem?->project_id;
        $this->side = $receiptItem->side;
        $this->quantity = $receiptItem->received_quantity;
        $this->status = $receiptItem->status;
        $this->timestamp = now()->toISOString();
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('workflow'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'qc.physical_arrival_completed';
    }
}
