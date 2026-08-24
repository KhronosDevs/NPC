<?php

declare(strict_types=1);

namespace khronos\npc\domain;

/**
 * Visual appearance of an NPC: name tag, scale, model and (for Human models)
 * skin. Immutable - changing anything produces a new instance.
 */
final class NpcAppearance {
    public const MIN_SCALE = 0.1;
    public const MAX_SCALE = 10.0;

    public function __construct(
        public readonly string $nametag,
        public readonly float $scale = 1.0,
        public readonly NpcModel $model = new NpcModel('Villager'),
        public readonly string $skinData = '',
        public readonly bool $slim = false,
    ) {
        if ($scale < self::MIN_SCALE || $scale > self::MAX_SCALE) {
            throw new \InvalidArgumentException(sprintf(
                'NPC scale must be between %s and %s, got %s',
                (string)self::MIN_SCALE,
                (string)self::MAX_SCALE,
                (string)$scale,
            ));
        }
    }

    public function withNametag(string $nametag): self {
        return new self($nametag, $this->scale, $this->model, $this->skinData, $this->slim);
    }

    public function withScale(float $scale): self {
        return new self($this->nametag, $scale, $this->model, $this->skinData, $this->slim);
    }

    public function withModel(NpcModel $model): self {
        return new self($this->nametag, $this->scale, $model, $this->skinData, $this->slim);
    }

    public function withSkin(string $skinData, bool $slim): self {
        return new self($this->nametag, $this->scale, $this->model, $skinData, $slim);
    }
}
