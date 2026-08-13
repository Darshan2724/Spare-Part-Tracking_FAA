<?php

namespace App\Events;

use App\Models\ReceiptItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $receiptItemId;
    public $bomItemId;
    public $projectId;
    public $side;
    public $quantity;

    public function __construct(ReceiptItem $receiptItem)
    {
        $this->receiptItemId = $receiptItem->id;
        $this->bomItemId = $receiptItem->bom_item_id;
        $this->projectId = $receiptItem->bomItem?->project_id;
        $this->side = $receiptItem->side;
        $this->quantity = $receiptItem->received_quantity;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('workflow'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'store.received';
    }
}
