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
     * @var mixed Result of the task
     */
    private mixed $result = null;

    /**
     * @param  int  $identifier  Identifier of the Task
     * @param  string  $worker_class  Worker assigned to process this Task
     * @param  int  $worker_id  Identifier of the registered worker
     * @param  mixed  $data  Data of the Task
     */
    public function __construct(
        private int $identifier,
        private string $worker_class,
        private int $worker_id,
        private mixed $data = null,
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

    public function getData(): mixed {
        return $this->data;
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
        $this->result = $result;

        return $this;
    }

    public function getResult(): mixed {
        return $this->result;
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

}
