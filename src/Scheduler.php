<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use DateInterval;
use Generator;
use HDSSolutions\Console\Parallel\Contracts\Task;
use HDSSolutions\Console\Parallel\Exceptions\ParallelException;
use HDSSolutions\Console\Parallel\Internals\Commands;
use parallel;
use parallel\Channel;
use parallel\Events\Event;
use parallel\Runtime;
use RuntimeException;
use Throwable;

final class Scheduler {
    use Internals\Scheduler\HasChannels;
    use Internals\Scheduler\HasRunner;

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var string Unique ID of the instance */
    private string $uuid;

    /**
     * Disable public constructor, usage is only available through the singleton instance
     */
    private function __construct() {
        $this->uuid = substr(md5(uniqid(self::class, true)), 0, 16);
        $this->runner = PARALLEL_EXT_LOADED
            // create a Runner instance inside a thread
            ? parallel\run(static function($uuid): void {
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
     * Sets the maximum number of CPU cores to use in parallel
     *
     * @param  int  $count  No. of CPU cores. (minimum: 1)
     *
     * @return int No. of assigned CPU cores
     */
    public static function setMaxCpuCountUsage(int $count): int {
        $message = new Commands\Runner\SetMaxCpuUsage(max(1, $count));

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Sets the maximum percentage of cpu cores to use in parallel
     *
     * @param  float  $percentage  Percentage of CPU cores. (between 0 and 1.0)
     *
     * @return int No. of assigned CPU cores
     */
    public static function setMaxCpuPercentageUsage(float $percentage): int {
        $message = new Commands\Runner\SetMaxCpuUsage(max(0, min($percentage, 1.0)), percentage: true);

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
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
        $message = new Commands\Runner\QueueTaskMessage($data);

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
     *
     * @param  DateInterval|null  $wait_until  Should wait until specified DateInterval or until all tasks finished.
     */
    public static function awaitTasksCompletion(DateInterval $wait_until = null): bool {
        $message = new Commands\Runner\WaitTasksCompletionMessage($wait_until);

        if (PARALLEL_EXT_LOADED) {
            $should_keep_waiting = false;
            do {
                self::instance()->send($message);
                if ($should_keep_waiting) {
                    usleep(25_000);
                }
            } while ($should_keep_waiting = self::instance()->recv());

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
        $message = new Commands\Runner\GetTasksMessage();

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
     * Remove a Task from the processing queue.<br/>
     * **IMPORTANT**: The task will be stopped immediately if it is currently being processed.
     *
     * @param  Task  $task Task to be removed
     *
     * @return bool
     */
    public static function removeTask(Task $task): bool {
        $message = new Commands\Runner\RemoveTaskMessage($task->getIdentifier());

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Remove all pending tasks from the processing queue.<br>
     * Tasks that weren't processed will remain in the {@see Task::STATE_Pending} state
     */
    public static function removePendingTasks(): bool {
        $message = new Commands\Runner\RemovePendingTasksMessage();

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Remove all registered Tasks.<br/>
     * **IMPORTANT**: This will stop processing Tasks immediately and remove **all** Tasks.
     *
     * @return bool
     */
    public static function removeAllTasks(): bool {
        $message = new Commands\Runner\RemoveAllTasksMessage();

        if (PARALLEL_EXT_LOADED) {
            self::instance()->send($message);

            return self::instance()->recv();
        }

        return self::instance()->runner->processMessage($message);
    }

    /**
     * Stops all running tasks.<br>
     * If force is set to false, waits gracefully for all running tasks to finish execution.<br>
     * Tasks that weren't processed will remain in the {@see Task::STATE_Pending} state.<br>
     * Tasks that were currently processing, will have the {@see Task::STATE_Cancelled} state.
     *
     * @param  bool  $force  Flag to force task cancellation
     */
    public static function stop(bool $force = true): void {
        // check if extension isn't loaded and just return
        if ( !PARALLEL_EXT_LOADED) return;

        self::removePendingTasks();
        if ($force) {
            self::instance()->send(new Commands\Runner\StopRunningTasksMessage());
            self::instance()->recv();
        }

        self::awaitTasksCompletion();
    }

    /**
     * Ensures that everything gets closed
     */
    public function __destruct() {
        // remove all Tasks
        self::removeAllTasks();

        // check if extension isn't loaded and just return
        if ( !PARALLEL_EXT_LOADED) return;

        try {
            // stop Runner instance
            self::instance()->send(Event\Type::Close);
            // wait until Runner instance shutdowns
            self::instance()->recv();

        } catch (Channel\Error\Closed | Throwable) {}
    }

}
