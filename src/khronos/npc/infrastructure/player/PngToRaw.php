<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

/**
 * Minimal dependency-free PNG -> raw RGBA decoder.
 *
 * Protocol-84 player skins are RAW RGBA pixel buffers, while Java skins
 * (fetched e.g. from minotar.net) are PNGs. This server build has no GD,
 * so decoding happens here in pure PHP: enough of the format for real
 * world skins - bit depth 8, color types 0/2/3/4/6, no interlace -
 * running on a pool worker so it never blocks the main thread.
 */
final class PngToRaw {
    /**
     * @return array{data: string, width: int, height: int}|null raw RGBA bytes plus dimensions, or null when unsupported/broken
     */
    public static function decode(string $png): ?array {
        if (strlen($png) < 57 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = 0;
        $interlace = 0;
        $idat = '';
        $palette = null;
        $transparency = null;

        $pos = 8;
        $len = strlen($png);
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $chunkLen);
            $pos += 12 + $chunkLen;

            switch ($type) {
                case 'IHDR':
                    if ($chunkLen < 13) {
                        return null;
                    }
                    $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $data);
                    $width = (int)$header['width'];
                    $height = (int)$header['height'];
                    $bitDepth = (int)$header['bitDepth'];
                    $colorType = (int)$header['colorType'];
                    $interlace = (int)$header['interlace'];
                    break;
                case 'PLTE':
                    $palette = $data;
                    break;
                case 'tRNS':
                    $transparency = $data;
                    break;
                case 'IDAT':
                    $idat .= $data;
                    break;
                case 'IEND':
                    break 2;
            }
        }

        if ($interlace !== 0 || $bitDepth !== 8 || $width <= 0 || $height <= 0 || $idat === '') {
            return null;
        }

        if (!in_array($colorType, [0, 2, 3, 4, 6], true)) {
            return null;
        }
        $bytesPerPixel = match ($colorType) {
            0 => 1, // grayscale
            2 => 3, // RGB
            3 => 1, // palette
            4 => 2, // gray + alpha
            default => 4, // RGBA
        };

        $raw = @gzuncompress($idat);
        if ($raw === false) {
            return null;
        }

        $stride = $width * $bytesPerPixel;
        if (strlen($raw) < ($stride + 1) * $height) {
            return null;
        }

        // Un-filter scanlines (filters 0-4).
        $lines = [];
        $previous = str_repeat("\x00", $stride);
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$offset]);
            $line = substr($raw, $offset + 1, $stride);
            $offset += 1 + $stride;

            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bytesPerPixel ? ord($line[$x - $bytesPerPixel]) : 0;
                $b = ord($previous[$x]);
                $c = $x >= $bytesPerPixel ? ord($previous[$x - $bytesPerPixel]) : 0;
                $v = ord($line[$x]);
                $line[$x] = match ($filter) {
                    1 => chr(($v + $a) & 0xFF),                                  // Sub
                    2 => chr(($v + $b) & 0xFF),                                  // Up
                    3 => chr(($v + intdiv($a + $b, 2)) & 0xFF),                  // Average
                    4 => chr(($v + self::paeth($a, $b, $c)) & 0xFF),             // Paeth
                    default => chr($v),                                          // None
                };
            }
            $lines[] = $line;
            $previous = $line;
        }

        // Expand every supported color type to RGBA.
        $out = str_repeat("\x00\x00\x00\x00", $width * $height);
        $o = 0;
        for ($y = 0; $y < $height; $y++) {
            $line = $lines[$y];
            for ($x = 0; $x < $width; $x++) {
                $i = $x * $bytesPerPixel;
                switch ($colorType) {
                    case 6: // RGBA
                        $out[$o++] = $line[$i];
                        $out[$o++] = $line[$i + 1];
                        $out[$o++] = $line[$i + 2];
                        $out[$o++] = $line[$i + 3];
                        break;
                    case 2: // RGB
                        $out[$o++] = $line[$i];
                        $out[$o++] = $line[$i + 1];
                        $out[$o++] = $line[$i + 2];
                        $out[$o++] = "\xFF";
                        break;
                    case 3: // palette
                        $idx = ord($line[$i]);
                        $r = $g = $b = 0;
                        $a = 255;
                        if ($palette !== null && $idx * 3 + 2 < strlen($palette)) {
                            $r = ord($palette[$idx * 3]);
                            $g = ord($palette[$idx * 3 + 1]);
                            $b = ord($palette[$idx * 3 + 2]);
                            if ($transparency !== null && $idx < strlen($transparency)) {
                                $a = ord($transparency[$idx]);
                            }
                        }
                        $out[$o++] = chr($r);
                        $out[$o++] = chr($g);
                        $out[$o++] = chr($b);
                        $out[$o++] = chr($a);
                        break;
                    case 4: // gray + alpha
                        $gray = $line[$i];
                        $out[$o++] = $gray;
                        $out[$o++] = $gray;
                        $out[$o++] = $gray;
                        $out[$o++] = $line[$i + 1];
                        break;
                    case 0: // grayscale
                        $gray = $line[$i];
                        $out[$o++] = $gray;
                        $out[$o++] = $gray;
                        $out[$o++] = $gray;
                        $out[$o++] = "\xFF";
                        break;
                }
            }
        }

        return ['data' => $out, 'width' => $width, 'height' => $height];
    }

    private static function paeth(int $a, int $b, int $c): int {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        return $pb <= $pc ? $b : $c;
    }
}
