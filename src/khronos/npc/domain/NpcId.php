<?php

declare(strict_types=1);

namespace khronos\npc\domain;

/**
 * Immutable identifier of an NPC aggregate. A URL-safe random string so it
 * can be used both as a database primary key and as an ECS metadata marker.
 */
final class NpcId implements \Stringable {
    private function __construct(
        private readonly string $value,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('NPC id must not be empty');
        }
    }

    public static function generate(): self {
        try {
            $random = bin2hex(random_bytes(8));
        } catch (\Random\RandomException) {
            $random = bin2hex(uniqid('', true));
        }
        return new self('npc-' . $random);
    }

    public static function fromString(string $value): self {
        return new self($value);
    }

    public function value(): string {
        return $this->value;
    }

    public function equals(self $other): bool {
        return $this->value === $other->value;
    }

    public function __toString(): string {
        return $this->value;
    }
}
