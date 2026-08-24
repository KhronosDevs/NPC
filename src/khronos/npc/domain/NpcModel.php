<?php

declare(strict_types=1);

namespace khronos\npc\domain;

/**
 * The visual model of an NPC.
 *
 * Any built-in entity type name is allowed ("Villager", "Zombie", "Pig", ...)
 * plus two special models:
 *
 *  - Human:    rendered as a player-form entity (custom skin support).
 *  - Invisible: no body rendered - only the floating name tag shows.
 *
 * This class deliberately does NOT know which entity types the server
 * supports; infrastructure validates concrete names against the runtime's
 * entity registry. Only the two special models are defined here.
 */
final class NpcModel implements \Stringable {
    public const HUMAN = 'Human';
    public const INVISIBLE = 'Invisible';

    private function __construct(
        private readonly string $value,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('NPC model must not be empty');
        }
    }

    /** Accepts any casing; canonical form keeps the first letter upper-case. */
    public static function fromString(string $value): self {
        $normalized = ucfirst(strtolower(trim($value)));
        return new self($normalized);
    }

    public static function human(): self {
        return new self(self::HUMAN);
    }

    public static function invisible(): self {
        return new self(self::INVISIBLE);
    }

    public function value(): string {
        return $this->value;
    }

    public function isHuman(): bool {
        return $this->value === self::HUMAN;
    }

    public function isInvisible(): bool {
        return $this->value === self::INVISIBLE;
    }

    /** True for anything that is not one of the special models. */
    public function isEntityType(): bool {
        return !$this->isHuman() && !$this->isInvisible();
    }

    public function equals(self $other): bool {
        return $this->value === $other->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
