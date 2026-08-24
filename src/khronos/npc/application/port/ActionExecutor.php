<?php

declare(strict_types=1);

namespace khronos\npc\application\port;

use khronos\npc\domain\action\InteractionAction;

/**
 * Executes one interaction action against the runtime (send packets, run
 * commands, teleport, ...). Infrastructure provides implementations; new
 * action types are supported by registering a new executor - the domain
 * and the use cases never change.
 */
interface ActionExecutor {
    /** True when this executor can run $action. */
    public function supports(InteractionAction $action): bool;

    public function execute(InteractionAction $action, InteractionContext $context): void;
}
