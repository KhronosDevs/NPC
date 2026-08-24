<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\ecs;

use khronos\npc\application\NpcMapper;
use khronos\npc\application\port\NpcEntityBridge;
use khronos\npc\domain\Npc;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\constants\EntityTags;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\enum\EntityType;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\Kernel;

/**
 * Materializes NPC aggregates as live, world-persistent ECS entities.
 *
 * Component set is deliberately minimal: Position + Rotation + Metadata +
 * World + the PERSISTENT tag. No Velocity/AIState/Health/Collision - so no
 * physics system, no AI system and no combat pipeline ever touches an NPC
 * (left-clicks are ignored server-side because there is no HealthComponent
 * to reduce).
 *
 * Persistence: the PERSISTENT tag makes the core capture the entity into
 * its chunk's snapshot data on unload (and restore it on load/restart).
 * The NPC's full domain state rides in MetadataComponent, which the
 * snapshot serializes completely.
 *
 * Rendering goes through the kernel's add-packet provider hook (see
 * NpcAddPacketProvider); the DISPLAY_NAME / SCALE metadata keys double as
 * built-in overrides so mob-model NPCs still render correctly even if this
 * plugin's provider were absent.
 */
final class EcsNpcEntityBridge implements NpcEntityBridge {
    /** Entity type used as packet carrier for Human / Invisible models. */
    private const CARRIER_MODEL = 'Villager';

    public function __construct(
        private readonly NpcMapper $mapper,
    ) {}

    public function materialize(Npc $npc): ?int {
        $kernel = Kernel::getInstance();
        if ($kernel === null) {
            return null;
        }
        $worldId = $this->worldId($npc->location()->world);
        if ($worldId === null) {
            return null;
        }

        $carrier = $this->carrierTypeFor($npc);
        if ($carrier === null) {
            return null;
        }

        $loc = $npc->location();
        $appearance = $npc->appearance();
        $metaData = $this->mapper->toMetaPayload($npc)
            + [
                // Canonical nametag/scale keys - the core's buildAddPacket()
                // uses them as rendering overrides for mob-form NPCs.
                MetadataKeys::DISPLAY_NAME => $appearance->nametag,
                MetadataKeys::SCALE => $appearance->scale,
                // Drives the core's snapshot type on capture; must be a
                // valid EntityType value for restore to work.
                MetadataKeys::ENTITY_TYPE => $carrier->value,
                MetadataKeys::MOB_TYPE => $carrier->value,
            ];

        $entity = (new EntityBuilder())
            ->with(new PositionComponent($loc->x, $loc->y, $loc->z))
            ->with(new RotationComponent($loc->yaw, $loc->pitch, $loc->yaw))
            ->with(new MetadataComponent($metaData))
            ->with(new WorldComponent($worldId))
            ->withTag(EntityTags::PERSISTENT)
            ->build($kernel->getWorld());

        return $entity->id;
    }

    public function dematerialize(int $ecsEntityId): void {
        $kernel = Kernel::getInstance();
        if ($kernel === null) {
            return;
        }
        $entity = $kernel->getWorld()->getEntity($ecsEntityId);
        if ($entity !== null) {
            // No UNIQUE_ID metadata on NPCs, so the despawn path writes no
            // entity file; the chunk's next capture pass simply no longer
            // sees it - removal is permanent.
            $kernel->getWorld()->despawn($entity);
        }
    }

    public function adoptRestoredNpcs(int $worldId, int $chunkX, int $chunkZ): array {
        $kernel = Kernel::getInstance();
        if ($kernel === null) {
            return [];
        }
        $worldFolder = $this->worldFolder($worldId);
        if ($worldFolder === null) {
            return [];
        }

        $found = [];
        foreach ($kernel->getWorld()->getEntities() as $entity) {
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            if (((int)floor($pos->x / 16)) !== $chunkX || ((int)floor($pos->z / 16)) !== $chunkZ) {
                continue;
            }
            $worldComp = $entity->get(WorldComponent::class);
            if ($worldComp === null || $worldComp->id !== $worldId) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            $npcIdValue = $meta?->get(NpcMapper::META_NPC_ID);
            if (!is_string($npcIdValue) || $npcIdValue === '') {
                continue;
            }

            $this->repairRestoredEntity($entity);

            $rot = $entity->get(RotationComponent::class);
            $found[] = [
                'ecsEntityId' => $entity->id,
                'world' => $worldFolder,
                'x' => $pos->x,
                'y' => $pos->y,
                'z' => $pos->z,
                'yaw' => $rot?->yaw ?? 0.0,
                'pitch' => $rot?->pitch ?? 0.0,
                'meta' => $meta->data,
            ];
        }
        return $found;
    }

    public function worldFolder(int $worldId): ?string {
        $registry = $this->worldRegistry();
        if ($registry === null) {
            return $worldId === 0 ? 'world' : null;
        }
        $world = $registry->getWorld($worldId);
        return $world !== null ? (string)$world['folderName'] : null;
    }

    // ---- internals -----------------------------------------------------------

    /**
     * The generic snapshot restore cannot preserve two things we depend on:
     *  - the boolean PERSISTENT tag (only object components round-trip), and
     *  - our minimal component set: restoreEntityFromSnapshot() respawns via
     *    spawnEntity(), which attaches Velocity/Health/AIState/Collision mob
     *    defaults that would let the AI system wander the NPC and combat
     *    damage it.
     */
    private function repairRestoredEntity(\pocketmine\core\ecs\Entity $entity): void {
        $entity->set(EntityTags::PERSISTENT, true);
        foreach ([\pocketmine\core\component\AIStateComponent::class, \pocketmine\core\component\VelocityComponent::class, \pocketmine\core\component\HealthComponent::class, \pocketmine\core\component\CollisionComponent::class] as $strip) {
            if ($entity->has($strip)) {
                $entity->remove($strip);
            }
        }
        $meta = $entity->get(MetadataComponent::class);
        if ($meta !== null) {
            $meta->remove(MetadataKeys::HOSTILE);
            $meta->remove(MetadataKeys::PASSIVE);
            $meta->remove('shorn');
            $meta->remove('shornAt');
        }
    }

    /** The EntityType the client renders for this NPC's model. */
    private function carrierTypeFor(Npc $npc): ?EntityType {
        $model = $npc->appearance()->model;
        if ($model->isEntityType()) {
            $type = EntityType::tryFrom($model->value());
            if ($type !== null) {
                return $type;
            }
            error_log('[NPC] Unknown model "' . $model->value() . '" for NPC ' . $npc->id()->value() . ', falling back to ' . self::CARRIER_MODEL);
        }
        return EntityType::tryFrom(self::CARRIER_MODEL);
    }

    private function worldId(string $worldFolder): ?int {
        $registry = $this->worldRegistry();
        if ($registry === null) {
            return $worldFolder === 'world' ? 0 : null;
        }
        $id = $registry->getWorldIdByName($worldFolder);
        if ($id !== null) {
            return $id;
        }
        foreach ($registry->getWorlds() as $world) {
            if ($world['folderName'] === $worldFolder) {
                return (int)$world['id'];
            }
        }
        return null;
    }

    private function worldRegistry(): ?WorldRegistry {
        $kernel = Kernel::getInstance();
        if ($kernel === null) {
            return null;
        }
        $registry = $kernel->getResourceRegistry()->get(WorldRegistry::class);
        return $registry instanceof WorldRegistry ? $registry : null;
    }
}
