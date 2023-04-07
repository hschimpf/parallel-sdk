<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

use RuntimeException;

abstract class ParallelCommandMessage {

    /**
     * @param  string  $action  Action to execute
     * @param  array  $args  Arguments to pass to the action
     */
    public function __construct(
        private string $action,
        private array $args = [],
    ) {}

    public function __get(string $name) {
        if ( !property_exists($this, $name)) {
            throw new RuntimeException(sprintf('Invalid property "%s"', $name));
        }

        return $this->$name;
    }

}
