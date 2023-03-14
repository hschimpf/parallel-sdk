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
     * Associates a text with a named placeholder.
     *
     * @param  string  $message  The text to associate with the placeholder
     * @param  string  $name  The name of the placeholder
     */
    public function setMessage(string $message, string $name = 'message'): void;

    /**
     * Advances the progress output X steps.
     *
     * @param  int  $steps  Number of steps to advance
     */
    public function advance(int $steps = 1): void;

    /**
     * Moves the progress output to a specific step.
     *
     * @param  int  $step  Step to move progress to
     */
    public function setProgress(int $step): void;

    /**
     * Outputs the current progress string.
     */
    public function display(): void;

    /**
     * Removes the progress bar from the current line.
     *
     * This is useful if you wish to write some output
     * while a progress bar is running.
     * Call display() to show the progress bar again.
     */
    public function clear(): void;

    /**
     * @return ?float Time when Worker started processing the Task, null if Worker didn't start yet
     */
    public function getStartedAt(): ?float;

    /**
     * @return ?float Time when Worker finished processing the Task, null if Worker didn't finish yet
     */
    public function getFinishedAt(): ?float;

    /**
     * Returns the processed task
     *
     * @return ProcessedTask Processed task
     */
    public function getProcessedTask(): ProcessedTask;

}
