<?php

declare(strict_types=1);

namespace khronos\npc\application\usecase;

use khronos\npc\application\port\NpcEntityBridge;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\domain\NpcId;

/**
 * Removes an NPC permanently: tears down the live entity. The chunk's next
 * capture pass no longer sees it, so the world's own storage drops it.
 */
final class RemoveNpcUseCase {
    public function __construct(
        private readonly NpcRegistry $registry,
        private readonly NpcEntityBridge $bridge,
    ) {}

    public function execute(NpcId $id): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }

        $ecsId = $this->registry->entityIdOf($npc);
        if ($ecsId !== null) {
            $this->bridge->dematerialize($ecsId);
        }

        $this->registry->remove($id);
        return true;
    }
}
