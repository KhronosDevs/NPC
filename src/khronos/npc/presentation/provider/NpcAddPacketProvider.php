<?php

declare(strict_types=1);

namespace khronos\npc\presentation\provider;

use khronos\npc\application\NpcMapper;
use khronos\npc\application\registry\NpcRegistry;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\ecs\Entity;
use pocketmine\core\enum\EntityType;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\protocol\AddEntityPacket;
use pocketmine\protocol\AddPlayerPacket;
use pocketmine\protocol\DataPacket;
use pocketmine\utils\Binary;
use pocketmine\utils\UUID;

/**
 * Renders NPC entities through the kernel's add-packet provider hook
 * (first non-null result wins across plugins).
 *
 *  - Human model    -> AddPlayerPacket (player-form entity, custom skin
 *                      arrives separately via the player-list entry that
 *                      NpcSkinListInjector injects lazily per viewer).
 *  - Invisible      -> AddEntityPacket of the carrier type carrying the
 *                      invisible flag bit, so only the name tag shows.
 *  - Everything else-> AddEntityPacket of the model's own network id with
 *                      DATA_NO_AI so clients never locally animate it.
 *
 * Non-NPC entities return null immediately - this runs for every entity
 * add in every world, so the hot path is one metadata read.
 */
final class NpcAddPacketProvider {
    private const FLAG_INVISIBLE = 0x20;

    private NpcRegistry $registry;
    private bool $showNametags;

    public function __construct(NpcRegistry $registry, bool $showNametags = true) {
        $this->registry = $registry;
        $this->showNametags = $showNametags;
    }

    /**
     * @param array<int, mixed> $playerSessions entityId => session (players)
     */
    public function __invoke(int $entityId, Entity $entity, array $playerSessions): ?DataPacket {
        if (isset($playerSessions[$entityId])) {
            return null; // real players render themselves
        }
        $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta === null || !is_string($meta->get(NpcMapper::META_NPC_ID))) {
            return null; // not ours: pass to the next provider / builtin logic
        }

        $npc = $this->registry->findByEcsEntityId($entityId);
        // After a restart the registry may not know this entity yet (its
        // chunk event adoption can lag one tick); fall back to raw metadata.
        $model = $npc !== null
            ? $npc->appearance()->model
            : \khronos\npc\domain\NpcModel::fromString((string)($meta->get(NpcMapper::META_MODEL) ?? 'Villager'));
        $nametag = (string)($meta->get(MetadataKeys::DISPLAY_NAME) ?? '');
        $scale = max(0.01, (float)($meta->get(MetadataKeys::SCALE) ?? 1.0));

        $pos = $entity->get(PositionComponent::class);
        $rot = $entity->get(RotationComponent::class);
        $x = $pos?->x ?? 0.0;
        $y = $pos?->y ?? 0.0;
        $z = $pos?->z ?? 0.0;
        $yaw = $rot?->yaw ?? 0.0;
        $pitch = $rot?->pitch ?? 0.0;

        if ($model->isHuman()) {
            $pk = new AddPlayerPacket();
            $pk->uuid = self::fakeUuid(
                (string)$meta->get(NpcMapper::META_NPC_ID),
                (string)($meta->get(NpcMapper::META_SKIN) ?? ''),
                (bool)($meta->get(NpcMapper::META_SLIM) ?? false),
            );
            // Empty username (legacy NPC trick): the visible name comes from
            // DATA_NAMETAG below - an empty username keeps the NPC out of
            // the client's tab list rendering path entirely.
            $pk->username = '';
            $pk->eid = $entityId;
            $pk->x = $x;
            $pk->y = $y;
            $pk->z = $z;
            $pk->yaw = $yaw;
            $pk->pitch = $pitch;
            $pk->item = [0, 0, 0, null];
            $pk->metadata = self::baseMetadata();
            $pk->metadata[2] = [Binary::DATA_TYPE_STRING, $nametag];
            $pk->metadata[3] = [Binary::DATA_TYPE_BYTE, $this->showNametags ? 1 : 0];
            $pk->metadata[25] = [Binary::DATA_TYPE_FLOAT, $scale];
            return $pk;
        }

        $carrierName = (string)($meta->get(MetadataKeys::ENTITY_TYPE) ?? 'Villager');
        $type = EntityType::tryFrom($carrierName);
        if ($type === null) {
            return null; // let the built-in path decide (it will drop it)
        }

        $pk = new AddEntityPacket();
        $pk->eid = $entityId;
        $pk->type = $type->networkId();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->yaw = $yaw;
        $pk->pitch = $pitch;
        $pk->metadata = self::baseMetadata();
        $flags = 0;
        if ($model->isInvisible()) {
            $flags |= self::FLAG_INVISIBLE;
        }
        $pk->metadata[0] = [Binary::DATA_TYPE_BYTE, $flags];
        $pk->metadata[2] = [Binary::DATA_TYPE_STRING, $nametag];
        $pk->metadata[3] = [Binary::DATA_TYPE_BYTE, $this->showNametags ? 1 : 0];
        $pk->metadata[25] = [Binary::DATA_TYPE_FLOAT, $scale];
        return $pk;
    }

    /**
     * Deterministic player identity for a Human NPC. Fingerprints the skin
     * content: changing an NPC's skin changes its uuid, so clients that
     * cache skins by uuid pick up the new one without a relog. MUST match
     * the uuid used in the injector's PlayerListPacket entry.
     */
    public static function fakeUuid(string $npcId, string $skinDataBase64 = '', bool $slim = false): UUID {
        return UUID::fromData('khronos-npc:', $npcId, $skinDataBase64, $slim ? '1' : '0');
    }

    /** @return array<int, array{0: int, 1: mixed}> legacy default entity metadata */
    public static function baseMetadata(): array {
        return [
            0  => [Binary::DATA_TYPE_BYTE, 0],
            1  => [Binary::DATA_TYPE_SHORT, 300],
            2  => [Binary::DATA_TYPE_STRING, ''],
            3  => [Binary::DATA_TYPE_BYTE, 1],
            4  => [Binary::DATA_TYPE_BYTE, 0],
            15 => [Binary::DATA_TYPE_BYTE, 1], // DATA_NO_AI
            23 => [Binary::DATA_TYPE_LONG, -1],
            24 => [Binary::DATA_TYPE_BYTE, 0],
        ];
    }
}
