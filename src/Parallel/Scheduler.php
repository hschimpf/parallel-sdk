<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Exception;
use Generator;
use parallel\Channel;
use parallel\Events\Event\Type;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Throwable;
use function parallel\run;

final class Scheduler {

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var ParallelWorker[] Registered workers */
    private array $workers;

    /** @var ?Channel Channel to wait threads start */
    private ?Channel $starter = null;

    /** @var PendingTask[] Collection of pending tasks */
    private array $pendingTasks = [];

    /** @var Future[] Collection of running tasks */
    private array $futures = [];

    /** @var array Collection of results from threads */
    private array $results = [];

    /** @var ?int Max CPU usage count */
    private ?int $max_cpu_count = null;

    /** @var ?Future Thread controlling the progress bar */
    private ?Future $progressBarThread = null;

    /** @var ?Channel Channel of communication between threads and progress bar */
    private ?Channel $progressBarChannel = null;

    /**
     * Disable public constructor, usage only available through singleton instance
     */
    private function __construct() {}

    /**
     * @return self Singleton instance
     */
    private static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Register the worker to process tasks
     *
     * @param  Closure|ParallelWorker  $worker  Worker to process tasks
     *
     * @return ParallelWorker
     */
    public static function with(Closure | ParallelWorker $worker): ParallelWorker {
        // convert Closure to ParallelWorker instance
        self::instance()->workers[] = $worker instanceof ParallelWorker ? $worker : new Worker($worker);

        return $worker;
    }

    /**
     * Schedule worker task for execution in parallel, passing ...$data at execution time
     *
     * @param  mixed  ...$data  Array of arguments to be passed to worker task at execution time
     *
     * @throws Runtime\Error\Closed if \parallel\Runtime was closed
     * @throws Runtime\Error\IllegalFunction if task is a closure created from an internal function
     * @throws Runtime\Error\IllegalInstruction if task contains illegal instructions
     * @throws Runtime\Error\IllegalParameter if task accepts or argv contains illegal variables
     * @throws Runtime\Error\IllegalReturn | Throwable if task returns illegally
     *
     * @see  Runtime::run() for more details
     * @link https://www.php.net/manual/en/parallel.run
     */
    public static function runTask(mixed ...$data): void {
        // check if a worker was defined
        if (($worker_id = count(self::instance()->workers) - 1) < 0) {
            // reject task scheduling, no worker is defined
            throw new RuntimeException('No worker is defined');
        }

        // save data to pending tasks
        self::instance()->pendingTasks[] = new PendingTask($worker_id, $data);

        do {
            // remove finished tasks
            self::instance()->cleanFinishedTasks();
            // if pending tasks count exceeds available CPU, wait 10ms
            if ($pending_eq_cpu = (count(self::instance()->pendingTasks) >= self::instance()->getMaxCpuUsage())) usleep(10_000);

        // wait if all CPU are used and pending tasks exceed available CPU
        } while ($pending_eq_cpu && !self::instance()->hasCpuAvailable());

        // start the next available task
        self::instance()->runNextTask();
    }

    /**
     * Returns the result of every processed task
     *
     * @return ProcessedTask[] | Generator Results of processed tasks
     */
    public static function getProcessedTasks(): Generator | array {
        // start all pending tasks, send all available results, until there is no more results available
        while ( !empty(self::instance()->pendingTasks) || !empty(self::instance()->futures) || !empty(self::instance()->results)) {
            // send available results
            while (null !== $result = array_shift(self::instance()->results))
                // send already processed result
                yield $result;

            // clear finished tasks
            self::instance()->cleanFinishedTasks();

            // start available tasks
            while (self::instance()->hasCpuAvailable() && !empty(self::instance()->pendingTasks))
                // start the next available task
                self::instance()->runNextTask();

            // check there are tasks running
            if ( !empty(self::instance()->futures))
                // wait for any task to finish
                while ( empty(array_filter(self::instance()->futures, static fn(Future $future): bool => $future->done()))) usleep(10_000);
        }
    }

