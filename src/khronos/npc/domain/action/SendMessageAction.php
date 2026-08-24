<?php

declare(strict_types=1);

namespace khronos\npc\domain\action;

/** Send one or more chat lines to the interacting player. */
final class SendMessageAction implements InteractionAction {
    /** @param list<string> $lines */
    public function __construct(
        public readonly array $lines,
    ) {
        if ($this->lines === []) {
            throw new \InvalidArgumentException('A message action needs at least one line');
        }
    }

    public static function type(): string {
        return 'send_message';
    }

    public function toArray(): array {
        return ['lines' => $this->lines];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        $lines = $data['lines'] ?? [];
        if (is_string($lines)) {
            $lines = [$lines];
        }
        return new self(array_values(array_map('strval', (array)$lines)));
    }
}
