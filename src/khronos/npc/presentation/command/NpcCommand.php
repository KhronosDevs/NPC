<?php

declare(strict_types=1);

namespace khronos\npc\presentation\command;

use khronos\npc\application\NpcService;
use khronos\npc\domain\NpcModel;
use khronos\npc\domain\action\RunCommandAction;
use khronos\npc\domain\action\SendMessageAction;
use khronos\npc\domain\action\ShowDialogAction;
use khronos\npc\domain\action\TeleportPlayerAction;
use khronos\npc\infrastructure\player\NpcSkins;
use khronos\npc\infrastructure\player\SkinCache;
use khronos\npc\infrastructure\player\SkinFetcher;
use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;
use pocketmine\api\entity\Player;

/**
 * /npc - single command for every NPC management operation.
 *
 *   /npc spawn <model> <nametag...>
 *   /npc remove <id>
 *   /npc list
 *   /npc move <id>            (to where you stand)
 *   /npc rename <id> <nametag...>
 *   /npc scale <id> <scale>
 *   /npc model <id> <model>
 *   /npc skin <id> <javaUsername> [slim]
 *   /npc action add command <id> <as-console:0|1> <command...>
 *   /npc action add message <id> <line...>
 *   /npc action add dialog  <id> <title...> | <line...> | ...
 *   /npc action add teleport<id> [world] <x> <y> <z> [yaw] [pitch]
 *   /npc action clear <id>
 *
 * <model> accepts ANY runtime entity type (Villager, Zombie, Pig, ...) plus
 * the specials "Human" and "Invisible".
 */
final class NpcCommand extends Command {
    private const MODELS_HINT = 'any entity type (Villager, Zombie, Pig, ...), "Human", or "Invisible"';
    /** Small Y lift so NPCs render just above the ground surface. */
    private const SPAWN_Y_LIFT = 0.05;

