<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Runner;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

/**
 * Message sent to {@see Runner} to execute {@see Runner::setMaxCpuCountUsage()} | {@see Runner::setMaxCpuPercentageUsage()} action
 */
final class SetMaxCpuUsage extends ParallelCommandMessage {

    public function __construct(int | float $max, bool $percentage = false) {
        parent::__construct(sprintf('set_max_cpu_%s_usage', $percentage ? 'percentage' : 'count'), [ $max ]);
    }

}
