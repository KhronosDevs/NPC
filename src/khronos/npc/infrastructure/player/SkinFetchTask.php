<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

use pocketmine\adapter\driven\threading\PluginTask;

/**
 * One Java-skin download job, executed on a pool worker thread.
 *
 * Fetches a skin PNG over HTTPS (cURL with a file_get_contents fallback),
 * decodes it to a raw RGBA buffer and size-validates it - all off the main
 * thread. Results cross back as plain strings per the PluginTask contract;
 * errors as a message string (exceptions cannot cross pmmpthread).
 */
final class SkinFetchTask extends PluginTask {
    /** Fully qualified URL of the skin PNG. */
    public string $url = '';
    /** Raw RGBA skin bytes on success; empty on failure. */
    public string $result = '';
    /** Non-empty when the fetch/decode failed. */
    public string $error = '';

    private const TIMEOUT_SECONDS = 8;

    public function run(): void {
        try {
            if ($this->url === '') {
                throw new \InvalidArgumentException('Empty skin URL');
            }
            $png = self::httpGet($this->url);
            if ($png === null || $png === '') {
                throw new \RuntimeException('Download failed or player not found');
            }

            // Java skins are 64x32 (legacy) or 64x64 (modern) RGBA PNGs.
            $decoded = PngToRaw::decode($png);
            if ($decoded === null) {
                throw new \RuntimeException('Not a readable PNG skin');
            }
            if (!in_array([$decoded['width'], $decoded['height']], [[64, 32], [64, 64]], true)) {
                throw new \RuntimeException("Unexpected skin size {$decoded['width']}x{$decoded['height']}");
            }

            $this->result = $decoded['data'];
            $this->complete($this->result);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->complete(null);
        }
    }

    /** cURL first (redirects, timeouts), http:// wrapper as fallback. */
    private static function httpGet(string $url): ?string {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return null;
            }
            curl_setopt_array($handle, [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => true,
                \CURLOPT_MAXREDIRS => 4,
                \CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                \CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                \CURLOPT_USERAGENT => 'Khronos-NPC-Plugin',
                \CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if (!is_string($body) || $status !== 200) {
                return null;
            }
            return $body;
        }

        // Fallback: stream wrapper (needs allow_url_fopen).
        if (!ini_get('allow_url_fopen')) {
            return null;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => self::TIMEOUT_SECONDS,
            'header' => "User-Agent: Khronos-NPC-Plugin\r\n",
            'follow_location' => 1,
            'max_redirects' => 4,
        ]]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }
}
