<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class WorkerAlreadyDefinedException extends RuntimeException {

    public function __construct(string $worker, bool $with_parameters = false) {
        parent::__construct(sprintf('Worker class "%s" is already defined%s', $worker,
            $with_parameters ? ', you can\'t specify new constructor parameters!' : '',
        ));
    }

}