    public function __construct(
        private readonly NpcService $service,
        private readonly string $defaultModel,
        private readonly float $defaultScale,
        private readonly SkinCache $skins,
        private readonly SkinFetcher $skinFetcher,
    ) {
        parent::__construct(
            name: 'npc',
            description: 'Manage NPCs',
            usage: '/npc help',
            aliases: ['npcs'],
            permission: 'npc.admin',
            category: 'admin',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        if (!$this->testPermission($sender)) {
            return true;
        }
        $sub = strtolower((string)($args[0] ?? ''));
        match ($sub) {
            'spawn' => $this->spawn($sender, array_slice($args, 1)),
            'remove', 'delete' => $this->remove($sender, array_slice($args, 1)),
            'list' => $this->list($sender),
            'move' => $this->move($sender, array_slice($args, 1)),
            'rename' => $this->rename($sender, array_slice($args, 1)),
            'scale' => $this->scale($sender, array_slice($args, 1)),
            'model' => $this->model($sender, array_slice($args, 1)),
            'skin' => $this->skin($sender, array_slice($args, 1)),
            'action' => $this->action($sender, array_slice($args, 1)),
            default => $sender->sendMessage($this->helpText()),
        };
        return true;
    }

    // ---- subcommands -------------------------------------------------------

    /** @param list<string> $args */
    private function spawn(CommandSender $sender, array $args): void {
        $player = $sender->getPlayer();
        if ($player === null) {
            $sender->sendMessage('§cUse this command in-game.');
            return;
        }
        $model = (string)($args[0] ?? '');
        $nametag = trim(implode(' ', array_slice($args, 1)));
        if ($model === '' || $nametag === '') {
            $sender->sendMessage('§cUsage: /npc spawn <model> <nametag...>');
            $sender->sendMessage('§7Model: ' . self::MODELS_HINT);
            return;
        }

        // Spawn exactly where the admin stands, facing their yaw/pitch.
        // NOTE: on accepted moves the core writes x/y/z into the position
        // component but yaw/pitch ONLY into the rotation component - so
        // the pose must be assembled from both.
        $pose = $this->playerPose($player);
        $worldComp = $player->getInternalRef()->getEntity()
            ?->get(\pocketmine\core\component\WorldComponent::class);
        $worldFolder = $this->resolveWorldFolder($worldComp?->id ?? 0) ?? 'world';

        // Human NPCs get their CREATOR's skin, captured at spawn and stored
        // with the NPC - every viewer renders that saved skin afterwards.
        $skinData = null;
        if (NpcModel::fromString($model)->isHuman()) {
            $creatorSkin = NpcSkins::sanitize($this->skins->skinFor($player->getId()));
            $skinData = base64_encode($creatorSkin);
        }

        $npc = $this->service->spawn(
            world: $worldFolder,
            x: $pose['x'],
            y: $pose['y'],
            z: $pose['z'],
            yaw: $pose['yaw'],
            pitch: $pose['pitch'],
            nametag: $nametag,
            scale: $this->defaultScale,
            model: ucfirst(strtolower($model)),
            skinData: $skinData,
        );
        if ($npc === null) {
            $sender->sendMessage('§cCould not create that NPC (invalid model or nametag).');
            return;
        }
        $sender->sendMessage('§aNPC created: §f' . $npc->id()->value());
        if ($skinData !== null) {
            $sender->sendMessage('§7Your skin was captured and saved on this NPC.');
        }
        $sender->sendMessage('§7Add actions with §f/npc action add ' . $npc->id()->value() . ' ...');
    }

    private function resolveWorldFolder(int $worldId): ?string {
        $registry = \pocketmine\Kernel::getInstance()?->getResourceRegistry()
            ?->get(\pocketmine\core\resource\WorldRegistry::class);
        if ($registry instanceof \pocketmine\core\resource\WorldRegistry) {
            $world = $registry->getWorld($worldId);
            if ($world !== null) {
                return (string)$world['folderName'];
            }
        }
        return null;
    }

    /**
     * The player's current pose: x/y/z from the position component, yaw/
     * pitch from the rotation component (see handleMove - the core keeps
     * them in different components), with a hair of Y lift so the NPC
     * renders just above the ground instead of clipping into it.
     *
     * @return array{x: float, y: float, z: float, yaw: float, pitch: float}
     */
    private function playerPose(\pocketmine\api\entity\Player $player): array {
        $ref = $player->getInternalRef();
        $pos = $ref->getPosition();
        $rot = $ref->getRotation();
        return [
            'x' => $pos?->x ?? 0.0,
            'y' => ($pos?->y ?? 0.0) + self::SPAWN_Y_LIFT,
            'z' => $pos?->z ?? 0.0,
            'yaw' => $rot?->yaw ?? $pos?->yaw ?? 0.0,
            'pitch' => $rot?->pitch ?? $pos?->pitch ?? 0.0,
        ];
    }

    /** @param list<string> $args */
    private function remove(CommandSender $sender, array $args): void {
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        if ($npc === null) {
            return;
        }
        $this->service->remove($npc->id());
        $sender->sendMessage('§aNPC removed.');
    }

    private function list(CommandSender $sender): void {
        $all = $this->service->all();
        if ($all === []) {
            $sender->sendMessage('§7No NPCs exist yet. Create one with §f/npc spawn§7.');
            return;
        }
        $sender->sendMessage('§6NPCs (' . count($all) . '):');
        foreach ($all as $npc) {
            $sender->sendMessage(sprintf(
                '§f%s §7- "%s" [%s] @ %s (%d actions)',
                $npc->id()->value(),
                $npc->appearance()->nametag !== '' ? $npc->appearance()->nametag : '-',
                $npc->appearance()->model->value(),
                (string)$npc->location(),
                count($npc->actions()),
            ));
        }
    }

    /** @param list<string> $args */
    private function move(CommandSender $sender, array $args): void {
        $player = $sender->getPlayer();
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        if ($player === null || $npc === null) {
            if ($player === null) {
                $sender->sendMessage('§cUse this command in-game.');
            }
            return;
        }
        $pose = $this->playerPose($player);
        if (!$this->service->move($npc->id(), $pose['x'], $pose['y'], $pose['z'], $pose['yaw'], $pose['pitch'])) {
            $sender->sendMessage('§cMove failed.');
            return;
        }
        $sender->sendMessage('§aNPC moved to you.');
    }

    /** @param list<string> $args */
    private function rename(CommandSender $sender, array $args): void {
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        $name = trim(implode(' ', array_slice($args, 1)));
        if ($npc === null || $name === '') {
            $sender->sendMessage('§cUsage: /npc rename <id> <nametag...>');
            return;
        }
        $ok = $this->service->rename($npc->id(), $name);
        $sender->sendMessage($ok ? '§aNPC renamed.' : '§cRename failed.');
    }

    /** @param list<string> $args */
    private function scale(CommandSender $sender, array $args): void {
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        $scale = (float)($args[1] ?? 0);
        if ($npc === null || $scale <= 0) {
            $sender->sendMessage('§cUsage: /npc scale <id> <scale>');
            return;
        }
        $ok = $this->service->rescale($npc->id(), $scale);
        $sender->sendMessage($ok ? '§aScale set.' : '§cScale must be between 0.1 and 10.');
    }

    /** @param list<string> $args */
    private function model(CommandSender $sender, array $args): void {
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        $model = (string)($args[1] ?? '');
        if ($npc === null || $model === '') {
            $sender->sendMessage('§cUsage: /npc model <id> <model>');
            $sender->sendMessage('§7Model: ' . self::MODELS_HINT);
            return;
        }
        $ok = $this->service->changeModel($npc->id(), $model);
        $sender->sendMessage($ok ? '§aModel changed.' : '§cModel change failed.');
    }

    /**
     * /npc skin <id> <javaUsername> [slim]
     *
     * Fetches a Java player's skin from minotar.net asynchronously (HTTP +
     * PNG decode run on pool workers; the main thread never blocks) and
     * stores the decoded raw RGBA buffer on the NPC. The saved skin is
     * rendered for every viewer from then on.
     */
    private function skin(CommandSender $sender, array $args): void {
        $npc = $this->resolveNpc($sender, $args[0] ?? '');
        $username = (string)($args[1] ?? '');
        $slim = strtolower((string)($args[2] ?? '')) === 'slim';
        if ($npc === null || $username === '') {
            $sender->sendMessage('§cUsage: /npc skin <id> <javaUsername> [slim]');
            $sender->sendMessage('§7Fetches the Java account\'s skin from minotar.net.');
            return;
        }
        // Java names: 3-16 chars, letters/digits/underscore.
        if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $username)) {
            $sender->sendMessage('§cThat does not look like a Java username.');
            return;
        }

