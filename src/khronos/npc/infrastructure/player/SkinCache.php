<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

use pocketmine\api\event\DataPacketSendEvent;
use pocketmine\protocol\PlayerListPacket;

/**
 * Captures online players' skins from the outbound player-list stream.
 *
 * There is no public API to read a session's skin (it lives in the network
 * service's private session array), but every player's own list entry -
 * which carries their raw login skin - is broadcast through the packet
 * pipeline at join. We cache those bytes keyed by entity id so a Human NPC
 * can be given its CREATOR's skin at spawn time. The captured bytes are
 * then persisted inside the NPC's metadata and rendered to every viewer;
 * the viewer's own skin is never used.
 */
final class SkinCache {
    /** @var array<int, string> player entity id => raw skin bytes */
    private array $skins = [];

    public function onDataPacketSend(DataPacketSendEvent $event): void {
        $packet = $event->getPacket();
        if (!$packet instanceof PlayerListPacket || $packet->type !== PlayerListPacket::TYPE_ADD) {
            return;
        }
        foreach ($packet->entries as $entry) {
            // Entry layout: [UUID, entityId(long), name, slim, skin]
            if (!isset($entry[1]) || !is_string($entry[4] ?? null)) {
                continue;
            }
            $skin = (string)$entry[4];
            if ($skin !== '') {
                $this->skins[(int)$entry[1]] = $skin;
            }
        }
    }

    /** Raw skin bytes of an online player, or null when unknown yet. */
    public function skinFor(int $playerEntityId): ?string {
        return $this->skins[$playerEntityId] ?? null;
    }

    public function forget(int $playerEntityId): void {
        unset($this->skins[$playerEntityId]);
    }
}
