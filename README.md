# NPC plugin (khronos/npc)

Static, persistent NPCs for Khronos (MCPE protocol 84): any entity model,
player-form "Human" NPCs with skins, invisible text-only NPCs, custom
nametags and scale, configurable interaction actions, chunk-scoped lazy
loading.

## Storage: NPCs are stored BY THE WORLD

There is no plugin-side database. Spawned NPC entities carry
`EntityTags::PERSISTENT`, and the NPC's full state (nametag, scale, model,
skin, actions, ...) lives in the entity's `MetadataComponent`. The core's
chunk-snapshot pipeline therefore:

- captures every NPC when its chunk unloads (alongside tile entities),
- restores it - all components intact - when the chunk loads again,
- survives full server restarts.

Two restore gaps are repaired by this plugin at chunk-load time
(`EcsNpcEntityBridge::repairRestoredEntity()`):

1. the boolean `PERSISTENT` tag itself is not an object component, so it
   does not survive a snapshot round-trip and is re-applied;
2. the generic restore respawns through `EntitySpawnService`, which
   attaches mob defaults (`AIState/Velocity/Health/Collision`) - these are
   stripped so NPCs stay static, immortal and AI-free.

Consequence: `/npc list` shows NPCs whose chunks are currently loaded.
Editing commands (rename, move, actions, ...) also require the NPC's chunk
to be loaded - which it normally is, since you stand next to it.

## Architecture (DDD)

```
src/khronos/npc/
├── Main.php                     composition root (only place knowing concretes)
├── domain/                      pure business logic - zero server imports
│   ├── Npc.php                  aggregate root; records domain events
│   ├── NpcId.php                identity value object
│   ├── NpcModel.php             model VO: any entity type + Human + Invisible
│   ├── NpcAppearance.php        nametag / scale / model / skin VO
│   ├── NpcLocation.php          world-folder + position VO
│   ├── action/                  InteractionAction + the four built-in actions
│   └── event/                   NpcSpawned / NpcInteracted / NpcRemoved
├── application/                 orchestration
│   ├── NpcService.php           facade used by commands/listeners
│   ├── NpcMapper.php            aggregate <-> entity-metadata payload codec
│   │                            (+ extensible action factories)
│   ├── registry/NpcRegistry.php O(1) indexes: by id, by ECS entity, by world+chunk
│   ├── port/                    NpcEntityBridge, ActionExecutor, InteractionContext
│   └── usecase/
│       ├── SpawnNpcUseCase.php
│       ├── RemoveNpcUseCase.php
│       ├── HandleInteractionUseCase.php   cooldown + action execution
│       └── ChunkMaterializer.php          chunk load/unload adoption & sync
├── infrastructure/              Khronos API adapters
│   ├── ecs/EcsNpcEntityBridge.php         NPC <-> persistent ECS entity
│   ├── player/KernelActionExecutor.php    runs actions against the runtime
│   └── config/NpcConfig.php               typed config.yml access
└── presentation/
    ├── command/NpcCommand.php             /npc ...
    ├── provider/NpcAddPacketProvider.php  kernel add-packet provider:
    │                                      Human -> AddPlayerPacket,
    │                                      Invisible -> flags patch, else
    │                                      AddEntityPacket with DATA_NO_AI
    └── listener/
        ├── NpcSkinListInjector.php        lazy PlayerListPacket entries for
        │                                  Human NPCs (via sendPacketTo),
        │                                  MoveEntity -> MovePlayer conversion
        ├── InteractionListener.php        attack/right-click routing, damage guard
        └── ChunkLifecycleListener.php     chunk load/unload -> materializer
```

Dependency direction is strictly inward: `domain` imports nothing from the
server; `application` imports only `domain`; `infrastructure` and
`presentation` adapt between the Khronos API and the inner layers.

## How rendering works

NPCs are spawned as **minimal static entities** (position, rotation,
metadata, PERSISTENT tag) so no AI/physics/combat system ever touches them.
Rendering goes through the kernel's add-packet provider hook:

- **Human**: the provider returns an `AddPlayerPacket` per viewer add;
  `NpcSkinListInjector` lazily queues the matching `PlayerListPacket`
  entry (skin included) right before it via `sendPacketTo()`, and converts
  follow-up `MoveEntityPacket`s to `MovePlayerPacket`s.
- **Invisible**: carrier `AddEntityPacket` carrying the invisible flag bit -
  only the floating name tag renders.
- **Any other model**: `AddEntityPacket` of that model's network id with
  `DATA_NO_AI`; nametag/scale ride the standard `DISPLAY_NAME`/`SCALE`
  metadata overrides.

Steady-state ticks send nothing new: adds/moves/removals are driven by the
server's own per-viewer tracking.

## Performance notes

- No per-tick scans over all NPCs. Chunk events resolve their NPC set via
  the registry's `world => chunkKey => ids` index (O(NPCs in chunk));
  interaction routing is one hash lookup (`entityId -> npcId`).
- NPCs materialize only when their home chunk is loaded; unload drops only
  the in-memory binding (data lives in the chunk snapshot).
- Persistence is free: writing an NPC = mutating its live entity.

## Adding a new interaction action type

1. **Domain** - create `domain/action/FlyAction.php` implementing
   `InteractionAction` (`type(): string`, `toArray()`) plus a
   `fromArray(array): static` factory. Keep it pure data.
2. **Register the factory** on the `NpcMapper`:
   ```php
   $mapper->registerActionFactory(FlyAction::type(), fn(array $d) => FlyAction::fromArray($d));
   ```
3. **Execute it** - either add a case in `KernelActionExecutor`
   (`supports()` + `execute()`), or write a separate class implementing
   `application/port/ActionExecutor` and compose executors.
4. Done. The metadata payload, cooldowns and chunk lifecycle need no
   changes.

## Commands (permission `npc.admin`)

    /npc spawn <model> <nametag...>      model = any entity type, Human, Invisible
    /npc remove <id> | /npc list
    /npc move <id>                       move to where you stand
    /npc rename <id> <name...>
    /npc scale <id> <0.1..10>
    /npc model <id> <model>
    /npc skin <id> <javaUsername> [slim]  Java skin via minotar.net (async)
    /npc action add command <id> <0|1-console> <command...>
    /npc action add message <id> <text...>
    /npc action add dialog  <id> <title> | <line> | <line>
    /npc action add teleport<id> [world] <x> <y> <z> [yaw] [pitch]
    /npc action clear <id>

NPC ids may be addressed by unique prefix.
