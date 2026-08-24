<?php

declare(strict_types=1);

namespace khronos\npc\application\usecase;

use khronos\npc\application\port\NpcEntityBridge;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\domain\Npc;
use khronos\npc\domain\NpcAppearance;
use khronos\npc\domain\NpcLocation;
use khronos\npc\domain\NpcModel;
use khronos\npc\domain\action\InteractionAction;

/**
 * Spawns a new NPC at a position: creates the aggregate and materializes
 * its persistent entity immediately (spawning happens where an admin
 * stands, so the chunk is loaded by definition).
 *
 * Persistence is implicit: the materialized entity carries
 * EntityTags::PERSISTENT plus the full state in metadata.
 *
 * Returns the created aggregate, or null when validation fails ($onError
 * receives a human-readable reason).
 */
final class SpawnNpcUseCase {
    public function __construct(
        private readonly NpcRegistry $registry,
        private readonly NpcEntityBridge $bridge,
    ) {}

    /**
     * @param list<InteractionAction> $actions
     * @param callable(string): void|null $onError
     */
    public function execute(
        string $world,
        float $x,
        float $y,
        float $z,
        float $yaw,
        float $pitch,
        string $nametag,
        ?float $scale,
        ?string $model,
        ?string $skinData,
        bool $slim,
        array $actions = [],
        ?callable $onError = null,
    ): ?Npc {
        try {
            $appearance = new NpcAppearance(
                $nametag,
                $scale ?? 1.0,
                NpcModel::fromString($model ?? 'Villager'),
                $skinData ?? '',
                $slim,
            );
            $npc = Npc::create(new NpcLocation($world, $x, $y, $z, $yaw, $pitch), $appearance, $actions);
        } catch (\InvalidArgumentException $e) {
            if ($onError !== null) {
                $onError($e->getMessage());
            }
            return null;
        }

        $this->registry->add($npc);
        // An unknown model falls back to the carrier type inside the bridge
        // (with a log line); it can be corrected later via /npc model.
        $ecsId = $this->bridge->materialize($npc);
        if ($ecsId !== null) {
            $this->registry->bindEntity($npc, $ecsId);
        }

        return $npc;
    }
}
