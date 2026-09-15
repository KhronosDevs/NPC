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
 * Attack (left-click / mobile tap) on an NPC: cancels the event (so the
 * server's damage pipeline never runs on the NPC) and routes it to
 * HandleInteractionUseCase. Right-click is accepted as an alternate
 * trigger for desktop players. Damage against an NPC: cancelled while
 * npcs.invulnerable is on (NPCs have no HealthComponent anyway, so most
 * damage paths already ignore them).
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

        // NPCs swallow both click types: attacking an NPC runs its actions,
        // right-click is cancelled too so the server's default entity
        // dispatch (villager trading, saddling, ...) never runs.
        $event->setCancelled(true);

        // Actions trigger on ATTACK (left-click), not right-click: mobile
        // players interact with entities by tapping, which the client sends
        // as an attack (InteractPacket ACTION_LEFT_CLICK) - there is no
        // right-click gesture on touch. RIGHT_CLICK_ENTITY stays accepted as
        // an alternate trigger for desktop players.
        if ($event->getAction() !== PlayerInteractEvent::LEFT_CLICK_ENTITY
            && $event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_ENTITY) {
            return;
        }

        // The core fires this event before its own reach validation, so
        // enforce the same 3-block range here (matching the server's own
        // canInteract/canAttack): keeps a range hack from triggering NPC
        // actions through the event-fired-early window.
        if (!$this->withinReach($event->getPlayer()->getInternalRef(), $targetRef)) {
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
