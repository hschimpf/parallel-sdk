<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class WorkerNotDefinedException extends RuntimeException {

    public function __construct() {
        parent::__construct('Worker is not defined');
    }

}
