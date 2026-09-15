<?php

declare(strict_types=1);

namespace khronos\npc\presentation\listener;

use khronos\npc\application\NpcMapper;
use khronos\npc\application\NpcService;
use khronos\npc\domain\NpcId;
use pocketmine\api\event\EntityDamageEvent;
use pocketmine\api\event\PlayerInteractEvent;

/**
 * Translates runtime interaction/damage callbacks into application calls.
 *
 * Right-click on an NPC: cancels the event (so the server's default
 * entity-type dispatch never runs) and routes it to HandleInteractionUseCase.
 * Damage against an NPC: cancelled while npcs.invulnerable is on (NPCs have
 * no HealthComponent anyway, so most damage paths already ignore them).
 */
final class InteractionListener {
    public function __construct(
        private readonly NpcService $service,
        private readonly bool $invulnerable,
    ) {}

    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $target = $event->getTarget();
        if ($target === null) {
            return;
        }
        $targetRef = $target->getInternalRef();
        $npcId = $this->npcIdOf($targetRef);
        if ($npcId === null) {
            return;
        }

        // The core fires this event before its own reach validation, so
        // enforce the same 3-block range here (matching the server's own
        // canInteract): out-of-range clicks fall through uncancelled.
        if (!$this->withinReach($event->getPlayer()->getInternalRef(), $targetRef)) {
            return;
        }

        // NPCs swallow both click types: right-click runs actions,
        // left-click does nothing (no hurt animation, no knockback).
        $event->setCancelled(true);

        // The core's Blocker-4 fix relabels entity right-clicks as
        // RIGHT_CLICK_ENTITY (they were RIGHT_CLICK_BLOCK before); accept
        // both so the plugin works against either core version.
        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_ENTITY
            && $event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            return;
        }

        $player = $event->getPlayer();
        $this->service->handleInteraction($player->getId(), $player->getName(), NpcId::fromString($npcId));
    }

    private function withinReach(\pocketmine\core\ecs\EntityRef $a, \pocketmine\core\ecs\EntityRef $b): bool {
        $pa = $a->getPosition();
        $pb = $b->getPosition();
        if ($pa === null || $pb === null) {
            return false;
        }
        $dx = $pa->x - $pb->x;
        $dy = $pa->y - $pb->y;
        $dz = $pa->z - $pb->z;
        return ($dx * $dx + $dy * $dy + $dz * $dz) <= 9.0;
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        if (!$this->invulnerable) {
            return;
        }
        if ($this->npcIdOf($event->getEntity()->getInternalRef()) !== null) {
            $event->setCancelled(true);
        }
    }

    /** O(1): single metadata read keyed by the marker the bridge wrote. */
    private function npcIdOf(\pocketmine\core\ecs\EntityRef $ref): ?string {
        $meta = $ref->getMetadata();
        $value = $meta?->get(NpcMapper::META_NPC_ID);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
