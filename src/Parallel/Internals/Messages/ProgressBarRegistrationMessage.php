<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Messages;

final class ProgressBarRegistrationMessage {

    /**
     * @param  string  $worker
     * @param  int  $steps
     */
    public function __construct(
        public string $worker,
        public int $steps = 0,
    ) {}

}
