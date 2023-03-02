<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Closure;

final class RegisteredWorker {

    public function __construct(
        private string $worker_class,
        private ?Closure $closure = null,
    ) {}

    public function getWorkerClass(): string {
        return $this->worker_class;
    }

    public function getClosure(): ?Closure {
        return $this->closure;
    }

}
