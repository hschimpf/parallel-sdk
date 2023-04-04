<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;
use HDSSolutions\Console\Parallel\Scheduler;

final class RegisteredWorker {

    /**
     * @var bool Flag to identify if this Worker has a ProgressBar
     */
    private bool $with_progress = false;

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
        if (false === $this->with_progress = $with_progress) return;

        // enable ProgressBar thread
        Scheduler::enableProgressBarOnCurrentWorker($steps);
    }

    public function hasProgressEnabled(): bool {
        return $this->with_progress;
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
