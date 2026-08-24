<?php

declare(strict_types=1);

namespace khronos\npc\application\usecase;

use khronos\npc\application\port\ActionExecutor;
use khronos\npc\application\port\InteractionContext;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\domain\Npc;
use khronos\npc\domain\NpcId;

/**
 * Handles a player interaction with an NPC: validates reachability state,
 * enforces the per-player cooldown, records the domain event and runs every
 * configured action in order.
 *
 * Returns a status constant so the presentation layer can give feedback.
 */
final class HandleInteractionUseCase {
    public const HANDLED = 0;
    public const UNKNOWN_NPC = 1;
    public const DISABLED = 2;
    public const NO_ACTIONS = 3;
    public const ON_COOLDOWN = 4;

    /** @var array<int, array<string, int>> player entity id => npc id => last-interaction tick */
    private array $lastInteraction = [];

    public function __construct(
        private readonly NpcRegistry $registry,
        private readonly ActionExecutor $executor,
        private readonly bool $interactionEnabled,
        private readonly int $cooldownTicks,
        private readonly \Closure $currentTickProvider,
    ) {}

    /**
     * @return self::HANDLED|self::UNKNOWN_NPC|self::DISABLED|self::NO_ACTIONS|self::ON_COOLDOWN
     */
    public function execute(int $playerEntityId, string $playerName, NpcId $npcId): int {
        if (!$this->interactionEnabled) {
            return self::DISABLED;
        }

        $npc = $this->registry->findById($npcId);
        if ($npc === null) {
            return self::UNKNOWN_NPC;
        }

        if ($this->isOnCooldown($playerEntityId, $npc)) {
            return self::ON_COOLDOWN;
        }
        $this->markCooldown($playerEntityId, $npc);

        $context = new InteractionContext($playerEntityId, $playerName, $npc);
        foreach ($npc->actions() as $action) {
            if ($this->executor->supports($action)) {
                try {
                    $this->executor->execute($action, $context);
                } catch (\Throwable) {
                    // One broken action must not stop the rest.
                }
            }
        }

        return $npc->actions() === [] ? self::NO_ACTIONS : self::HANDLED;
    }

    private function isOnCooldown(int $playerEntityId, Npc $npc): bool {
        if ($this->cooldownTicks <= 0) {
            return false;
        }
        $last = $this->lastInteraction[$playerEntityId][$npc->id()->value()] ?? null;
        if ($last === null) {
            return false;
        }
        return ($this->currentTickProvider)() - $last < $this->cooldownTicks;
    }

    private function markCooldown(int $playerEntityId, Npc $npc): void {
        if ($this->cooldownTicks <= 0) {
            return;
        }
        $this->lastInteraction[$playerEntityId][$npc->id()->value()] = ($this->currentTickProvider)();
        // Keep the per-player map bounded: one entry per NPC is fine, but
        // drop players who left entirely (called from periodic prune).
        if (count($this->lastInteraction) > 1024) {
            $tick = ($this->currentTickProvider)();
            foreach ($this->lastInteraction as $pid => $perNpc) {
                $newest = max($perNpc);
                if ($tick - $newest > 20 * 300) {
                    unset($this->lastInteraction[$pid]);
                }
            }
        }
    }

    /** Forget cooldowns for a player who logged out. */
    public function forgetPlayer(int $playerEntityId): void {
        unset($this->lastInteraction[$playerEntityId]);
    }
}
