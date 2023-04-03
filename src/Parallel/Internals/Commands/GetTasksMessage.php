<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands;

final class GetTasksMessage extends ParallelCommandMessage {

    public function __construct() {
        parent::__construct('get_tasks');
    }

}
