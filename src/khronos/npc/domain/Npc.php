<?php

declare(strict_types=1);

namespace khronos\npc\domain;

use khronos\npc\domain\action\InteractionAction;
use khronos\npc\domain\event\NpcDomainEvent;
use khronos\npc\domain\event\NpcInteracted;
use khronos\npc\domain\event\NpcRemoved;
use khronos\npc\domain\event\NpcSpawned;

/**
 * The NPC aggregate root. All mutations go through methods that record
 * domain events; callers persist the aggregate after mutating.
 *
 * Zero dependencies on the server API - fully unit-testable.
 */
final class Npc {
    /** @var list<NpcDomainEvent> */
    private array $recordedEvents = [];

    /**
     * @param list<InteractionAction> $actions
     * @param int $createdAt Unix seconds
     */
    public function __construct(
        private readonly NpcId $id,
        private NpcLocation $location,
        private NpcAppearance $appearance,
        private array $actions = [],
        private readonly int $createdAt = 0,
    ) {}

    public static function create(NpcLocation $location, NpcAppearance $appearance, array $actions = []): self {
        $now = time();
        $npc = new self(NpcId::generate(), $location, $appearance, $actions, $now);
        $npc->recordedEvents[] = new NpcSpawned($npc->id, $now);
        return $npc;
    }

    // ---- Identity / readers ------------------------------------------------

    public function id(): NpcId {
        return $this->id;
    }

    public function location(): NpcLocation {
        return $this->location;
    }

    public function appearance(): NpcAppearance {
        return $this->appearance;
    }

    /** @return list<InteractionAction> */
    public function actions(): array {
        return $this->actions;
    }

    public function createdAt(): int {
        return $this->createdAt;
    }

    // ---- Mutations ----------------------------------------------------------

    public function moveTo(NpcLocation $newLocation): void {
        if ($newLocation->world !== $this->location->world) {
            throw new \LogicException('NPCs cannot move between worlds; remove and re-spawn instead');
        }
        $this->location = $newLocation;
    }

    public function rename(string $nametag): void {
        $this->appearance = $this->appearance->withNametag($nametag);
    }

    public function rescale(float $scale): void {
        $this->appearance = $this->appearance->withScale($scale);
    }

    public function changeModel(NpcModel $model): void {
        $this->appearance = $this->appearance->withModel($model);
    }

    public function setSkin(string $skinData, bool $slim): void {
        $this->appearance = $this->appearance->withSkin($skinData, $slim);
    }

    public function addAction(InteractionAction $action): void {
        $this->actions[] = $action;
    }

    /** @return list<InteractionAction> removed actions */
    public function clearActions(): array {
        $removed = $this->actions;
        $this->actions = [];
        return $removed;
    }

    /**
     * Record an interaction performed against this NPC (after cooldown and
     * reach validation - this method is about bookkeeping, not policing).
     */
    public function recordInteraction(int $playerEntityId, string $playerName): void {
        $this->recordedEvents[] = new NpcInteracted($this->id, $playerEntityId, $playerName, time());
    }

    /** Called when the aggregate is deleted from the domain. */
    public static function removedEventFor(NpcId $id): NpcRemoved {
        return new NpcRemoved($id, time());
    }

    /** @return list<NpcDomainEvent> recorded-but-unconsumed events (drains). */
    public function pullEvents(): array {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }
}
