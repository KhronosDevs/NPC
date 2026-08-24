<?php

declare(strict_types=1);

namespace khronos\npc\application\registry;

use khronos\npc\domain\Npc;
use khronos\npc\domain\NpcId;

/**
 * In-memory read model of every known NPC.
 *
 * All lookups are O(1) hash-map operations:
 *   - by NPC id (commands, interaction routing),
 *   - by runtime entity id (interaction events arrive as entity ids),
 *   - by world+chunk key (chunk load/unload materialization).
 *
 * The registry holds DATA only; live entities live in the ECS and are
 * tracked through the bridge's materialized-id index. Unloaded chunks keep
 * their NPCs here (cheap aggregates), never as entities.
 */
final class NpcRegistry {
    /** @var array<string, Npc> npc id => aggregate */
    private array $byNpcId = [];

    /** @var array<int, string> runtime entity id => npc id */
    private array $npcIdByEntityId = [];

    /** @var array<string, array<string, true>> world => chunkKey => set of npc ids */
    private array $byChunk = [];

    public function add(Npc $npc, ?int $ecsEntityId = null): void {
        $id = $npc->id()->value();
        if (isset($this->byNpcId[$id])) {
            $this->remove($npc->id());
        }
        $this->byNpcId[$id] = $npc;
        $loc = $npc->location();
        $this->byChunk[$loc->world][$loc->chunkKey()][$id] = true;
        if ($ecsEntityId !== null) {
            $this->npcIdByEntityId[$ecsEntityId] = $id;
        }
    }

    /**
     * Re-index after a location change (old chunk entry dropped, new added).
     */
    public function relocate(Npc $npc): void {
        $id = $npc->id()->value();
        foreach ($this->byChunk as &$chunks) {
            foreach ($chunks as &$set) {
                unset($set[$id]);
            }
            unset($set);
            $chunks = array_filter($chunks, static fn(array $s): bool => $s !== []);
        }
        unset($chunks);
        $this->byChunk = array_filter($this->byChunk, static fn(array $c): bool => $c !== []);
        $loc = $npc->location();
        $this->byChunk[$loc->world][$loc->chunkKey()][$id] = true;
    }

    public function remove(NpcId $id): ?Npc {
        $key = $id->value();
        $npc = $this->byNpcId[$key] ?? null;
        if ($npc === null) {
            return null;
        }
        unset($this->byNpcId[$key]);

        $entityId = array_search($key, $this->npcIdByEntityId, true);
        if ($entityId !== false) {
            unset($this->npcIdByEntityId[$entityId]);
        }

        $loc = $npc->location();
        unset($this->byChunk[$loc->world][$loc->chunkKey()][$key]);
        if (isset($this->byChunk[$loc->world]) && $this->byChunk[$loc->world] === []) {
            unset($this->byChunk[$loc->world]);
        }
        return $npc;
    }

    public function findById(NpcId $id): ?Npc {
        return $this->byNpcId[$id->value()] ?? null;
    }

    public function findByEcsEntityId(int $ecsEntityId): ?Npc {
        $npcId = $this->npcIdByEntityId[$ecsEntityId] ?? null;
        return $npcId !== null ? ($this->byNpcId[$npcId] ?? null) : null;
    }

    /** Bind/unbind the runtime entity id of a materialized NPC. */
    public function bindEntity(Npc $npc, int $ecsEntityId): void {
        $this->npcIdByEntityId[$ecsEntityId] = $npc->id()->value();
    }

    public function unbindEntity(int $ecsEntityId): void {
        unset($this->npcIdByEntityId[$ecsEntityId]);
    }

    /** The live entity id of an NPC, or null when dematerialized. */
    public function entityIdOf(Npc $npc): ?int {
        $id = array_search($npc->id()->value(), $this->npcIdByEntityId, true);
        return $id !== false ? $id : null;
    }

    /** @return list<Npc> NPCs whose home chunk is this one (usually tiny). */
    public function inChunk(string $worldFolder, int $chunkX, int $chunkZ): array {
        $ids = $this->byChunk[$worldFolder][$chunkX . ',' . $chunkZ] ?? [];
        $out = [];
        foreach (array_keys($ids) as $id) {
            if (isset($this->byNpcId[$id])) {
                $out[] = $this->byNpcId[$id];
            }
        }
        return $out;
    }

    /** @return list<Npc> */
    public function all(): array {
        return array_values($this->byNpcId);
    }

    public function count(): int {
        return count($this->byNpcId);
    }
}
