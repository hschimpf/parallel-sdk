<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class NoWorkerDefinedException extends RuntimeException {

    public function __construct() {
        parent::__construct('No worker is defined');
    }

}
