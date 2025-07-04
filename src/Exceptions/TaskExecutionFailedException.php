<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class TaskExecutionFailedException extends RuntimeException {

    public function __construct(ParallelException $exception) {
        parent::__construct($exception->getMessage());
    }

}
