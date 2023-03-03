<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class TestWorker extends ParallelWorker {

    public function __construct(
        private array $multipliers,
    ) {}

    protected function process(int $number = 0): array {
        $microseconds = random_int(100, 500);
        echo sprintf("TestWorker >> Hello from task #%u, I'll wait %sms\n", $number, $microseconds);
        usleep($microseconds * 1000);

        return [ $number, $number * array_product($this->multipliers) ];
    }

}
