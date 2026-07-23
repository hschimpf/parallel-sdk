<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Symfony\Component\Console\Output\StreamOutput;

final class ConsoleWorker {
    use ConsoleWorker\HasChannels;
    use Common\ListenEventsAndExecuteActions;

    /**
     * @var StreamOutput Output stream where console messages are written
     */
    private StreamOutput $output;

    public function __construct(
        private readonly string $uuid,
    ) {
        $this->openChannels();
        // use a fresh stderr stream owned by this thread
        $this->output = new StreamOutput(fopen('php://stderr', 'w'));
    }

    public function afterListening(): void {
        $this->closeChannels();
    }

    private function writeOutput(string $message, bool $newline = true): void {
        $this->output->write($message, $newline);
    }

}
