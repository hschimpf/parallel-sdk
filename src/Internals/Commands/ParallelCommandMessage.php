<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

abstract readonly class ParallelCommandMessage {

    /**
     * @param  string  $action  Action to execute
     * @param  array  $args  Arguments to pass to the action
     */
    public function __construct(
        public string $action,
        public array $args = [],
    ) {}

}
