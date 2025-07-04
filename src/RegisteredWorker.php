<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Commands;

final class RegisteredWorker {
    use Internals\Worker\CommunicatesWithRunner;

    /**
     * @var bool Flag to identify if this Worker has a ProgressBar
     */
    private bool $with_progress = false;

    private int $steps;

    public function __construct(
        private readonly string $uuid,
        private readonly int $identifier,
        private readonly string $worker_class,
        private readonly ?Closure $closure = null,
        private readonly array $args = [],
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
        // enable with progress flag
        $this->with_progress = $with_progress;

        // check if caller is Runner
        $caller = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? null;
        if (($caller['class'] ?? null) === Internals\Runner::class || !PARALLEL_EXT_LOADED) {
            $this->steps = $steps;

            return;
        }

        // redirect call to Runner instance
        $this->getRunnerChannel()->send(new Commands\ProgressBar\EnableProgressBarMessage($this->getIdentifier(), $steps));
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
