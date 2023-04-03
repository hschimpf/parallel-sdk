<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use Exception;
use Generator;
use HDSSolutions\Console\Parallel\Exceptions\ParallelException;
use HDSSolutions\Console\Parallel\Internals\Messages\ProgressBarRegistrationMessage;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\Internals\Task;
use HDSSolutions\Console\Parallel\Internals\ProgressBarWorker;
use HDSSolutions\Console\Parallel\Internals\RegisteredWorker;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Channel;
use parallel\Events\Event\Type;
use parallel\Future;
use parallel\Runtime;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;
use function parallel\run;

final class Scheduler {

    /** @var Scheduler Singleton instance */
    private static self $instance;

    /** @var string Unique ID of the instance */
    private string $uuid;

    /** @var ?Channel Channel to wait threads start */
    private ?Channel $starter = null;

    /** @var Future[] Collection of running tasks */
    private array $futures = [];

    /**
     * @var ProgressBar|null ProgressBar instance for non-threaded Tasks execution
     */
    private ?ProgressBar $progressBar = null;

    /**
     * @var bool Flag to identify if ProgressBar is already started (non-threaded)
     */
    private bool $progressBarStarted = false;

    /**
     * @var Future|null Thread controlling the ProgressBar
     */
    private ?Future $progressBarThread = null;

    /**
     * @var Channel|null Channel of communication between ProgressBar and Tasks
     */
    private ?Channel $progressBarChannel = null;

    /**
     * @var Future|Runner Instance of the Runner
     */
    private Future|Runner $runner;

    /**
     * Disable public constructor, usage only available through singleton instance
     */
    private function __construct() {
        $this->uuid = substr(md5(uniqid(self::class, true)), 0, 16);
        $this->runner = extension_loaded('parallel')
            // create a runner inside a thread
            ? (new Runtime(PARALLEL_AUTOLOADER))->run(static function($uuid): void {
                // create runner instance
                $runner = new Internals\Runner($uuid);
                // watch for events
                $runner->watch();
            }, [ $this->uuid ])

            // create runner instance for non-threaded environment
            : new Internals\Runner($this->uuid);
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

        if (extension_loaded('parallel')) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

    private function registerWorker(string | Closure $worker, array $args): RegisteredWorker {
        $message = new Commands\RegisterWorkerMessage($worker, $args);

        if (extension_loaded('parallel')) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

    private ?Channel $input = null;
    private ?Channel $output = null;

    private function send(mixed $value): void {
        // open channel if not already opened
        while ($this->input === null) {
            // try to open input channel
            try { $this->input = Channel::open(Runner::class.'@input');
            // wait 10ms on failure
            } catch (Channel\Error\Existence) { usleep(10_000); }
        }

        $this->input->send($value);
    }

    private function recv(): mixed {
        // open channel if not already opened
        while ($this->output === null) {
            // try to open output channel
            try { $this->output = Channel::open(Runner::class.'@output');
            // wait 10ms on failure
            } catch (Channel\Error\Existence) { usleep(10_000); }
        }

        return $this->output->recv();
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

        if (extension_loaded('parallel')) {
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
     * Returns the result of every processed task
     *
     * @return Task[] | Generator Results of processed tasks
     * @deprecated Use {@see Scheduler::getTasks()} instead
     */
    public static function getProcessedTasks(): Generator | array {
        yield from self::getTasks();
    }

    /**
     * Returns the list of tasks
     *
     * @return Task[] | Generator List of Tasks
     */
    public static function getTasks(): Generator | array {
        $message = new Commands\GetTasksMessage();

        if (extension_loaded('parallel')) {
            self::instance()->send($message);

            while (false !== $task = self::instance()->recv()) {
                yield $task;
            }
        }

        while (false !== $task = self::instance()->runner->processMessage($message)) {
            yield $task;
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

    /**
     * Ensures that everything gets closed
     */
    public static function disconnect(): void {
        // check if extension is loaded
        if ( !extension_loaded('parallel')) return;

        try {
            // stop runner
            self::instance()->runner->cancel();

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
