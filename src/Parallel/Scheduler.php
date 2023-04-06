<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Generator;
use HDSSolutions\Console\Parallel\Exceptions\ParallelException;
use HDSSolutions\Console\Parallel\Internals\Communication\TwoWayChannel;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Task;
use HDSSolutions\Console\Parallel\Internals\RegisteredWorker;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Throwable;

final class Scheduler {

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var string Unique ID of the instance */
    private string $uuid;

    /** @var Future[] Collection of running tasks */
    private array $futures = [];

    /**
     * @var Future|Runner Instance of the Runner
     */
    private Future|Runner $runner;

    /**
     * Disable public constructor, usage is only available through the singleton instance
     */
    private function __construct() {
        $this->uuid = substr(md5(uniqid(self::class, true)), 0, 16);
        $this->runner = PARALLEL_EXT_LOADED
            // create a Runner instance inside a thread
            ? (new Runtime(PARALLEL_AUTOLOADER))->run(static function($uuid): void {
                // create runner instance
                $runner = new Internals\Runner($uuid);
                // listen for events
                $runner->listen();
            }, [ $this->uuid ])

            // create runner instance for non-threaded environment
            : new Internals\Runner($this->uuid);

        // wait until Runner starts listening for events
        if (PARALLEL_EXT_LOADED) $this->recv();
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
        // check if worker is already registered
        if (is_string($worker) && false !== $registered_worker = self::instance()->getRegisteredWorker($worker)) {
            if ( !empty($args)) {
                // args must not be defined if worker already exists
                throw new RuntimeException(sprintf('Worker "%s" is already defined, you can\'t specify new constructor parameters!', $worker));
            }

            return $registered_worker;
        }

        return self::instance()->registerWorker($worker, $args);
    }

    private function getRegisteredWorker(string $worker): RegisteredWorker | false {
        $message = new Commands\GetRegisteredWorkerMessage($worker);

        if (PARALLEL_EXT_LOADED) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

    private function registerWorker(string | Closure $worker, array $args): RegisteredWorker {
        $message = new Commands\RegisterWorkerMessage($worker, $args);

        if (PARALLEL_EXT_LOADED) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

    /**
     * @var TwoWayChannel|null Communication channel with Runner instance
     */
    private ?TwoWayChannel $runner_channel = null;

    /**
     * @var Channel|null Communication channel to receive Tasks
     */
    private ?Channel $tasks_channel = null;

    private function send(mixed $value): void {
        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class);
            // wait 25ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(25_000); }
        }

        $this->runner_channel->send($value);
    }

    private function recv(bool $from_tasks_channel = false): mixed {
        // open channel if not already opened
        while ($this->runner_channel === null) {
            // open channel to communicate with the Runner instance
            try { $this->runner_channel = TwoWayChannel::open(Runner::class);
            // wait 25ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(25_000); }
        }
        // open channel if not already opened
        while ($this->tasks_channel === null) {
            // open channel to receive the tasks list
            try { $this->tasks_channel = Channel::open(Runner::class.'@'.$this->uuid);
            // wait 25ms if channel does not exist yet and retry
            } catch (Channel\Error\Existence) { usleep(25_000); }
        }

        return $from_tasks_channel ? $this->tasks_channel->recv() : $this->runner_channel->receive();
    }

    /**
     * Schedule worker task for execution in parallel, passing ...$data at execution time
     *
     * @param  mixed  ...$data  Array of arguments to be passed to worker task at execution time
     *
     * @return int Queued Task identifier
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
    public static function runTask(mixed ...$data): int {
        $message = new Commands\QueueTaskMessage($data);

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            // get queued task and check if there was an exception thrown
            if (($task_id = self::instance()->recv()) instanceof ParallelException) {
                // redirect exception
                throw new RuntimeException($task_id->getMessage());
            }

            return $task_id;
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Calling this method will pause execution until all tasks are finished.
     */
    public static function awaitTasksCompletion(): bool {
        $message = new Commands\WaitTasksCompletionMessage();

        if (PARALLEL_EXT_LOADED) {
            $has_pending_tasks = false;
            do {
                self::instance()->send($message);
                if ($has_pending_tasks) {
                    usleep(25_000);
                }
            } while ($has_pending_tasks = self::instance()->recv());

            return true;
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Returns the list of tasks
     *
     * @return Task[] | Generator List of Tasks
     */
    public static function getTasks(): Generator | array {
        $message = new Commands\GetTasksMessage();

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            while (false !== $task = self::instance()->recv(true)) {
                yield $task;
            }

            return;
        }

        yield from self::instance()->runner->processMessage($message);
    }

    /**
     * Remove all registered Tasks.<br/>
     * **IMPORTANT**: This will stop processing Tasks immediately and remove **all** Tasks.
     *
     * @return bool
     */
    public static function removeTasks(): bool {
        $message = new Commands\RemoveTasksMessage();

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Stops all running tasks. If force is set to false, waits gracefully for all running tasks to finish execution
     *
     * @param  bool  $force  Flag to force task cancellation
     */
    public static function stop(bool $force = true): void {
        // if parallel isn't enabled, just finish progress bar and return
        if ( !PARALLEL_EXT_LOADED) {
            // self::instance()->progress->finish();
            return;
        }

        // cancel all running tasks
        if ($force) array_map(static fn(Future $future): bool => $future->cancel(), self::instance()->futures);

        // wait for all tasks to finish
        while ( !empty(array_filter(self::instance()->futures, static fn(Future $future): bool => !$future->done()))) usleep(10_000);
    }

    /**
     * Ensures that everything gets closed
     */
    public function __destruct() {
        // remove all Tasks
        self::removeTasks();

        // check if extension is loaded
        if ( !PARALLEL_EXT_LOADED) return;

        try {
            // stop runner
            self::instance()->send(Event\Type::Close);
            // gracefully join
            self::instance()->recv();

        } catch (Channel\Error\Closed | Throwable) {}
    }

}
