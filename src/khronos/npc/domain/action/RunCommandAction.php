<?php

declare(strict_types=1);

namespace khronos\npc\domain\action;

/**
 * Run a server command as the interacting player (or as console).
 * The stored command line has no leading slash.
 */
final class RunCommandAction implements InteractionAction {
    public function __construct(
        public readonly string $command,
        public readonly bool $asConsole = false,
    ) {
        if (trim($this->command) === '') {
            throw new \InvalidArgumentException('Command must not be empty');
        }
    }

    public static function type(): string {
        return 'run_command';
    }

    public function toArray(): array {
        return [
            'command' => ltrim($this->command, '/'),
            'as_console' => $this->asConsole,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        return new self(
            (string)($data['command'] ?? ''),
            (bool)($data['as_console'] ?? false),
        );
    }
}
