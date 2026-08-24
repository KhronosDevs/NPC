<?php

declare(strict_types=1);

namespace khronos\npc\infrastructure\player;

use pocketmine\adapter\driven\threading\PluginFuture;
use pocketmine\adapter\driven\threading\PluginTask;
use pocketmine\port\driven\ThreadingPort;

/**
 * Async Java-skin fetcher: main thread submits URLs, worker threads do the
 * HTTP download + PNG decode, completion callbacks fire on the main thread.
 *
 * Bounded concurrency (at most MAX_INFLIGHT downloads at once, FIFO for
 * the rest) so a burst of /npc skin commands cannot stampede the pool.
 * Futures are polled by a per-tick drain - never PluginFuture::then(),
 * whose callbacks the kernel does not drain reliably.
 */
final class SkinFetcher {
    private const MAX_INFLIGHT = 2;

    /** @var list<array{SkinFetchTask, callable(string, string): void}> task + onDone(rawBytes, error) */
    private array $queue = [];
    /** @var list<array{PluginFuture, SkinFetchTask, callable(string, string): void}> */
    private array $inflight = [];

    public function __construct(
        private readonly ThreadingPort $threading,
        ?\pocketmine\api\scheduler\Scheduler $scheduler = null,
    ) {
        if ($scheduler !== null) {
            $scheduler->scheduleRepeatingTask(function (): void {
                $this->drain();
            }, 1);
        }
    }

    /**
     * Fetch a skin PNG and hand raw RGBA bytes to $onDone on the main
     * thread. $onDone(string $rawBytes, string $error) - exactly one of
     * the two is non-empty.
     */
    public function fetch(string $url, callable $onDone): void {
        $task = new SkinFetchTask();
        $task->url = $url;
        $this->queue[] = [$task, $onDone];
        $this->pump();
    }

    /** True while downloads run or are queued. */
    public function hasPending(): bool {
        return $this->queue !== [] || $this->inflight !== [];
    }

    private function pump(): void {
        while ($this->queue !== [] && count($this->inflight) < self::MAX_INFLIGHT) {
            [$task, $onDone] = array_shift($this->queue);
            try {
                $future = $this->threading->submitPluginTask($task);
            } catch (\Throwable $e) {
                // No real worker pool available: degrade to inline fetch so
                // the feature still works (worst case: blocks this tick).
                error_log('[NPC] Skin fetch worker unavailable, running inline: ' . $e->getMessage());
                $task->run();
                $this->finish($task, $onDone);
                continue;
            }
            $this->inflight[] = [$future, $task, $onDone];
        }
    }

    private function drain(): void {
        if ($this->inflight === []) {
            return; // hot path: single empty-check per tick
        }
        foreach ($this->inflight as $k => [$future, $task, $onDone]) {
            if (!$future->isDone()) {
                continue;
            }
            unset($this->inflight[$k]);
            $this->inflight = array_values($this->inflight);
            if ($future->isCancelled()) {
                continue;
            }
            $this->finish($task, $onDone);
        }
        $this->pump();
    }

    private function finish(SkinFetchTask $task, callable $onDone): void {
        try {
            $onDone($task->result, $task->error);
        } catch (\Throwable $e) {
            error_log('[NPC] Skin fetch callback failed: ' . $e->getMessage());
        }
    }
}
