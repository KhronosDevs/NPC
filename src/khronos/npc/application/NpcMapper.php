<?php

declare(strict_types=1);

namespace khronos\npc\application;

use khronos\npc\domain\Npc;
use khronos\npc\domain\NpcAppearance;
use khronos\npc\domain\NpcId;
use khronos\npc\domain\NpcLocation;
use khronos\npc\domain\NpcModel;
use khronos\npc\domain\action\InteractionAction;
use khronos\npc\domain\action\RunCommandAction;
use khronos\npc\domain\action\SendMessageAction;
use khronos\npc\domain\action\ShowDialogAction;
use khronos\npc\domain\action\TeleportPlayerAction;

/**
 * Codec between NPC aggregates and the entity-metadata payload that is the
 * NPC's persisted form. Everything stored here must survive the core's
 * component-snapshot round-trip: scalars and JSON strings only.
 *
 * Extensibility point: register additional action factories to support new
 * action types without touching this class.
 */
final class NpcMapper {
    /** Well-known metadata payload keys (prefixed to avoid collisions). */
    public const META_NPC_ID = 'khronosNpcId';
    public const META_MODEL = 'khronosNpcModel';
    public const META_ACTIONS = 'khronosNpcActions';
    public const META_CREATED_AT = 'khronosNpcCreatedAt';
    public const META_SKIN = 'khronosNpcSkin';
    public const META_SLIM = 'khronosNpcSlim';

    /** @var array<string, callable(array<string, mixed>): InteractionAction> */
    private array $factories = [];

    public function __construct() {
        $this->factories[RunCommandAction::type()] = fn(array $d): InteractionAction => RunCommandAction::fromArray($d);
        $this->factories[SendMessageAction::type()] = fn(array $d): InteractionAction => SendMessageAction::fromArray($d);
        $this->factories[ShowDialogAction::type()] = fn(array $d): InteractionAction => ShowDialogAction::fromArray($d);
        $this->factories[TeleportPlayerAction::type()] = fn(array $d): InteractionAction => TeleportPlayerAction::fromArray($d);
    }

    /** Register a factory for an action type ("run_command" => fn(array): Action). */
    public function registerActionFactory(string $type, callable $factory): void {
        $this->factories[$type] = $factory;
    }

    /**
     * The full persisted state of an NPC as a flat scalar map, ready to be
     * written into an entity's MetadataComponent.
     *
     * @return array<string, mixed>
     */
    public function toMetaPayload(Npc $npc): array {
        return [
            self::META_NPC_ID => $npc->id()->value(),
            self::META_MODEL => $npc->appearance()->model->value(),
            self::META_ACTIONS => json_encode(array_map(
                static fn(InteractionAction $a): array => ['type' => $a::type(), 'data' => $a->toArray()],
                $npc->actions(),
            )) ?: '[]',
            self::META_CREATED_AT => $npc->createdAt(),
            self::META_SKIN => $npc->appearance()->skinData,
            self::META_SLIM => $npc->appearance()->slim ? 1 : 0,
        ];
    }

    /**
     * Rebuild an aggregate from a restored entity's position + metadata
     * payload. Returns null when the payload is not a valid NPC.
     *
     * @param array<string, mixed> $meta the entity's full MetadataComponent data
     */
    public function fromMetaPayload(string $world, float $x, float $y, float $z, float $yaw, float $pitch, array $meta): ?Npc {
        $id = (string)($meta[self::META_NPC_ID] ?? '');
        if ($id === '') {
            return null;
        }

        try {
            $actions = [];
            $decoded = json_decode((string)($meta[self::META_ACTIONS] ?? '[]'), true);
            foreach (is_array($decoded) ? $decoded : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $action = $this->actionFromArray((array)$entry);
                if ($action !== null) {
                    $actions[] = $action;
                }
            }

            return new Npc(
                NpcId::fromString($id),
                new NpcLocation($world, $x, $y, $z, $yaw, $pitch),
                new NpcAppearance(
                    (string)($meta[\pocketmine\core\constants\MetadataKeys::DISPLAY_NAME] ?? ''),
                    max(NpcAppearance::MIN_SCALE, min(NpcAppearance::MAX_SCALE, (float)($meta[\pocketmine\core\constants\MetadataKeys::SCALE] ?? 1.0))),
                    NpcModel::fromString((string)($meta[self::META_MODEL] ?? 'Villager')),
                    (string)($meta[self::META_SKIN] ?? ''),
                    (bool)($meta[self::META_SLIM] ?? false),
                ),
                $actions,
                (int)($meta[self::META_CREATED_AT] ?? 0),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{type?: mixed, data?: mixed} $entry
     */
    public function actionFromArray(array $entry): ?InteractionAction {
        $type = (string)($entry['type'] ?? '');
        $factory = $this->factories[$type] ?? null;
        if ($factory === null) {
            return null;
        }
        try {
            return $factory(is_array($entry['data'] ?? null) ? $entry['data'] : []);
        } catch (\Throwable) {
            return null;
        }
    }
}
