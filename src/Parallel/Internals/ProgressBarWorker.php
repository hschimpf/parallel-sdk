<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarActionMessage;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarRegistrationMessage;
use HDSSolutions\Console\Parallel\Internals\Messages\StatsReportMessage;
use parallel\Channel;
use parallel\Events\Event\Type;
use RuntimeException;
use Symfony\Component\Console\Helper\Helper;

final class ProgressBarWorker {
    use ProgressBarWorker\HasChannels;
    use ProgressBarWorker\HasProgressBar;

    /**
     * @var bool Flag to identify if ProgressBar is already started
     */
    private bool $progressBarStarted = false;

    /**
     * @var array Memory usage between threads
     */
    private array $threads_memory;

    /**
     * @var array Total of items processed per second
     */
    private array $items = [];

    /**
     * @param  string  $uuid
     */
    public function __construct(
        private string $uuid,
    ) {
        $this->openChannels();
        $this->createProgressBar();
    }

    /**
     * Watch for events. This is used only on a multi-threaded environment
     */
    public function listen(): void {
        // notify successful start
        $this->release();

        // connect to Channel of communication
        $progresBarChannel = Channel::open(sprintf('progress-bar@%s', $this->uuid));
        // notify successful start
        $progresBarChannel->send(true);

        // create ProgressBar instance
        $this->progressBar = $createProgressBarInstance();

        // threads memory usage and peak
        $this->threads_memory = [
            'current' => [ $main_memory_usage() ],
            'peak'    => [ memory_get_usage() ],
        ];

        // get next message
        try { while (Type::Close !== $message = $this->input->recv()) {
            // check for close event and exit loop
            if ($message === Type::Close) break;

            switch ($message_class = get_class($message)) {
                case ProgressBarRegistrationMessage::class:
                    $this->registerWorker($message->steps);
                    break;

                case StatsReportMessage::class:
                    // update memory usage for this thread
                    $this->threads_memory['current'][0] = $main_memory_usage();
                    // update peak memory usage
                    if ($this->threads_memory['current'][0] > $this->threads_memory['peak'][0]) {
                        $this->threads_memory['peak'][0] = $this->threads_memory['current'][0];
                    }

                    // save memory usage of thread
                    $this->threads_memory['current'][$message->worker_id] = $message->memory_usage;
                    // update peak memory usage
                    if ($this->threads_memory['current'][$message->worker_id] > ($this->threads_memory['peak'][$message->worker_id] ?? 0)) {
                        $this->threads_memory['peak'][$message->worker_id] = $this->threads_memory['current'][$message->worker_id];
                    }

                    // update ProgressBar memory report
                    $this->progressBar->setMessage($this->getMemoryUsage(), 'threads_memory');
                    break;

                case ProgressBarActionMessage::class:
                    // redirect action to ProgressBar instance
                    $this->progressBar->{$message->action}(...$message->args);
                    if ($message->action === 'advance') {
                        // count processed item
                        $this->items[ time() ] = ($this->items[ time() ] ?? 0) + 1;
                        // update ProgressBar items per second report
                        $this->progressBar->setMessage($this->getItemsPerSecond(), 'items_per_second');
                    }
                    break;

                default:
                    throw new RuntimeException(sprintf('Unsupported message type: %s', $message_class));
            }

        }} catch (Channel\Error\Closed) {
            // TODO channel must not be closed
            $debug = true;
        }

        $this->closeChannels();
    }

    private function registerWorker(int $steps = 0): void {
        // check if ProgressBar isn't already started
        if ( !$this->progressBarStarted) {
            // start Worker ProgressBar
            $this->progressBar->start($steps);
            $this->progressBarStarted = true;

        } else {
            // update steps
            $this->progressBar->setMaxSteps($steps);
        }
    }

    private function getMemoryUsage(): string {
        // main memory used
        $main = Helper::formatMemory($this->threads_memory['current'][0]);
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
        if (empty($this->items)) return '0';

        // keep only last 15s for average
        $this->items = array_slice($this->items, -15, preserve_keys: true);

        // return the average of items processed per second
        return '~'.number_format(floor(array_sum($this->items) / count($this->items) * 100) / 100, 2);
    }

}
