<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartReverted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $projectId;
    public ?string $jigId;
    public ?string $unitId;
    public int $bomItemId;
    public string $side;
    public int $quantity;
    public string $fromDepartment;
    public string $toDepartment;
    public ?string $sourceType;
    public ?int $sourceId;
    public string $timestamp;

    public function __construct(
        int $bomItemId,
        string $side,
        int $quantity,
        string $fromDepartment,
        string $toDepartment,
        ?int $projectId = null,
        ?string $jigId = null,
        ?string $unitId = null,
        ?string $sourceType = null,
        ?int $sourceId = null
    ) {
        $this->bomItemId = $bomItemId;
        $this->side = $side;
        $this->quantity = $quantity;
        $this->fromDepartment = $fromDepartment;
        $this->toDepartment = $toDepartment;
        $this->projectId = $projectId;
        $this->jigId = $jigId;
        $this->unitId = $unitId;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->timestamp = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('workflow'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'part.reverted';
    }

    public function broadcastWith(): array
    {
        return [
            'project_id' => $this->projectId,
            'jig_id' => $this->jigId,
            'unit_id' => $this->unitId,
            'bom_item_id' => $this->bomItemId,
            'side' => $this->side,
            'quantity' => $this->quantity,
            'from_department' => $this->fromDepartment,
            'to_department' => $this->toDepartment,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'timestamp' => $this->timestamp,
        ];
    }
}
