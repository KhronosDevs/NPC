<?php

declare(strict_types=1);

namespace khronos\npc\domain\action;

/**
 * Open a simple dialog: a titled, timed popup menu rendered as an on-screen
 * popup (protocol 84 has no form UI). Lines are shown under the title.
 */
final class ShowDialogAction implements InteractionAction {
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines = [],
        public readonly int $durationSeconds = 5,
    ) {
        if (trim($this->title) === '') {
            throw new \InvalidArgumentException('A dialog needs a title');
        }
    }

    public static function type(): string {
        return 'show_dialog';
    }

    public function toArray(): array {
        return [
            'title' => $this->title,
            'lines' => $this->lines,
            'duration' => $this->durationSeconds,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        $lines = $data['lines'] ?? [];
        return new self(
            (string)($data['title'] ?? ''),
            array_values(array_map('strval', (array)$lines)),
            max(1, (int)($data['duration'] ?? 5)),
        );
    }
}
