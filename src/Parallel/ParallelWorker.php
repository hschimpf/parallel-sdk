<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use Closure;

abstract class ParallelWorker {

    /** @var ?Closure Callback executed when a task is finished */
    private ?Closure $taskFinishedCallback = null;

    abstract protected function processTask(): mixed;

    final public function __invoke(): mixed {
        // redirect params to process task method
        return $this->processTask( ...func_get_args() );
    }

    final public function onTaskFinished(Closure $callback): self {
        // register callback
        $this->taskFinishedCallback = $callback;

        return $this;
    }

    final public function dispatchTaskFinished(...$result): void {
        // pass task result to callback
        ($this->taskFinishedCallback)(...$result);
    }

}
