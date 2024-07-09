<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests\Workers;

use HDSSolutions\Console\Parallel\ParallelWorker;

final class TestWorker extends ParallelWorker {

    public function __construct(
        private array $multipliers,
    ) {}

    protected function process(int $number = 0): array {
        $microseconds = random_int(10, 100);
        $this->setMessage(sprintf('TestWorker >> Hello from task #%u, I\'ll wait %sms', $number, $microseconds));

        usleep($microseconds * 1000);

        $this->setMessage(sprintf('TestWorker >> I finished waiting %sms from task #%u!', $microseconds, $number));
        $this->advance();

        return [ $number, $number * array_product($this->multipliers) ];
    }

}
