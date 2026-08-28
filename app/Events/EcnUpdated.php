<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EcnUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $projectId;
    public ?int $ecnRequirementId;
    public ?string $ecnNumber;
    public ?string $jigNo;
    public ?string $unitNo;
    public ?string $partNo;
    public ?string $side;
    public ?string $sideDisplay;
    public string $classification = 'ECN';
    public int $quantity;
    public ?string $previousState;
    public ?string $newState;
    public string $eventType;
    public string $timestamp;

    public function __construct(array $payload)
    {
        $this->projectId = $payload['project_id'] ?? null;
        $this->ecnRequirementId = $payload['ecn_requirement_id'] ?? null;
        $this->ecnNumber = $payload['ecn_number'] ?? null;
        $this->jigNo = $payload['jig_no'] ?? null;
        $this->unitNo = $payload['unit_no'] ?? null;
        $this->partNo = $payload['part_no'] ?? null;
        $this->side = $payload['side'] ?? null;
        $this->sideDisplay = $payload['side_display'] ?? null;
        $this->quantity = (int)($payload['quantity'] ?? 0);
        $this->previousState = $payload['previous_state'] ?? null;
        $this->newState = $payload['new_state'] ?? null;
        $this->eventType = $payload['event_type'] ?? 'ECN_UPDATED';
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
        return 'ecn.updated';
    }
}
