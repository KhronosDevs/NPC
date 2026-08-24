<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\config;

use khronos\npc\domain\NpcAppearance;
use pocketmine\api\plugin\Config;

/**
 * Typed accessor over the plugin's config.yml. All defaults mirror
 * config.yml; the file itself is the documentation.
 */
final class NpcConfig {
    public function __construct(
        private readonly bool $enabled,
        private readonly bool $debug,
        private readonly bool $interactionEnabled,
        private readonly int $cooldownTicks,
        private readonly bool $feedbackMessages,
        private readonly bool $invulnerable,
        private readonly string $defaultModel,
        private readonly float $defaultScale,
        private readonly bool $showNametags,
        private readonly bool $dialogEnabled,
        private readonly int $dialogDurationSeconds,
    ) {}

    public static function load(string $dataFolder): self {
        $config = new Config(rtrim($dataFolder, '/') . '/config.yml', [
            'enabled' => true,
            'debug' => false,
            'interaction' => [
                'enabled' => true,
                'cooldown-ticks' => 10,
                'feedback-messages' => true,
            ],
            'npcs' => [
                'invulnerable' => true,
                'default-model' => 'Villager',
                'default-scale' => 1.0,
            ],
            'appearance' => [
                'show-nametags' => true,
            ],
            'dialog' => ['enabled' => true, 'duration-seconds' => 5],
        ]);

        return new self(
            (bool)$config->get('enabled', true),
            (bool)$config->get('debug', false),
            (bool)$config->get('interaction.enabled', true),
            max(0, (int)$config->get('interaction.cooldown-ticks', 10)),
            (bool)$config->get('interaction.feedback-messages', true),
            (bool)$config->get('npcs.invulnerable', true),
            ucfirst(strtolower((string)$config->get('npcs.default-model', 'Villager'))),
            (float)$config->get('npcs.default-scale', 1.0),
            (bool)$config->get('appearance.show-nametags', true),
            (bool)$config->get('dialog.enabled', true),
            max(1, (int)$config->get('dialog.duration-seconds', 5)),
        );
    }

    public function enabled(): bool {
        return $this->enabled;
    }

    public function debug(): bool {
        return $this->debug;
    }

    public function interactionEnabled(): bool {
        return $this->interactionEnabled;
    }

    public function cooldownTicks(): int {
        return $this->cooldownTicks;
    }

    public function feedbackMessages(): bool {
        return $this->feedbackMessages;
    }

    public function invulnerable(): bool {
        return $this->invulnerable;
    }

    public function defaultModel(): string {
        return $this->defaultModel;
    }

    public function defaultScale(): float {
        return min(NpcAppearance::MAX_SCALE, max(NpcAppearance::MIN_SCALE, $this->defaultScale));
    }

    public function showNametags(): bool {
        return $this->showNametags;
    }

    public function dialogEnabled(): bool {
        return $this->dialogEnabled;
    }

    public function dialogDurationSeconds(): int {
        return $this->dialogDurationSeconds;
    }
}
