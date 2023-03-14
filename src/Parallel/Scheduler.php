<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Exception;
use Generator;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarRegistrationMessage;
use HDSSolutions\Console\Parallel\Internals\PendingTask;
use HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;
use HDSSolutions\Console\Parallel\Internals\RegisteredWorker;
use HDSSolutions\Console\Parallel\Internals\Worker;
use parallel\Channel;
use parallel\Events\Event\Type;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
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
     * @var ProgressBar|null ProgressBar instance for non-threaded Tasks execution
     */
    private ?ProgressBar $progressBar = null;

    /**
     * @var bool Flag to identify if ProgressBar is already started (non-threaded)
     */
    private bool $progressBarStarted = false;

    /**
     * @var array Memory usage stats (non-threaded)
     */
    private array $memory_stats = [ 'current' => 0, 'peak' => 0 ];

    /**
     * @var array Total of items processed per second (non-threaded)
     */
    private array $items = [];

    /**
     * @var Future|null Thread controlling the ProgressBar
     */
    private ?Future $progressBarThread = null;

    /**
     * @var Channel|null Channel of communication between ProgressBar and Tasks
     */
    private ?Channel $progressBarChannel = null;

    /**
     * Disable public constructor, usage only available through singleton instance
     */
    private function __construct() {
        $this->uuid = substr(md5(uniqid(self::class, true)), 0, 16);
    }

    /**
     * @return self Singleton instance
     */
    private static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function registerWorkerWithProgressBar(RegisteredWorker $registered_worker, int $steps = 0): void {
        self::instance()->initProgressBar();

        if ( !extension_loaded('parallel')) {
            // check if ProgressBar isn't already started
            if ( !self::instance()->progressBarStarted) {
                // start ProgressBar
                self::instance()->progressBar->start($steps);
                self::instance()->progressBarStarted = true;

            } else {
                // update steps
                self::instance()->progressBar->setMaxSteps($steps);
            }
        }

        // register Worker ProgressBar
        self::instance()->progressBarChannel?->send(new ProgressBarRegistrationMessage(
            worker: $registered_worker->getWorkerClass(),
            steps:  $steps,
        ));
    }

    private function initProgressBar(): void {
        // init ProgressBar only if not already working
        if ($this->progressBar !== null || $this->progressBarThread !== null) return;

        // start a normal ProgressBar if parallel isn't available (non-threaded)
        if ( !extension_loaded('parallel')) {
            // create a non-threaded ProgressBar instance
            $this->progressBar = $this->createProgressBarInstance();
            return;
        }

        // create a channel of communication between ProgressBar and Tasks
        $this->progressBarChannel = Channel::make(sprintf('progress-bar@%s', $this->uuid));

        // main thread memory reporter
        // FIXME this closure is copied and runs inside a thread, so memory report isn't accurate
        $main_memory_usage = static fn() => memory_get_usage();

        // decouple progress bar to a separated thread
        $this->progressBarThread = run(static function(string $uuid, Closure $createProgressBarInstance, Closure $main_memory_usage): void {
            // create ProgressBar worker instance
            $progressBarWorker = new ProgressBarWorker($uuid);
            // start ProgressBar
            $progressBarWorker->start($createProgressBarInstance, $main_memory_usage);

        }, [
            // send UUID for starter channel
            $this->uuid,
            // send ProgressBar creator
            fn() => $this->createProgressBarInstance(),
            // send main memory usage reporter
            $main_memory_usage,
        ]);

        // wait for ProgressBar thread to start
        if ($this->progressBarChannel->recv() !== true) {
            throw new RuntimeException('Failed to start ProgressBar');
        }
    }

    private function createProgressBarInstance(): ProgressBar {
        $progressBar = new ProgressBar(new ConsoleOutput());

        // configure ProgressBar settings
        $progressBar->setBarWidth( 80 );
        $progressBar->setRedrawFrequency( 100 );
        $progressBar->minSecondsBetweenRedraws( 0.1 );
        $progressBar->maxSecondsBetweenRedraws( 0.2 );
        $progressBar->setFormat(" %current% of %max%: %message%\n".
                             " [%bar%] %percent:3s%%\n".
                             " elapsed: %elapsed:6s%, remaining: %remaining:-6s%, %items_per_second% items/s".(extension_loaded('parallel') ? "\n" : ',').
                             " memory: %threads_memory%\n");
        // set initial values
        $progressBar->setMessage('Starting...');
        $progressBar->setMessage('??', 'items_per_second');
        $progressBar->setMessage('??', 'threads_memory');

        return $progressBar;
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
            identifier:   count(self::instance()->registered_workers),
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
                    // get registered worker
                    $registered_worker = $pending_task->getRegisteredWorker();
                    // get Worker class to instantiate
                    $worker_class = $registered_worker->getWorkerClass();

                    /** @var ParallelWorker $worker Instance of the Worker */
                    $worker = new $worker_class(...$registered_worker->getArgs());
                    // build task params
                    $params = $worker instanceof Worker
                        // process task using local Worker
                        ? [ $registered_worker->getClosure(), ...$pending_task->getData() ]
                        // process task using user Worker
                        : [ ...$pending_task->getData() ];

                    // check if worker has ProgressBar enabled
                    if ($registered_worker->hasProgressEnabled()) {
                        // connect worker to ProgressBar
                        $worker->connectProgressBar($uuid, $GLOBALS['worker_thread_id'] ??= sprintf('%s@%s', $uuid, substr(md5(uniqid($worker_class, true)), 0, 16)));
                    }

                    // notify that thread started
                    Channel::open(sprintf('starter@%s', $uuid))->send(true);

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
                // get registered worker
                $registered_worker = $pending_task->getRegisteredWorker();
                // get Worker class to instantiate
                $worker_class = $registered_worker->getWorkerClass();

                /** @var ParallelWorker $worker Instance of the Worker */
                $worker = new $worker_class(...$registered_worker->getArgs());
                // build task params
                $params = $worker instanceof Worker
                    // process task using local Worker
                    ? [ $registered_worker->getClosure(), ...$pending_task->getData() ]
                    // process task using user Worker
                    : [ ...$pending_task->getData() ];

                // check if worker has ProgressBar enabled
                if ($registered_worker->hasProgressEnabled()) {
                    // connect worker to ProgressBar
                    $worker->connectProgressBar(function(string $action, array $args) {
                        // update stats
                        if ($action === 'advance') {
                            // count processed item
                            $this->items[ time() ] = ($this->items[ time() ] ?? 0) + 1;
                        }
                        // update ProgressBar memory usage report
                        $this->progressBar->setMessage($this->getMemoryUsage(), 'threads_memory');
                        // update ProgressBar items per second report
                        $this->progressBar->setMessage($this->getItemsPerSecond(), 'items_per_second');

                        // execute progress bar action
                        $this->progressBar->$action(...$args);
                    });
                }

                // process task using worker
                $worker->start(...$params);

                // store Worker result
                $this->futures[] = $worker->getProcessedTask();
            }

            // wait for thread to start
            if (($this->starter?->recv() ?? true) !== true) {
                throw new RuntimeException('Failed to start Task');
            }
        }
    }

    private function getMemoryUsage(): string {
        // update memory usage for this thread
        $this->memory_stats['current'] = memory_get_usage(true);
        // update peak memory usage
        if ($this->memory_stats['current'] > $this->memory_stats['peak']) {
            $this->memory_stats['peak'] = $this->memory_stats['current'];
        }

        // current memory used
        $main = Helper::formatMemory($this->memory_stats['current']);
        // peak memory usage
        $peak = Helper::formatMemory($this->memory_stats['peak']);

        return "$main, ↑ $peak";
    }

    private function getItemsPerSecond(): string {
        // check for empty list
        if (empty($this->items)) return '0';

        // keep only last 15s for average
        $this->items = array_slice($this->items, -15, preserve_keys: true);

        // return the average of items processed per second
        return '~'.number_format(floor(array_sum($this->items) / count($this->items) * 100) / 100, 2);
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

    /**
     * Ensures that everything gets closed
     */
    public static function disconnect(): void {
        // check if extension is loaded
        if ( !extension_loaded('parallel')) return;

        try {
            // send message to ProgressBar thread to stop execution
            self::instance()->progressBarChannel?->send(Type::Close);
            // wait progress thread to finish
            self::instance()->progressBarThread?->value();
            // close ProgressBar communication channel
            self::instance()->progressBarChannel?->close();

            self::instance()->progressBarChannel = null;
            self::instance()->progressBarThread = null;

        } catch (Channel\Error\Closed | Throwable) {}

        // kill all running threads
        while ($task = array_shift(self::instance()->futures)) try { $task->cancel(); } catch (Exception) {}

        try {
            // task start watcher
            self::instance()->starter?->close();
            self::instance()->starter = null;

        } catch (Channel\Error\Closed) {}
    }

    public function __destruct() {
        // ensure that we execute disconnect
        self::disconnect();
    }

}
