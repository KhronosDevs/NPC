<?php

declare(strict_types=1);

namespace khronos\npc\domain;

/**
 * Where an NPC stands. The world is identified by its folder name (the same
 * stable identifier world storage uses), not by any runtime id.
 */
final class NpcLocation implements \Stringable {
    public function __construct(
        public readonly string $world,
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly float $yaw = 0.0,
        public readonly float $pitch = 0.0,
    ) {
        if ($world === '') {
            throw new \InvalidArgumentException('NPC world must not be empty');
        }
    }

    public function chunkX(): int {
        return (int)floor($this->x / 16);
    }

    public function chunkZ(): int {
        return (int)floor($this->z / 16);
    }

    public function chunkKey(): string {
        return $this->chunkX() . ',' . $this->chunkZ();
    }

    public function movedTo(float $x, float $y, float $z, ?float $yaw = null, ?float $pitch = null): self {
        return new self(
            $this->world,
            $x,
            $y,
            $z,
            $yaw ?? $this->yaw,
            $pitch ?? $this->pitch,
        );
    }

    public function __toString(): string {
        return sprintf('%s (%.2f, %.2f, %.2f)', $this->world, $this->x, $this->y, $this->z);
    }
}
