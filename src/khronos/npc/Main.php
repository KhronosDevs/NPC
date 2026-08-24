<?php

declare(strict_types=1);

namespace khronos\npc;

use khronos\npc\application\NpcMapper;
use khronos\npc\application\NpcService;
use khronos\npc\application\registry\NpcRegistry;
use khronos\npc\application\usecase\ChunkMaterializer;
use khronos\npc\application\usecase\HandleInteractionUseCase;
use khronos\npc\application\usecase\RemoveNpcUseCase;
use khronos\npc\application\usecase\SpawnNpcUseCase;
use khronos\npc\infrastructure\config\NpcConfig;
use khronos\npc\infrastructure\ecs\EcsNpcEntityBridge;
use khronos\npc\infrastructure\player\KernelActionExecutor;
use khronos\npc\infrastructure\player\PngToRaw;
use khronos\npc\infrastructure\player\SkinCache;
use khronos\npc\infrastructure\player\SkinFetcher;
use khronos\npc\infrastructure\player\SkinFetchTask;
use khronos\npc\infrastructure\player\NpcSkins;
use khronos\npc\presentation\command\NpcCommand;
use khronos\npc\presentation\listener\ChunkLifecycleListener;
use khronos\npc\presentation\listener\InteractionListener;
use khronos\npc\presentation\listener\NpcSkinListInjector;
use khronos\npc\presentation\provider\NpcAddPacketProvider;
use pocketmine\api\plugin\Plugin;

/**
 * Composition root: the only place that knows concrete implementations.
 * Wires domain -> application -> infrastructure -> presentation and
 * registers commands, event listeners and the add-packet provider.
 *
 * Persistence: NPCs are stored BY THE WORLD. Spawned entities carry
 * EntityTags::PERSISTENT and their full state in metadata, so the core's
 * chunk-snapshot pipeline saves/restores them across unloads and restarts.
 * There is no plugin-side database.
 */
final class Main extends Plugin {
    private ?NpcService $service = null;
    private ?NpcSkinListInjector $skinInjector = null;
    private ?NpcAddPacketProvider $packetProvider = null;
    private ?SkinCache $skins = null;

    public function onEnable(): void {
        $cfg = NpcConfig::load($this->getDataFolder());
        if (!$cfg->enabled()) {
            $this->getLogger()->info('NPC is disabled in config.yml; staying passive.');
            return;
        }

        // pmmpthread workers cannot autoload: pre-load every class a task's
        // run() touches BEFORE the first submission (skin fetch pipeline).
        class_exists(SkinFetchTask::class);
        class_exists(PngToRaw::class);
        class_exists(NpcSkins::class);

        // ---- shared services ---------------------------------------------
        $registry = new NpcRegistry();
        $mapper = new NpcMapper();
        $bridge = new EcsNpcEntityBridge($mapper);
        $executor = new KernelActionExecutor($cfg->dialogEnabled(), $cfg->dialogDurationSeconds());

        // Tick source for cooldowns (kernel tick counter; 0 when absent).
        $tickProvider = static function (): int {
            return \pocketmine\Kernel::getInstance()
                ?->getResourceRegistry()
                ?->get(\pocketmine\core\resource\TickCounter::class)
                ?->value ?? 0;
        };

        // ---- use cases -----------------------------------------------------
        $spawnUseCase = new SpawnNpcUseCase($registry, $bridge);
        $removeUseCase = new RemoveNpcUseCase($registry, $bridge);
        $interactionUseCase = new HandleInteractionUseCase(
            $registry,
            $executor,
            $cfg->interactionEnabled(),
            $cfg->cooldownTicks(),
            $tickProvider,
        );
        $chunkMaterializer = new ChunkMaterializer($registry, $bridge, $mapper);

        $this->service = new NpcService(
            $registry,
            $spawnUseCase,
            $removeUseCase,
            $interactionUseCase,
            $chunkMaterializer,
            $executor,
        );

        // ---- rendering: add-packet provider + skin-list injection ----------
        $plugin = $this;
        $subscribe = static function (string $class, callable $handler) use ($plugin): void {
            $plugin->registerEvent($class, $handler);
        };
        $sendPacketTo = static fn(int $viewerId, \pocketmine\protocol\DataPacket $pk): bool => $plugin->sendPacketTo($viewerId, $pk);

        // Creator-skin capture: players' skins ride the outbound player-list
        // stream; a Human NPC stores its creator's skin at spawn time.
        $this->skins = new SkinCache();
        $subscribe(\pocketmine\api\event\DataPacketSendEvent::class,
            fn($e) => $this->skins?->onDataPacketSend($e));
        $subscribe(\pocketmine\api\event\PlayerQuitEvent::class,
            fn($e) => $this->skins?->forget($e->getPlayer()->getId()));

        $this->packetProvider = new NpcAddPacketProvider($registry, $cfg->showNametags());
        $this->registerAddPacketProvider($this->packetProvider);

        $this->skinInjector = new NpcSkinListInjector($registry, $sendPacketTo);

        // ---- presentation adapters ------------------------------------------
        $interactionListener = new InteractionListener($this->service, $cfg->invulnerable());
        $lifecycleListener = new ChunkLifecycleListener($this->service);

        $subscribe(\pocketmine\api\event\DataPacketSendEvent::class,
            fn($e) => $this->skinInjector?->onDataPacketSend($e));
        $subscribe(\pocketmine\api\event\PlayerInteractEvent::class,
            fn($e) => $interactionListener->onPlayerInteract($e));
        $subscribe(\pocketmine\api\event\EntityDamageEvent::class,
            fn($e) => $interactionListener->onEntityDamage($e));
        $subscribe(\pocketmine\api\event\ChunkLoadEvent::class,
            fn($e) => $this->service?->onChunkLoaded($e->getWorldId(), $e->getChunkX(), $e->getChunkZ()));
        $subscribe(\pocketmine\api\event\ChunkUnloadEvent::class,
            fn($e) => $this->service?->onChunkUnloaded($e->getWorldId(), $e->getChunkX(), $e->getChunkZ()));
        $subscribe(\pocketmine\api\event\PlayerQuitEvent::class,
            fn($e) => $lifecycleListener->onPlayerQuit($e));

        // ---- command ---------------------------------------------------------
        $skinFetcher = new SkinFetcher($this->getThreadingPort(), $this->getScheduler());
        $this->registerCommand(new NpcCommand(
            $this->service,
            $cfg->defaultModel(),
            $cfg->defaultScale(),
            $this->skins ?? throw new \LogicException('SkinCache not initialized'),
            $skinFetcher,
        ));

        $this->getLogger()->info('NPC v' . $this->getVersion() . ' enabled (world-persistent storage).');
    }

    public function onDisable(): void {
        if ($this->packetProvider !== null) {
            $this->unregisterAddPacketProvider($this->packetProvider);
        }
        $this->getLogger()->info('NPC disabled.');
    }

    /** For tests / other plugins that legitimately need the facade. */
    public function service(): NpcService {
        return $this->service ?? throw new \LogicException('NPC service not initialized');
    }
}
