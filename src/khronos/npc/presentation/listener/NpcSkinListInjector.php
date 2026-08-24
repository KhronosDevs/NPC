<?php

declare(strict_types=1);

namespace khronos\npc\presentation\listener;

use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\domain\Npc;
use khronos\npc\presentation\provider\NpcAddPacketProvider;
use pocketmine\api\event\DataPacketSendEvent;
use pocketmine\protocol\AddPlayerPacket;
use pocketmine\protocol\DataPacket;
use pocketmine\protocol\MoveEntityPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayerListPacket;

/**
 * Companion to NpcAddPacketProvider: protocol 84 clients need a
 * PlayerListPacket entry BEFORE an AddPlayerPacket or they render no skin.
 *
 * The core builds our provider's AddPlayerPacket per viewer inside its own
 * batch pipeline, so we ride the outbound stream. For every Human NPC add
 * we inject the legacy trio in one batch (the add packet is already queued
 * ahead of ours, so the wire order matches old-src NPC plugins exactly):
 *
 *     AddPlayerPacket          - username EMPTY; name comes from metadata
 *     PlayerListPacket(ADD)    - skin applied (entry name empty)
 *     PlayerListPacket(REMOVE) - tab-list entry dropped again
 *
 * The skin stays applied and the entity renders with its floating nametag,
 * but nothing ever appears in the player/tab list.
 *
 * The fake UUID fingerprints the skin content: a re-render after a skin
 * change produces a DIFFERENT uuid, so clients that cache skins by uuid
 * (all of them - this is exactly why a relog used to be required) pick up
 * the new skin immediately.
 */
final class NpcSkinListInjector {
    public function __construct(
        private readonly NpcRegistry $registry,
        /** fn(int $viewerEntityId, DataPacket $packet): bool - Plugin::sendPacketTo() */
        private readonly \Closure $sendPacketTo,
    ) {}

    public function onDataPacketSend(DataPacketSendEvent $event): void {
        $packet = $event->getPacket();

        // Hot path: every outbound game packet lands here. One instanceof
        // chain, zero work for unrelated packets (including our own injected
        // list packets and the converted MovePlayerPacket below).
        if (!$packet instanceof AddPlayerPacket && !$packet instanceof MoveEntityPacket) {
            return;
        }

        $npc = $this->registry->findByEcsEntityId($packet->eid);
        if ($npc === null || !$npc->appearance()->model->isHuman()) {
            return;
        }

        if ($packet instanceof MoveEntityPacket) {
            // The client moves player-form entities via MovePlayerPacket only.
            $event->setCancelled(true);
            $move = new MovePlayerPacket();
            $move->eid = $packet->eid;
            $move->x = $packet->x;
            $move->y = $packet->y;
            $move->z = $packet->z;
            $move->yaw = $packet->yaw;
            $move->bodyYaw = $packet->yaw;
            $move->headYaw = $packet->headYaw;
            $move->pitch = $packet->pitch;
            $move->mode = MovePlayerPacket::MODE_NORMAL;
            $move->onGround = true;
            ($this->sendPacketTo)($event->getPlayer()->getId(), $move);
            return;
        }

        // Human add: legacy NPC sandwich. Our injection runs while the
        // AddPlayerPacket itself is being queued, so appending here puts
        // the list packets AFTER it on the wire - the exact proven order:
        //
        //     AddPlayerPacket         (already in the outbound queue,
        //                              username empty)
        //     PlayerListPacket(ADD)   - skin applied (entry name empty, so
        //                               nothing shows in the tab list even
        //                               transiently)
        //     PlayerListPacket(REMOVE)- entry dropped again
        //
        // The entity and its floating nametag (DATA_NAMETAG metadata) stay;
        // only the tab-list entry is transient.
        $viewerId = $event->getPlayer()->getId();
        $uuid = NpcAddPacketProvider::fakeUuid(
            $npc->id()->value(),
            $npc->appearance()->skinData,
            $npc->appearance()->slim,
        );

        $add = new PlayerListPacket();
        $add->type = PlayerListPacket::TYPE_ADD;
        $add->entries = [[
            $uuid,
            $packet->eid,
            '',
            $npc->appearance()->slim ? '1' : '0',
            self::skinBytes($npc),
        ]];
        ($this->sendPacketTo)($viewerId, $add);

        $remove = new PlayerListPacket();
        $remove->type = PlayerListPacket::TYPE_REMOVE;
        $remove->entries = [[$uuid]];
        ($this->sendPacketTo)($viewerId, $remove);
    }

    /** Skin payload for the fake player-list entry (raw bytes or decoded base64). */
    private static function skinBytes(Npc $npc): string {
        $data = $npc->appearance()->skinData;
        if ($data === '') {
            return '';
        }
        $decoded = base64_decode($data, true);
        return $decoded !== false ? $decoded : $data;
    }
}
