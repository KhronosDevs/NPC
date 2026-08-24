<?php

declare(strict_types=1);

namespace khronos\npc\presentation\listener;

use khronos\npc\application\NpcService;
use pocketmine\api\event\ChunkLoadEvent;
use pocketmine\api\event\ChunkUnloadEvent;
use pocketmine\api\event\PlayerQuitEvent;

/**
 * Chunk-scoped lazy loading + per-player cleanup.
 *
 * ChunkLoadEvent/ChunkUnloadEvent carry (chunkX, chunkZ, worldId); the
 * service resolves the chunk's NPC set through the registry index, so each
 * event costs O(NPCs in that chunk) - nothing scans all NPCs.
 */
final class ChunkLifecycleListener {
    public function __construct(
        private readonly NpcService $service,
    ) {}

    public function onChunkLoad(ChunkLoadEvent $event): void {
        $this->service->onChunkLoaded($event->getWorldId(), $event->getChunkX(), $event->getChunkZ());
    }

    public function onChunkUnload(ChunkUnloadEvent $event): void {
        $this->service->onChunkUnloaded($event->getWorldId(), $event->getChunkX(), $event->getChunkZ());
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $this->service->forgetPlayer($event->getPlayer()->getId());
    }
}