        $sender->sendMessage('§7Fetching skin of §f' . $username . '§7 ...');
        $this->skinFetcher->fetch(
            'https://minotar.net/skin/' . rawurlencode($username),
            function (string $rawBytes, string $error) use ($sender, $npc, $slim, $username): void {
                if ($error !== '') {
                    $sender->sendMessage('§cSkin fetch failed: §7' . $error);
                    return;
                }
                // Defensive: the worker already validated, but never store
                // anything that could crash a client's humanoid renderer.
                $rawBytes = NpcSkins::sanitize($rawBytes);
                $this->service->setSkin($npc->id(), base64_encode($rawBytes), $slim);
                $sender->sendMessage('§aSkin of §f' . $username . '§a applied to the NPC.');
                $sender->sendMessage('§7It is saved on the NPC and shown to everyone (re-render may be needed).');
            },
        );
    }

    /** @param list<string> $args */
    private function action(CommandSender $sender, array $args): void {
        $op = strtolower((string)($args[0] ?? ''));

        if ($op === 'clear') {
            $npc = $this->resolveNpc($sender, $args[1] ?? '');
            if ($npc === null) {
                return;
            }
            $this->service->clearActions($npc->id());
            $sender->sendMessage('§aAll actions removed from that NPC.');
            return;
        }

        if ($op !== 'add') {
            $sender->sendMessage('§cUsage: /npc action add <type> <id> ... | /npc action clear <id>');
            $sender->sendMessage('§7Types: command, message, dialog, teleport');
            return;
        }

        $type = strtolower((string)($args[1] ?? ''));
        $npc = $this->resolveNpc($sender, $args[2] ?? '');
        $rest = array_slice($args, 3);
        if ($npc === null) {
            return;
        }

        try {
            switch ($type) {
                case 'command':
                    // /npc action add command <id> <0|1 as-console> <command...>
                    $asConsole = (string)($rest[0] ?? '0') === '1';
                    $command = trim(implode(' ', array_slice($rest, 1)));
                    if ($command === '') {
                        throw new \InvalidArgumentException('Missing command text');
                    }
                    $this->service->addAction($npc->id(), new RunCommandAction($command, $asConsole));
                    break;

                case 'message':
                    // /npc action add message <id> <line...>
                    $line = trim(implode(' ', $rest));
                    if ($line === '') {
                        throw new \InvalidArgumentException('Missing message text');
                    }
                    $this->service->addAction($npc->id(), new SendMessageAction([$line]));
                    break;

                case 'dialog':
                    // Segments split on '|': title | line | line
                    $segments = array_map('trim', explode('|', implode(' ', $rest)));
                    $title = (string)array_shift($segments);
                    if ($title === '') {
                        throw new \InvalidArgumentException('Dialog needs a title before the first "|".');
                    }
                    $this->service->addAction($npc->id(), new ShowDialogAction($title, $segments));
                    break;

                case 'teleport':
                    // [world] x y z [yaw] [pitch]
                    if (count($rest) >= 4 && !is_numeric($rest[0])) {
                        $world = array_shift($rest);
                    } else {
                        $world = null;
                    }
                    if (count($rest) < 3) {
                        throw new \InvalidArgumentException('Need x y z (and optionally yaw pitch)');
                    }
                    $this->service->addAction($npc->id(), new TeleportPlayerAction(
                        (float)$rest[0], (float)$rest[1], (float)$rest[2],
                        (float)($rest[3] ?? 0), (float)($rest[4] ?? 0),
                        $world,
                    ));
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown action type '{$type}'");
            }
        } catch (\InvalidArgumentException $e) {
            $sender->sendMessage('§c' . $e->getMessage());
            return;
        }

        $sender->sendMessage('§aAction added (' . $type . ').');
    }

    // ---- helpers -------------------------------------------------------------

    private function resolveNpc(CommandSender $sender, string $prefixOrId): ?\khronos\npc\domain\Npc {
        if ($prefixOrId === '') {
            $sender->sendMessage('§cProvide an NPC id (see /npc list).');
            return null;
        }
        $npc = $this->service->findByPrefix($prefixOrId);
        if ($npc === null) {
            $sender->sendMessage('§cNo NPC matches "' . $prefixOrId . '".');
        }
        return $npc;
    }

    private function helpText(): string {
        return '§6--- NPC commands ---' . "\n"
            . '§f/npc spawn <model> <nametag...> §7- create an NPC at your feet' . "\n"
            . '§7Model: §f' . self::MODELS_HINT . "\n"
            . '§f/npc remove <id> §7| §f/npc list' . "\n"
            . '§f/npc move <id> §7- move an NPC to where you stand' . "\n"
            . '§f/npc rename <id> <name...> §7| §f/npc scale <id> <v> §7| §f/npc model <id> <m>' . "\n"
            . '§f/npc skin <id> <javaUsername> [slim]' . "\n"
            . '§f/npc action add <type> <id> ... §7| §f/npc action clear <id>' . "\n"
            . '§7Types: command <0|1> <cmd>, message <text>, dialog <title> | <lines>,' . "\n"
            . '       teleport [world] <x> <y> <z> [yaw] [pitch]';
    }
}
