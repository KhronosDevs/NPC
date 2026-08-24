<?php

declare(strict_types=1);

namespace khronos\npc\domain\event;

/** Base contract for NPC domain events recorded by the aggregate. */
interface NpcDomainEvent {
    public function name(): string;

    /** Unix timestamp (seconds) of when the event was recorded. */
    public function occurredAt(): int;
}
