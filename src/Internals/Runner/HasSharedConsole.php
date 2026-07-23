<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use Symfony\Component\Console\Output\ConsoleOutput;

trait HasSharedConsole {

    /**
     * @var ConsoleOutput|null Local ConsoleOutput used on non-threaded environments
     */
    private ?ConsoleOutput $consoleOutput = null;

    private function initConsole(): void {
        // on non-threaded environments, just initialize the local ConsoleOutput
        if (! PARALLEL_EXT_LOADED) {
            $this->consoleOutput ??= new ConsoleOutput();
        }
    }

    private function writeOutput(string $message, bool $newline = true): void {
        $this->consoleOutput ??= new ConsoleOutput();
        $this->consoleOutput->getErrorOutput()->write($message, $newline);
    }

}
