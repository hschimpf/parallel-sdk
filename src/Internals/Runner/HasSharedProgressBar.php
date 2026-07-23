<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use Symfony\Component\Console\Helper\Helper;

trait HasSharedProgressBar {

    use HasProgressBar;

    /**
     * @var array Memory usage between threads
     */
    private array $threads_memory = [
        'current' => [ '__main__' => 0 ],
        'peak'    => [ '__main__' => 0 ],
    ];

    /**
     * @var array Total of items processed per second
     */
    private array $items = [];

    /**
     * @var bool Flag to identify if the ProgressBar instance is initialized
     */
    private bool $progressbar_initialized = false;

    private function initProgressBar(): void {
        if ($this->progressbar_initialized) return;

        $this->createProgressBar();
        $this->progressbar_initialized = true;
    }

    private function stopProgressBar(): void {
        // ProgressBar is owned by this thread; no separate worker to stop
    }

    private function registerProgressBar(string $worker, int $steps = 0): bool {
        if (!$this->progressBarStarted) {
            $this->progressBar->start($steps);
            $this->progressBarStarted = true;

            return true;
        }

        $this->progressBar->setMaxSteps($steps);

        return true;
    }

    private function progressBarAction(string $action, array $args): void {
        // ignore progress actions until the bar is actually started
        if (!$this->progressBarStarted) return;

        // redirect action to ProgressBar instance
        $this->progressBar->$action(...$args);

        if ($action === 'advance') {
            // count processed item
            $this->items[ time() ] = ($this->items[ time() ] ?? 0) + (int) array_shift($args);
            // update ProgressBar items per second report
            $this->progressBar->setMessage($this->getItemsPerSecond(), 'items_per_second');
        }
    }

    private function writeOutput(string $message, bool $newline = true): void {
        if ($this->progressBarStarted) {
            $this->progressBar->clear();
            $this->output->write($message, $newline);
            $this->progressBar->display();

            return;
        }

        $this->output->write($message, $newline);
    }

    private function statsReport(string $worker_id, int $memory_usage): void {
        // save memory usage of thread
        $this->threads_memory['current'][$worker_id] = $memory_usage;
        // update peak memory usage
        if ($this->threads_memory['current'][$worker_id] > ($this->threads_memory['peak'][$worker_id] ?? 0)) {
            $this->threads_memory['peak'][$worker_id] = $this->threads_memory['current'][$worker_id];
        }

        if (!$this->progressBarStarted) return;

        // update ProgressBar memory report
        $this->progressBar->setMessage($this->getMemoryUsage(), 'threads_memory');
    }

    private function getMemoryUsage(): string {
        // main memory used
        $main = Helper::formatMemory($this->threads_memory['current']['__main__']);
        // total memory used (sum of all threads)
        $total = Helper::formatMemory($total_raw = array_sum($this->threads_memory['current']));
        // average of each thread
        $average = Helper::formatMemory((int) ($total_raw / (($count = count($this->threads_memory['current']) - 1) > 0 ? $count : 1)));
        // peak memory usage
        $peak = Helper::formatMemory(array_sum($this->threads_memory['peak']));

        return "$main, threads: {$count}x ~$average, Σ $total ↑ $peak";
    }

    private function getItemsPerSecond(): string {
        // check for empty list
        if ($this->items === []) return '0';

        // keep only last 15s for average
        $this->items = array_slice($this->items, -15, preserve_keys: true);

        // return the average of items processed per second
        return '~'.number_format(floor(array_sum($this->items) / count($this->items) * 100) / 100, 2);
    }

}
