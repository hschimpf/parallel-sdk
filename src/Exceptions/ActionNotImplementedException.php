<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use RuntimeException;

final class ActionNotImplementedException extends RuntimeException {

    public function __construct(string $action) {
        parent::__construct(sprintf('Action "%s" not yet implemented', $action));
    }

}
