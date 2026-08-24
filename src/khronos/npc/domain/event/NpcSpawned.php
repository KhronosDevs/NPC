<?php

declare(strict_types=1);

namespace khronos\npc\domain\event;

use khronos\npc\domain\NpcId;

final class NpcSpawned implements NpcDomainEvent {
    public function __construct(
        public readonly NpcId $npcId,
        public readonly int $occurredAt = 0,
    ) {}

    public function name(): string {
        return 'npc.spawned';
    }

    public function occurredAt(): int {
        return $this->occurredAt;
    }
}
