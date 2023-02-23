<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Exception;
use Generator;
use parallel\Channel;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Throwable;
use function parallel\run;

final class Scheduler {

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var array<ParallelWorker> Registered workers */
    private array $workers;

    /** @var ?Channel Channel to wait threads start */
    private ?Channel $__starter = null;

    /** @var array<int, mixed> Collection of pending tasks */
    private array $__pending_tasks = [];

    /** @var array<Future> Collection of running tasks */
    private array $__futures = [];

    /** @var array Collection of results from threads */
    private array $__results = [];

    /** @var ?int Max CPU usage count */
    private ?int $max_cpu_count = null;

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
        self::instance()->__pending_tasks[] = [ $worker_id, $data ];

        do {
            // remove finished tasks
            self::instance()->cleanFinishedTasks();
            // if pending tasks count exceeds available CPU, wait 10ms
            if ($pending_eq_cpu = (count(self::instance()->__pending_tasks) >= self::instance()->getMaxCpuUsage())) usleep(10_000);

        // wait if all CPU are used and pending tasks exceed available CPU
        } while ($pending_eq_cpu && !self::instance()->hasCpuAvailable());

        // start the next available task
        self::instance()->runNextTask();
    }

    /**
     * Returns the result of every processed task
     *
     * @return array | Generator Results of processed tasks
     */
    public static function getThreadsResults(): array | Generator {
        // start all pending tasks, send all available results, until there is no more results available
        while ( !empty(self::instance()->__pending_tasks) || !empty(self::instance()->__futures) || !empty(self::instance()->__results)) {
            // send available results
            while (null !== $result = array_shift(self::instance()->__results))
                // send already processed result
                yield $result;

            // clear finished tasks
            self::instance()->cleanFinishedTasks();

            // start available tasks
            while (self::instance()->hasCpuAvailable() && !empty(self::instance()->__pending_tasks))
                // start the next available task
                self::instance()->runNextTask();

            // check there are tasks running
            if ( !empty(self::instance()->__futures))
                // wait for any task to finish
                while ( empty(array_filter(self::instance()->__futures, static fn(Future $future): bool => $future->done()))) usleep(10_000);
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
        if ($force) array_map(static fn(Future $future): bool => $future->cancel(), self::instance()->__futures);

        // wait for all tasks to finish
        while ( !empty(array_filter(self::instance()->__futures, static fn(Future $future): bool => !$future->done()))) usleep(10_000);

        // close channels
        self::instance()->__starter?->close();
        self::instance()->__starter = null;
    }

    /**
     * Ensures that everything gets closed
     */
    public static function disconnect(): void {
        // check if extension is loaded
        if ( !extension_loaded('parallel')) return;

        // kill all running threads
        while ($task = array_shift(self::instance()->__futures)) try { $task->cancel(); } catch (Exception) {}
        // task start watcher
        try { self::instance()->__starter?->close(); } catch (Channel\Error\Closed) {}
    }

    private function runNextTask(): void {
        // check if there is an available CPU
        if ( !empty($this->__pending_tasks) && $this->hasCpuAvailable()) {
            // get data from pending tasks
            [ $worker_id, $data ] = array_shift($this->__pending_tasks);

            // create starter channel to wait threads start event
            $this->__starter ??= extension_loaded('parallel') ? Channel::make('starter') : null;

            // start task inside a thread (if parallel extension is available)
            $this->__futures[] = ( !extension_loaded('parallel')
                // normal execution (non-threaded)
                ? $this->workers[$worker_id](...$data)

                // parallel available, run process inside a thread
                : run(static function(...$data): mixed {
                    /** @var ParallelWorker $worker */
                    $worker = array_shift($data);

                    // notify that thread started
                    Channel::open('starter')->send(true);

                    // process worker task
                    $result = $worker(...$data);

                    // execute finished event
                    try { $worker->dispatchTaskFinished($result);
                    } catch (Exception) {}

                    // return worker result
                    return $result;
                }, [
                    // worker to process tasks
                    $this->workers[$worker_id],
                    // task data passed to worker
                    ...$data,
                ])
            );

            // wait for thread to start
            $this->__starter?->recv();
        }
    }

    private function getMaxCpuUsage(): int {
        // return configured max CPU usage
        return $this->max_cpu_count ??= (isset($_SERVER['PARALLEL_MAX_COUNT']) ? (int) $_SERVER['PARALLEL_MAX_COUNT'] : cpu_count( (float) ($_SERVER['PARALLEL_MAX_PERCENT'] ?? 1.0) ));
    }

    private function hasCpuAvailable(): bool {
        // return if there is available CPU
        return count($this->__futures) < $this->getMaxCpuUsage();
    }

    private function cleanFinishedTasks(): void {
        // release finished tasks from futures
        $finished = [];
        foreach ($this->__futures as $idx => $future) {
            // check if future is already done working
            if ( !extension_loaded('parallel') || $future->done()) {
                // get result to release thread
                try { $this->__results[] = extension_loaded('parallel') ? $future->value() : $future;
                } catch (Throwable) {}
                // remove future from list
                $finished[] = $idx;
            }
        }

        // remove finished tasks from futures
        foreach ($finished as $idx) unset($this->__futures[$idx]);
    }

    public function __destruct() {
        // ensure that we execute disconnect
        self::disconnect();
    }

}
