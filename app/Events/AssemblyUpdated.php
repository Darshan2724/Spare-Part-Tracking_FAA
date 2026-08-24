<?php

namespace App\Events;

use App\Models\AssemblyRecord;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssemblyUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $assemblyRecordId;
    public ?int $projectId;
    public ?string $jigId;
    public ?string $unitId;
    public int $bomItemId;
    public string $side;
    public int $quantity;
    public string $previousState;
    public string $newState;
    public string $timestamp;

    public function __construct(
        AssemblyRecord $record,
        ?int $projectId = null,
        ?string $side = null,
        ?int $quantity = null,
        string $previousState = 'assembly'
    ) {
        $this->assemblyRecordId = $record->id;
        $this->bomItemId = $record->bom_item_id;
        $this->side = $side ?? $record->side;
        $this->quantity = $quantity ?? $record->quantity;
        $this->projectId = $projectId ?? $record->bomItem?->project_id;
        $this->jigId = $record->bomItem?->jig_no;
        $this->unitId = $record->bomItem?->unit_no;
        $this->previousState = $previousState;
        $this->newState = 'assembly_completed';
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
        return 'assembly.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'assembly_record_id' => $this->assemblyRecordId,
            'project_id' => $this->projectId,
            'jig_id' => $this->jigId,
            'unit_id' => $this->unitId,
            'part_id' => $this->bomItemId,
            'side' => $this->side,
            'quantity' => $this->quantity,
            'previous_state' => $this->previousState,
            'new_state' => $this->newState,
            'timestamp' => $this->timestamp,
        ];
    }
}
