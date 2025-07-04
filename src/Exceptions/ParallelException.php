<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Exceptions;

use Throwable;

final readonly class ParallelException {

    private string $message;

    private string $file;

    private int    $line;

    public function __construct(
        Throwable $e,
    ) {
        $this->message = $e->getMessage();
        $this->file = $e->getFile();
        $this->line = $e->getLine();
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function getFile(): string {
        return $this->file;
    }

    public function getLine(): int {
        return $this->line;
    }

}
