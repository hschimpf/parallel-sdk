<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Exception;
use Generator;
use HDSSolutions\Console\Parallel\Internals\PendingTask;
use HDSSolutions\Console\Parallel\Internals\RegisteredWorker;
use HDSSolutions\Console\Parallel\Internals\Worker;
use parallel\Channel;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Throwable;
use function parallel\run;

final class Scheduler {

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var string Unique ID of the instance */
    private string $uuid;

    /** @var RegisteredWorker[] Registered workers */
    private array $registered_workers = [];

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

    /**
     * Disable public constructor, usage only available through singleton instance
     */
    private function __construct() {
        $this->uuid = uniqid(self::class, true);
    }

    /**
     * @return self Singleton instance
     */
    private static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Register a worker class to process tasks
     *
     * @param  string | Closure  $worker  Worker class to be used for processing tasks
     * @param  mixed  ...$args  Arguments passed to Worker constructor
     *
     * @return RegisteredWorker
     */
    public static function using(string | Closure $worker, ...$args): RegisteredWorker {
        // convert Closure to ParallelWorker instance
        self::instance()->registered_workers[] = $registered_worker = new RegisteredWorker(
            worker_class: is_string($worker) ? $worker : Worker::class,
            closure:      $worker instanceof Closure ? $worker : null,
            args:         $args,
        );

        return $registered_worker;
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
        if (($worker_id = count(self::instance()->registered_workers) - 1) < 0) {
            // reject task scheduling, no worker is defined
            throw new RuntimeException('No worker is defined');
        }

        // get registered worker
        $registered_worker = self::instance()->registered_workers[$worker_id];
        // register a pending task linked with the registered Worker
        self::instance()->pendingTasks[] = new PendingTask($registered_worker, $data);

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
        // close channels
        self::instance()->starter?->close();
        self::instance()->starter = null;
    }

    /**
     * Ensures that everything gets closed
     */
    public static function disconnect(): void {
        // check if extension is loaded
        if ( !extension_loaded('parallel')) return;
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

            // create starter channel to wait threads start event
            $this->starter ??= extension_loaded('parallel') ? Channel::make(sprintf('starter@%s', $this->uuid)) : null;

            // process task inside a thread (if parallel extension is available)
            if (extension_loaded('parallel')) {
                // parallel available, process task inside a thread
                $this->futures[] = run(static function(string $uuid, PendingTask $pending_task): ProcessedTask {
                    // notify that thread started
                    Channel::open(sprintf('starter@%s', $uuid))->send(true);

                    // get Worker class to instantiate
                    $worker_class = $pending_task->getRegisteredWorker()->getWorkerClass();
                    /** @var ParallelWorker $worker Instance of the Worker */
                    $worker = new $worker_class(...$pending_task->getRegisteredWorker()->getArgs());
                    // build task params
                    $params = $worker instanceof Worker
                        // process task using local Worker
                        ? [ $pending_task->getRegisteredWorker()->getClosure(), ...$pending_task->getData() ]
                        // process task using user Worker
                        : [ ...$pending_task->getData() ];
                    // process task
                    $worker->start(...$params);

                    // return Worker result
                    return $worker->getProcessedTask();
                }, [
                    // send UUID for starter channel
                    $this->uuid,
                    // send pending task to process
                    $pending_task,
                ]);

            } else {
                // get Worker class to instantiate
                $worker_class = $pending_task->getRegisteredWorker()->getWorkerClass();
                /** @var ParallelWorker $worker Instance of the Worker */
                $worker = new $worker_class();
                // process task using worker
                $worker->start(...$pending_task->getData());

                // store Worker result
                $this->futures[] = $worker->getProcessedTask();
            }

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
                // store the ProcessedTask
                try { $this->results[] = extension_loaded('parallel') ? $future->value() : $future;
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
