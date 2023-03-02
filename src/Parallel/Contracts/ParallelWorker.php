<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Contracts;

use HDSSolutions\Console\Parallel\ProcessedTask;

interface ParallelWorker {

    /**
     * ## Worker has not yet started
     */
    public const STATE_New = 0;

    /**
     * ## Worker is currently running
     */
    public const STATE_Running = 1;

    /**
     * ## Worker has finished execution
     */
    public const STATE_Finished = 2;

    /**
     * ## Available states of the Worker
     */
    public const STATES = [
        self::STATE_New,
        self::STATE_Running,
        self::STATE_Finished,
    ];

    /**
     * Returns the current state of the Worker
     *
     * @return int Current Worker state
     * @see ParallelWorker::STATES
     */
    public function getState(): int;

    /**
     * Begin execution of this Worker, calling the `process()` method
     *
     * @param  mixed  ...$args  Task data to pass to the Worker
     */
    public function start(...$args): void;

    /**
     * Returns the processed task
     *
     * @return ProcessedTask Processed task
     */
    public function getProcessedTask(): ProcessedTask;

}
