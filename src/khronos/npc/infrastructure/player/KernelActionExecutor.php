<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

use khronos\npc\application\port\ActionExecutor;
use khronos\npc\application\port\InteractionContext;
use khronos\npc\domain\action\InteractionAction;
use khronos\npc\domain\action\RunCommandAction;
use khronos\npc\domain\action\SendMessageAction;
use khronos\npc\domain\action\ShowDialogAction;
use khronos\npc\domain\action\TeleportPlayerAction;
use pocketmine\api\command\ConsoleCommandSender;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\core\ecs\EntityRef;
use pocketmine\Kernel;

/**
 * Runtime implementation of the action-executor port: turns pure data
 * actions into server effects (commands, messages, dialogs, teleports).
 */
final class KernelActionExecutor implements ActionExecutor {
    /** @var array<string, true> supported types, for O(1) supports() */
    private const SUPPORTED = [
        RunCommandAction::class => true,
        SendMessageAction::class => true,
        ShowDialogAction::class => true,
        TeleportPlayerAction::class => true,
    ];

    public function __construct(
        private readonly bool $dialogEnabled,
        private readonly int $dialogFallbackDurationSeconds,
    ) {}

    public function supports(InteractionAction $action): bool {
        return isset(self::SUPPORTED[$action::class]);
    }

    public function execute(InteractionAction $action, InteractionContext $context): void {
        match (true) {
            $action instanceof RunCommandAction => $this->runCommand($action, $context),
            $action instanceof SendMessageAction => $this->sendMessage($action, $context),
            $action instanceof ShowDialogAction => $this->showDialog($action, $context),
            $action instanceof TeleportPlayerAction => $this->teleport($action, $context),
            default => null,
        };
    }

    private function runCommand(RunCommandAction $action, InteractionContext $ctx): void {
        $kernel = Kernel::getInstance();
        if ($kernel === null || $action->command === '') {
            return;
        }
        if ($action->asConsole) {
            $sender = new ConsoleCommandSender();
        } else {
            $player = $this->player($ctx->playerEntityId);
            if ($player === null) {
                return;
            }
            $sender = new PlayerCommandSender($ctx->playerEntityId, $ctx->playerName);
        }
        // CommandPort::execute dispatches through the shared command map.
        $kernel->getCommandPort()->execute($sender, ltrim($action->command, '/'));
    }

    private function sendMessage(SendMessageAction $action, InteractionContext $ctx): void {
        $player = $this->player($ctx->playerEntityId);
        if ($player === null) {
            return;
        }
        foreach ($action->lines as $line) {
            $player->sendMessage((string)$line);
        }
    }

    private function showDialog(ShowDialogAction $action, InteractionContext $ctx): void {
        $kernel = Kernel::getInstance();
        if (!$this->dialogEnabled || $kernel === null) {
            // Dialogs disabled: degrade to plain chat lines.
            $player = $this->player($ctx->playerEntityId);
            if ($player !== null) {
                $player->sendMessage('§e' . $action->title);
                foreach ($action->lines as $line) {
                    $player->sendMessage('§7' . $line);
                }
            }
            return;
        }

        $player = $this->player($ctx->playerEntityId);
        if ($player === null) {
            return;
        }

        $popup = '§6' . $action->title . '§r';
        foreach ($action->lines as $line) {
            $popup .= "\n" . '§f' . $line;
        }
        $player->sendPopup($popup);

        $entityId = $ctx->playerEntityId;
        $duration = max(1, $action->durationSeconds ?: $this->dialogFallbackDurationSeconds);
        // Clear the popup once it expires.
        $kernel->getScheduler()->scheduleDelayedTask(function () use ($entityId): void {
            Kernel::getInstance()?->getNetworkSessionService()?->sendTextTo($entityId, 5, '');
        }, $duration * 20);
    }

    private function teleport(TeleportPlayerAction $action, InteractionContext $ctx): void {
        $kernel = Kernel::getInstance();
        $player = $this->player($ctx->playerEntityId);
        if ($kernel === null || $player === null) {
            return;
        }

        if ($action->world === null) {
            $player->teleport($action->x, $action->y, $action->z, $action->yaw, $action->pitch);
            return;
        }

        // Cross-world: resolve the target world id and switch the session.
        $registry = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldRegistry::class);
        $worldId = null;
        if ($registry instanceof \pocketmine\core\resource\WorldRegistry) {
            $worldId = $registry->getWorldIdByName($action->world)
                ?? (static function () use ($registry, $action): ?int {
                    foreach ($registry->getWorlds() as $id => $world) {
                        if ($world['folderName'] === $action->world) {
                            return (int)$id;
                        }
                    }
                    return null;
                })();
        }
        $nss = $kernel->getNetworkSessionService();
        if ($worldId === null || $nss === null || !$nss->switchWorld($ctx->playerEntityId, $worldId, [$action->x, $action->y, $action->z])) {
            $player->sendMessage('§cDestination world "' . $action->world . '" is not available.');
        }
    }

    private function player(int $entityId): ?\pocketmine\api\entity\Player {
        $kernel = Kernel::getInstance();
        $world = $kernel?->getWorld();
        if ($world === null) {
            return null;
        }
        $entity = $world->getEntity($entityId);
        if ($entity === null) {
            return null;
        }
        return new \pocketmine\api\entity\Player(
            EntityRef::create($entityId, $world),
            $world,
        );
    }
}
