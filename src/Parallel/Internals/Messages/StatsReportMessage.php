<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Messages;

final class StatsReportMessage {

    public function __construct(
        public string $worker_id,
        public int $memory_usage,
    ) {}

}
