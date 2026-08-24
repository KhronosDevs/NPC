<?php

declare(strict_types=1);

namespace khronos\npc\domain\action;

/**
 * Teleport the interacting player. When $world is null the player stays in
 * their current world; otherwise it is a world folder name.
 */
final class TeleportPlayerAction implements InteractionAction {
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly float $yaw = 0.0,
        public readonly float $pitch = 0.0,
        public readonly ?string $world = null,
    ) {}

    public static function type(): string {
        return 'teleport_player';
    }

    public function toArray(): array {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'yaw' => $this->yaw,
            'pitch' => $this->pitch,
            'world' => $this->world,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        $world = $data['world'] ?? null;
        return new self(
            (float)($data['x'] ?? 0),
            (float)($data['y'] ?? 0),
            (float)($data['z'] ?? 0),
            (float)($data['yaw'] ?? 0),
            (float)($data['pitch'] ?? 0),
            is_string($world) && $world !== '' ? $world : null,
        );
    }
}
