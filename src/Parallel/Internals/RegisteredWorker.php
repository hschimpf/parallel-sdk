<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;

final class RegisteredWorker {
    use Worker\CommunicatesWithRunner;

    /**
     * @var bool Flag to identify if this Worker has a ProgressBar
     */
    private bool $with_progress = false;

    private int $steps;

    public function __construct(
        private int $identifier,
        private string $worker_class,
        private ?Closure $closure = null,
        private array $args = [],
    ) {}

    public function getIdentifier(): string {
        if ($this->getClosure() === null) {
            return $this->getWorkerClass();
        }

        return sprintf('%s@%.0u', $this->getWorkerClass(), $this->identifier);
    }

    /**
     * Enables a ProgressBar for the worker
     *
     * @param  bool  $with_progress  Flag to enable/disable the ProgressBar
     */
    public function withProgress(bool $with_progress = true, int $steps = 0): void {
        // check if caller is Runner
        $caller = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
        if (($caller['class'] ?? null) === Runner::class || !PARALLEL_EXT_LOADED) {
            // enable with progress flag
            $this->with_progress = $with_progress;
            $this->steps = $steps;

            return;
        }

        // redirect call to Runner instance
        $this->getRunnerChannel()->send(new Commands\EnableProgressBarMessage($this->getIdentifier(), $steps));
        // wait until Runner updates worker flag
        $this->getRunnerChannel()->receive();
    }

    public function hasProgressEnabled(): bool {
        return $this->with_progress;
    }

    public function getSteps(): int {
        return $this->steps;
    }

    public function getWorkerClass(): string {
        return $this->worker_class;
    }

    public function getClosure(): ?Closure {
        return $this->closure;
    }

    public function getArgs(): array {
        return $this->args;
    }

}
