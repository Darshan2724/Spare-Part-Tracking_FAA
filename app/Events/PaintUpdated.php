<?php

namespace App\Events;

use App\Models\PaintRecord;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaintUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $paintRecordId;
    public ?int $projectId;
    public int $bomItemId;
    public string $side;
    public int $quantity;
    public string $previousState;
    public string $newState;
    public string $timestamp;

    public function __construct(
        PaintRecord $record,
        ?int $projectId = null,
        ?string $side = null,
        ?int $quantity = null,
        string $previousState = 'qc_approved'
    ) {
        $this->paintRecordId = $record->id;
        $this->bomItemId = $record->bom_item_id;
        $this->side = $side ?? $record->side;
        $this->quantity = $quantity ?? $record->quantity;
        $this->projectId = $projectId ?? $record->bomItem?->project_id;
        $this->previousState = $previousState;
        $this->newState = 'paint_completed';
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
        return 'paint.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'paint_record_id' => $this->paintRecordId,
            'project_id' => $this->projectId,
            'part_id' => $this->bomItemId,
            'side' => $this->side,
            'quantity' => $this->quantity,
            'previous_state' => $this->previousState,
            'new_state' => $this->newState,
            'timestamp' => $this->timestamp,
        ];
    }
}
