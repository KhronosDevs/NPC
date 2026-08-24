<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

/**
 * Skin byte helpers for Human-form NPCs.
 *
 * Protocol-84 skins are RAW RGBA pixel buffers (not PNG): 64x32 = 8192
 * bytes or 64x64 = 16384 bytes. The 0.15.10 client crashes while building
 * the humanoid model for a player entity whose list entry carries an
 * empty/invalid skin, so every skin that leaves this class is guaranteed
 * to have a valid size - invalid input falls back to a generated default.
 */
final class NpcSkins {
    private const WIDTH = 64;
    private const VALID_LENGTHS = [8192, 16384]; // 64x32, 64x64 RGBA

    public static function isValid(?string $bytes): bool {
        return $bytes !== null && in_array(strlen($bytes), self::VALID_LENGTHS, true);
    }

    /** Valid skin bytes for $input, or the generated default when invalid. */
    public static function sanitize(?string $bytes): string {
        if (self::isValid($bytes)) {
            return (string)$bytes;
        }
        error_log('[NPC] Invalid or missing NPC skin (' . ($bytes === null ? 'null' : strlen($bytes) . ' bytes') . '), using generated default.');
        return self::defaultSkin();
    }

    /**
     * A simple procedurally generated 64x32 RGBA skin (tan head, cyan top,
     * blue legs) - just enough of a valid texture to render safely.
     */
    public static function defaultSkin(): string {
        $w = self::WIDTH;
        $h = 32;
        $buf = str_repeat("\x00\x00\x00\x00", $w * $h);

        $fill = static function (int $x1, int $y1, int $x2, int $y2, array $rgba) use (&$buf, $w): void {
            for ($y = $y1; $y <= $y2; $y++) {
                for ($x = $x1; $x <= $x2; $x++) {
                    $offset = ($y * $w + $x) * 4;
                    $buf[$offset] = chr($rgba[0]);
                    $buf[$offset + 1] = chr($rgba[1]);
                    $buf[$offset + 2] = chr($rgba[2]);
                    $buf[$offset + 3] = chr(255);
                }
            }
        };

        // Front head on the head-up layout: face at (8..23, 8..15).
        $fill(8, 8, 23, 15, [226, 192, 152]);   // face
        // Body front (20..27, 20..31) torso area of the classic layout.
        $fill(20, 20, 27, 31, [0, 160, 180]);   // shirt
        $fill(28, 20, 35, 31, [60, 60, 160]);   // legs
        $fill(36, 20, 43, 31, [226, 192, 152]); // arm

        return $buf;
    }
}
