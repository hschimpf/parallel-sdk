<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Contracts;

interface Task {

    /**
     * ## Task has not yet been processed
     */
    public const STATE_Pending = 0;

    /**
     * ## Task processing is starting
     */
    public const STATE_Starting = 1;

    /**
     * ## Task is being currently processed
     */
    public const STATE_Processing = 2;

    /**
     * ## Task has been processed
     */
    public const STATE_Processed = 3;

    /**
     * ## Task processing was cancelled
     */
    public const STATE_Cancelled = 4;

    /**
     * ## Available states of the Task
     */
    public const STATES = [
        self::STATE_Pending,
        self::STATE_Starting,
        self::STATE_Processing,
        self::STATE_Processed,
        self::STATE_Cancelled,
    ];

    /**
     * @return int Identifier of the task
     */
    public function getIdentifier(): int;

    /**
     * @return string Worker class assigned to process this Task
     */
    public function getWorkerClass(): string;

    /**
     * @return int Identifier of the registered worker
     * @internal
     */
    public function getWorkerId(): int;

    /**
     * @deprecated Replaced with {@see self::getInput()}
     */
    public function getData(): mixed;

    /**
     * @return mixed Input sent to the Task
     */
    public function getInput(): mixed;

    /**
     * Returns the current state of the Task
     *
     * @return int Current Task state
     * @see Task::STATES
     */
    public function getState(): int;

    /**
     * @return bool True if the task is pending
     */
    public function isPending(): bool;

    /**
     * @return bool True if the task is being currently processed
     */
    public function isBeingProcessed(): bool;

    /**
     * @return bool True if the Task was processed
     */
    public function wasProcessed(): bool;

    /**
     * @return bool True if the Task was cancelled during processing
     */
    public function wasCancelled(): bool;

    /**
     * @deprecated Replaced with {@see self::getOutput()}
     */
    public function getResult(): mixed;

    /**
     * @return mixed Output of the Task
     */
    public function getOutput(): mixed;

}