    /**
     * Stops all running tasks. If force is set to false, waits gracefully for all running tasks to finish execution
     *
     * @param  bool  $force  Flag to force task cancellation
     */
    public static function stop(bool $force = true): void {
        // if parallel isn't enabled, just finish progress bar and return
        if ( !extension_loaded('parallel')) {
            // self::instance()->progress->finish();
            return;
        }

        // cancel all running tasks
        if ($force) array_map(static fn(Future $future): bool => $future->cancel(), self::instance()->futures);

        // wait for all tasks to finish
        while ( !empty(array_filter(self::instance()->futures, static fn(Future $future): bool => !$future->done()))) usleep(10_000);

        // send message to channel to stop execution
        self::instance()->progressBarChannel->send(Type::Close);

        // wait progress thread to finish
        while ( !self::instance()->progressBarThread->done()) usleep(10_000);
        self::instance()->progressBarThread = null;

        // close channels
        self::instance()->starter?->close();
        self::instance()->starter = null;
        self::instance()->progressBarChannel?->close();
        self::instance()->progressBarChannel = null;
    }

    /**
     * Ensures that everything gets closed
     */
    public static function disconnect(): void {
        // check if extension is loaded
        if ( !extension_loaded('parallel')) return;

        // stop progress bar thread and close channel
        try { self::instance()->progressBarThread?->cancel(); } catch (Exception) {}
        try { self::instance()->progressBarChannel?->close(); } catch (Channel\Error\Closed) {}

        // kill all running threads
        while ($task = array_shift(self::instance()->futures)) try { $task->cancel(); } catch (Exception) {}
        // task start watcher
        try { self::instance()->starter?->close(); } catch (Channel\Error\Closed) {}
    }

    private function runNextTask(): void {
        // check if there is an available CPU
        if ( !empty($this->pendingTasks) && $this->hasCpuAvailable()) {
            // get next available pending task
            $pending_task = array_shift($this->pendingTasks);
            // get worker ID from pending task
            $worker_id = $pending_task->getWorkerId();

            // create starter channel to wait threads start event
            $this->starter ??= extension_loaded('parallel') ? Channel::make('starter') : null;

            // process task inside a thread (if parallel extension is available)
            $this->futures[] = ( !extension_loaded('parallel')
                // normal execution (non-threaded)
                ? [ $worker_id, $this->workers[$worker_id](...$pending_task->getData()) ]

                // parallel available, process task inside a thread
                : run(static function(...$data): array {
                    /** @var int $worker_id */
                    $worker_id = array_shift($data);
                    /** @var ParallelWorker $worker */
                    $worker = array_shift($data);

                    // notify that thread started
                    Channel::open('starter')->send(true);

                    // process task using specified worker
                    $result = $worker(...$data);

                    // execute finished event
                    try { $worker->dispatchTaskFinished($result);
                    } catch (Exception) {}

                    // return worker ID and result
                    return [ $worker_id, $result ];
                }, [
                    // send worker ID for returning value
                    $worker_id,
                    // worker to process task
                    $this->workers[$worker_id],
                    // task data to pass to the worker
                    ...$pending_task->getData(),
                ])
            );

            // wait for thread to start
            $this->starter?->recv();
        }
    }

    private function getMaxCpuUsage(): int {
        // return configured max CPU usage
        return $this->max_cpu_count ??= (isset($_SERVER['PARALLEL_MAX_COUNT']) ? (int) $_SERVER['PARALLEL_MAX_COUNT'] : cpu_count( (float) ($_SERVER['PARALLEL_MAX_PERCENT'] ?? 1.0) ));
    }

    private function hasCpuAvailable(): bool {
        // return if there is available CPU
        return count($this->futures) < $this->getMaxCpuUsage();
    }

    private function cleanFinishedTasks(): void {
        // release finished tasks from futures
        $finished_tasks = [];
        foreach ($this->futures as $idx => $future) {
            // check if future is already done working
            if ( !extension_loaded('parallel') || $future->done()) {
                try {
                    // get result to release thread
                    $result = extension_loaded('parallel') ? $future->value() : $future;
                    // get worker identifier
                    $worker_id = array_shift($result);
                    // get process result
                    $result = array_shift($result);
                    // store Task result
                    $this->results[] = new ProcessedTask($this->workers[$worker_id], $result);
                } catch (Throwable) {}
                // add future idx to finished tasks list
                $finished_tasks[] = $idx;
            }
        }

        // remove finished tasks from futures
        foreach ($finished_tasks as $idx) unset($this->futures[$idx]);
    }

    public function __destruct() {
        // ensure that we execute disconnect
        self::disconnect();
    }

}
