<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel;

use HDSSolutions\Console\Parallel\Contracts;

final class Task implements Contracts\Task {

    /**
     * @var int Current Task state
     * @see Contracts\Task::STATES
     */
    private int $state = self::STATE_Pending;

    /**
     * @var mixed Output of the task
     */
    private mixed $output = null;

    /**
     * @param  int  $identifier  Identifier of the Task
     * @param  string  $worker_class  Worker assigned to process this Task
     * @param  int  $worker_id  Identifier of the registered worker
     * @param  mixed  $input  Input of the Task
     */
    public function __construct(
        private readonly int $identifier,
        private readonly string $worker_class,
        private readonly int $worker_id,
        private readonly mixed $input = null,
    ) {}

    public function getIdentifier(): int {
        return $this->identifier;
    }

    public function getWorkerClass(): string {
        return $this->worker_class;
    }

    public function getWorkerId(): int {
        return $this->worker_id;
    }

    /** @inheritdoc */
    public function getData(): mixed {
        return $this->getInput();
    }

    public function getInput(): mixed {
        return $this->input;
    }

    /** @internal */
    public function setState(int $state): self {
        $this->state = $state;

        return $this;
    }

    public function getState(): int {
        return $this->state;
    }

    /** @internal */
    public function setResult(mixed $result): self {
        $this->output = $result;

        return $this;
    }

    /** @inheritdoc */
    public function getResult(): mixed {
        return $this->getOutput();
    }

    public function getOutput(): mixed {
        return $this->output;
    }

    public function isPending(): bool {
        return $this->getState() === self::STATE_Pending;
    }

    public function isBeingProcessed(): bool {
        return $this->getState() === self::STATE_Processing;
    }

    public function wasProcessed(): bool {
        return $this->getState() === self::STATE_Processed;
    }

    public function wasCancelled(): bool {
        return $this->getState() === self::STATE_Cancelled;
    }

}
