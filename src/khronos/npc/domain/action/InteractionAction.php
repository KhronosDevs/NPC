<?php

declare(strict_types=1);

namespace khronos\npc\domain\action;

/**
 * One configurable thing an NPC does when interacted with.
 *
 * Actions are pure data: they describe WHAT should happen, never HOW.
 * Execution is delegated to an application port, which keeps the domain
 * free of server/framework knowledge and makes new action types trivial
 * to add (see README "Adding a new interaction action type").
 */
interface InteractionAction {
    /** Stable string identifier used in serialized form ("run_command", ...). */
    public static function type(): string;

    /** @return array<string, mixed> scalar-only payload for persistence. */
    public function toArray(): array;
}
