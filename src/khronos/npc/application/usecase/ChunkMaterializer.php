<?php

declare(strict_types=1);

namespace khronos\npc\application\usecase;

use khronos\npc\application\NpcMapper;
use khronos\npc\application\port\NpcEntityBridge;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\domain\Npc;

/**
 * Chunk-scoped lazy loading against the world's own persistence.
 *
 * Chunk loaded  -> core restored persistent entities; we repair them,
 *                  rebuild aggregates and index them (O(NPCs in chunk)).
 * Chunk unloaded -> the core snapshots our entities into chunk data; we
 *                  just drop the in-memory binding.
 *
 * Lookups are O(NPCs in the chunk) via the registry's chunk index - there
 * is never a scan across all NPCs of all worlds.
 */
final class ChunkMaterializer {
    public function __construct(
        private readonly NpcRegistry $registry,
        private readonly NpcEntityBridge $bridge,
        private readonly NpcMapper $mapper,
    ) {}

    /**
     * Called on ChunkLoadEvent (which fires after the core restored
     * persistent entity snapshots for that chunk).
     */
    public function onChunkLoaded(int $worldId, int $chunkX, int $chunkZ): void {
        foreach ($this->bridge->adoptRestoredNpcs($worldId, $chunkX, $chunkZ) as $entry) {
            $npc = $this->mapper->fromMetaPayload(
                $entry['world'],
                $entry['x'],
                $entry['y'],
                $entry['z'],
                $entry['yaw'],
                $entry['pitch'],
                $entry['meta'],
            );
            if ($npc === null) {
                continue;
            }
            // Already known (duplicate event / same id): keep the existing
            // aggregate, just refresh the entity binding.
            if ($this->registry->findById($npc->id()) !== null) {
                $this->registry->bindEntity($npc, $entry['ecsEntityId']);
                continue;
            }
            $this->registry->add($npc, $entry['ecsEntityId']);
        }
    }

    public function onChunkUnloaded(int $worldId, int $chunkX, int $chunkZ): void {
        $world = $this->bridge->worldFolder($worldId);
        if ($world === null) {
            return;
        }
        foreach ($this->registry->inChunk($world, $chunkX, $chunkZ) as $npc) {
            $ecsId = $this->registry->entityIdOf($npc);
            if ($ecsId !== null) {
                $this->registry->unbindEntity($ecsId);
            }
            $this->registry->remove($npc->id());
        }
    }

    public function onWorldRemoved(string $worldFolder): void {
        foreach ($this->registry->all() as $npc) {
            if ($npc->location()->world === $worldFolder) {
                $ecsId = $this->registry->entityIdOf($npc);
                if ($ecsId !== null) {
                    $this->registry->unbindEntity($ecsId);
                }
                $this->registry->remove($npc->id());
            }
        }
    }

    /**
     * Push position changes into the live entity - the per-tick broadcast
     * turns them into MoveEntityPackets automatically.
     */
    public function syncToEntity(Npc $npc): void {
        $loc = $npc->location();
        $ecsId = $this->registry->entityIdOf($npc);
        if ($ecsId === null) {
            return; // not loaded; nothing to update (edits require a loaded NPC)
        }
        $kernel = \pocketmine\Kernel::getInstance();
        $entity = $kernel?->getWorld()?->getEntity($ecsId);
        $pos = $entity?->get(\pocketmine\core\component\PositionComponent::class);
        if ($entity !== null && $pos !== null) {
            $pos->x = $loc->x;
            $pos->y = $loc->y;
            $pos->z = $loc->z;
            $pos->yaw = $loc->yaw;
            $pos->pitch = $loc->pitch;
            $rot = $entity->get(\pocketmine\core\component\RotationComponent::class);
            if ($rot !== null) {
                $rot->setYaw($loc->yaw);
                $rot->setPitch($loc->pitch);
                $rot->setHeadYaw($loc->yaw);
            }
        }
    }

    /**
     * Force an immediate re-render for every viewer after an appearance
     * change (skin / nametag / scale / model): tear down the current
     * entity and materialize a fresh one from the mutated aggregate.
     *
     * The core's per-viewer tracking emits RemoveEntityPacket for the old
     * id on its next broadcast pass, then the add-packet provider rebuilds
     * the new one with current skin/nametag/scale/model - no client ever
     * needs to leave view range for changes to show up.
     */
    public function rematerialize(Npc $npc): void {
        $oldEcsId = $this->registry->entityIdOf($npc);
        if ($oldEcsId !== null) {
            $this->bridge->dematerialize($oldEcsId);
            $this->registry->unbindEntity($oldEcsId);
        }

        // materialize() builds from the aggregate's CURRENT state, so the
        // fresh entity is born carrying the updated metadata payload.
        $ecsId = $this->bridge->materialize($npc);
        if ($ecsId !== null) {
            $this->registry->bindEntity($npc, $ecsId);
        }
    }
}
