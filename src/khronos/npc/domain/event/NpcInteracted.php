<?php

declare(strict_types=1);

namespace khronos\npc\domain\event;

use khronos\npc\domain\NpcId;

final class NpcInteracted implements NpcDomainEvent {
    public function __construct(
        public readonly NpcId $npcId,
        public readonly int $playerEntityId,
        public readonly string $playerName,
        public readonly int $occurredAt = 0,
    ) {}

    public function name(): string {
        return 'npc.interacted';
    }

    public function occurredAt(): int {
        return $this->occurredAt;
    }
}
