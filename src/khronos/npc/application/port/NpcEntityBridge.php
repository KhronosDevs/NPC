<?php

declare(strict_types=1);

namespace khronos\npc\application\port;

use khronos\npc\domain\Npc;

/**
 * Bridge between the NPC domain and the runtime entity layer (ECS).
 *
 * Persistence model: NPCs are stored BY THE WORLD. Materialized entities
 * carry EntityTags::PERSISTENT plus the NPC's full state in metadata, so
 * chunk unload/reload (and server restarts) round-trip them through the
 * core's entity-snapshot pipeline. This port has no save/delete - mutating
 * a live entity IS persisting it; the next chunk unload captures it.
 */
interface NpcEntityBridge {
    /**
     * Spawn the live, persistent ECS entity for $npc. Returns the runtime
     * entity id, or null when the NPC's world is not loaded.
     */
    public function materialize(Npc $npc): ?int;

    /** Remove the live entity. The chunk's stored snapshot loses it on the
     * next unload pass, so removal is permanent. */
    public function dematerialize(int $ecsEntityId): void;

    /**
     * After the core restored persistent entities for a freshly loaded
     * chunk: find every NPC entity in that chunk, repair what the generic
     * snapshot restore cannot preserve (the PERSISTENT tag itself, and the
     * mob components spawnEntity attaches), and return their state.
     *
     * @return list<array{ecsEntityId: int, world: string, x: float, y: float, z: float, yaw: float, pitch: float, meta: array<string, mixed>}>
     */
    public function adoptRestoredNpcs(int $worldId, int $chunkX, int $chunkZ): array;

    /** Runtime world id -> stable folder name; null when unknown. */
    public function worldFolder(int $worldId): ?string;
}
