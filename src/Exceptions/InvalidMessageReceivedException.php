<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class InvalidMessageReceivedException extends RuntimeException {

    public function __construct() {
        parent::__construct('Invalid message received!');
    }

}
