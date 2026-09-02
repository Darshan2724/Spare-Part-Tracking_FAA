<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierDeactivated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $supplierId;
    public $supplierName;
    public $action; // 'deactivated' | 'deleted'

    public function __construct(int $supplierId, string $supplierName, string $action = 'deactivated')
    {
        $this->supplierId = $supplierId;
        $this->supplierName = $supplierName;
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
        return 'supplier.deactivated';
    }
}
