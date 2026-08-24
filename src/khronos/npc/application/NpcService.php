<?php

declare(strict_types=1);

namespace khronos\npc\application;

use khronos\npc\application\port\ActionExecutor;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\application\usecase\ChunkMaterializer;
use khronos\npc\application\usecase\HandleInteractionUseCase;
use khronos\npc\application\usecase\RemoveNpcUseCase;
use khronos\npc\application\usecase\SpawnNpcUseCase;
use khronos\npc\domain\Npc;
use khronos\npc\domain\NpcAppearance;
use khronos\npc\domain\NpcId;
use khronos\npc\domain\NpcModel;
use khronos\npc\domain\action\InteractionAction;

/**
 * Application facade: the single entry point presentation code (commands,
 * listeners) talks to.
 *
 * Persistence model: NPCs are stored BY THE WORLD (EntityTags::PERSISTENT +
 * full state in entity metadata). Every mutation below updates the live
 * entity, which is the persisted form - there is no separate database.
 */
final class NpcService {
    public function __construct(
        private readonly NpcRegistry $registry,
        private readonly SpawnNpcUseCase $spawnUseCase,
        private readonly RemoveNpcUseCase $removeUseCase,
        private readonly HandleInteractionUseCase $interactionUseCase,
        private readonly ChunkMaterializer $chunkMaterializer,
        private readonly ActionExecutor $executor,
    ) {}

    // ---- Queries (loaded NPCs only - unloaded chunks are world data) ----

    /** @return list<Npc> every NPC whose home chunk is currently loaded. */
    public function all(): array {
        return $this->registry->all();
    }

    public function count(): int {
        return $this->registry->count();
    }

    public function findById(NpcId $id): ?Npc {
        return $this->registry->findById($id);
    }

    /** Find an NPC by (partial, case-insensitive) id prefix - command UX. */
    public function findByPrefix(string $prefix): ?Npc {
        if ($prefix === '') {
            return null;
        }
        $needle = strtolower($prefix);
        foreach ($this->registry->all() as $npc) {
            if (str_starts_with(strtolower($npc->id()->value()), $needle)) {
                return $npc;
            }
        }
        return null;
    }

    // ---- Commands ------------------------------------------------------------

    /**
     * Spawn a new NPC. Returns null + calls $onError when validation fails.
     *
     * @param list<InteractionAction> $actions
     * @param callable(string): void|null $onError
     */
    public function spawn(
        string $world,
        float $x,
        float $y,
        float $z,
        float $yaw = 0.0,
        float $pitch = 0.0,
        string $nametag = '',
        ?float $scale = null,
        ?string $model = null,
        ?string $skinData = null,
        bool $slim = false,
        array $actions = [],
        ?callable $onError = null,
    ): ?Npc {
        return $this->spawnUseCase->execute(
            $world, $x, $y, $z, $yaw, $pitch,
            $nametag, $scale, $model, $skinData, $slim,
            $actions, $onError,
        );
    }

    public function remove(NpcId $id): bool {
        return $this->removeUseCase->execute($id);
    }

    /** Move an NPC to a new position within its world. */
    public function move(NpcId $id, float $x, float $y, float $z, ?float $yaw, ?float $pitch): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->moveTo($npc->location()->movedTo($x, $y, $z, $yaw, $pitch));
        $this->chunkMaterializer->syncToEntity($npc);
        return true;
    }

    public function rename(NpcId $id, string $nametag): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null || trim($nametag) === '') {
            return false;
        }
        $npc->rename($nametag);
        $this->chunkMaterializer->rematerialize($npc);
        return true;
    }

    public function rescale(NpcId $id, float $scale): bool {
        try {
            new NpcAppearance('validity', $scale);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->rescale($scale);
        $this->chunkMaterializer->rematerialize($npc);
        return true;
    }

    public function changeModel(NpcId $id, string $model): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->changeModel(NpcModel::fromString($model));
        $this->chunkMaterializer->rematerialize($npc);
        return true;
    }

    public function setSkin(NpcId $id, string $skinData, bool $slim): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->setSkin($skinData, $slim);
        $this->chunkMaterializer->rematerialize($npc);
        return true;
    }

    public function addAction(NpcId $id, InteractionAction $action): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->addAction($action);
        $this->chunkMaterializer->syncToEntity($npc);
        return true;
    }

    public function clearActions(NpcId $id): bool {
        $npc = $this->registry->findById($id);
        if ($npc === null) {
            return false;
        }
        $npc->clearActions();
        $this->chunkMaterializer->syncToEntity($npc);
        return true;
    }

    // ---- Runtime events --------------------------------------------------------

    /** Route a player interaction to the owning NPC. See HandleInteractionUseCase statuses. */
    public function handleInteraction(int $playerEntityId, string $playerName, NpcId $npcId): int {
        $status = $this->interactionUseCase->execute($playerEntityId, $playerName, $npcId);
        if ($status === HandleInteractionUseCase::HANDLED || $status === HandleInteractionUseCase::NO_ACTIONS) {
            $npc = $this->registry->findById($npcId);
            $npc?->recordInteraction($playerEntityId, $playerName);
        }
        return $status;
    }

    public function onChunkLoaded(int $worldId, int $chunkX, int $chunkZ): void {
        $this->chunkMaterializer->onChunkLoaded($worldId, $chunkX, $chunkZ);
    }

    public function onChunkUnloaded(int $worldId, int $chunkX, int $chunkZ): void {
        $this->chunkMaterializer->onChunkUnloaded($worldId, $chunkX, $chunkZ);
    }

    public function forgetPlayer(int $playerEntityId): void {
        $this->interactionUseCase->forgetPlayer($playerEntityId);
    }

    // ---- Internals ----------------------------------------------------------------

    /** Exposed for tests / future subscribers that need raw executor access. */
    public function executor(): ActionExecutor {
        return $this->executor;
    }
}
