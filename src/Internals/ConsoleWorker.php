<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleWorker {
    use ConsoleWorker\HasChannels;
    use Common\ListenEventsAndExecuteActions;

    /**
     * @var OutputInterface Output stream used for console messages
     */
    private OutputInterface $output;

    public function __construct(
        private readonly string $uuid,
    ) {
        $this->openChannels();
        $this->output = (new ConsoleOutput)->getErrorOutput();
    }

    public function afterListening(): void {
        $this->closeChannels();
    }

    private function writeOutput(string $message, bool $newline = true): void {
        $this->output->write($message, $newline);
    }

}
