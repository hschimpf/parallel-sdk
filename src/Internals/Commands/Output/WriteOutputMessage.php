<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Output;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

/**
 * Message sent to {@see \HDSSolutions\Console\Parallel\Internals\ProgressBarWorker}
 * to execute {@see \HDSSolutions\Console\Parallel\Internals\ProgressBarWorker::writeOutput()}.
 */
final readonly class WriteOutputMessage extends ParallelCommandMessage {

    /**
     * @param  string  $message  Text to output
     * @param  bool  $newline  Whether to add a trailing newline
     */
    public function __construct(string $message, bool $newline = true) {
        parent::__construct('write_output', [ $message, $newline ]);
    }

}
