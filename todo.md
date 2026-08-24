# NPC plugin - known issues / TODO

## Tab list shows an empty entry for Human NPCs (cosmetic)

Status: open, low priority.

What happens: spawning a Human-form NPC briefly adds a player-list entry
and an *empty-named* entry can remain visible in the client's tab /
pause-screen player list.

What we already do (matches old-src NPC plugins):
- AddPlayerPacket with `username: ""`
- wire order: AddPlayerPacket -> PlayerListPacket(ADD, empty name) ->
  PlayerListPacket(REMOVE)

Remaining ideas to try:
1. Send the REMOVE entry twice (some 0.15 builds dedupe ADD/REMOVE pairs
   inside one batch).
2. Defer the REMOVE by a few ticks instead of same-batch (client may apply
   the ADD after the batch-internal REMOVE).
3. Reuse the viewing player's own skin UUID namespace or a version-4
   random UUID per add - some clients special-case deterministic UUIDs.
4. Investigate whether the pause-screen count comes from
   `PlayerListPacket` state at all on 0.15.10 or from session tracking,
   in which case only Mojang-side behavior would show it and it can be
   ignored.
