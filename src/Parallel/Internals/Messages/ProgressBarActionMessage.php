<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Messages;

final class ProgressBarActionMessage {

    /**
     * @param  string  $action
     * @param  array  $args
     */
    public function __construct(
        public string $action,
        public array $args,
    ) {}

}
