<?php

declare(strict_types=1);

namespace khronos\npc\application\port;

use khronos\npc\domain\Npc;

/**
 * Everything an action executor may need about WHO interacted and with WHAT.
 */
final class InteractionContext {
    public function __construct(
        public readonly int $playerEntityId,
        public readonly string $playerName,
        public readonly Npc $npc,
    ) {}
}
