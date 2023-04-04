<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Communication;

use HDSSolutions\Console\Parallel\Contracts;
use parallel\Channel;

final class TwoWayChannel implements Contracts\TwoWayChannel {

    /**
     * @var Channel Communication channel to receive events
     */
    private Channel $input;

    /**
     * @var Channel Communication channel to send data
     */
    private Channel $output;

    /**
     * Disable constructor
     */
    private function __construct(
        private bool $creator = false,
    ) {}

    /**
     * Shall make an unbuffered two-way channel with the given name<br/>
     * Shall make a buffered two-way channel with the given name and capacity
     *
     * @param  string  $name  The name of the channel
     * @param  int|null  $capacity  May be Channel::Infinite or a positive integer
     *
     * @return self
     * @throws Channel\Error\Existence if channel already exists
     */
    public static function make(string $name, ?int $capacity = null): self {
        $instance = new self(true);
        // create channels
        $instance->input = Channel::make("$name@input", $capacity);
        $instance->output = Channel::make("$name@output", $capacity);

        return $instance;
    }

    /**
     * Shall open the two-way channel with the given name
     *
     * @param  string  $name  The name of the channel
     *
     * @return self
     * @throws Channel\Error\Existence if channel does not exist
     */
    public static function open(string $name): self {
        $instance = new self(false);
        // create channels
        $instance->input = Channel::open("$name@input");
        $instance->output = Channel::open("$name@output");

        return $instance;
    }

    /**
     * @throws Channel\Error\Closed if input channel is closed.
     */
    public function receive(): mixed {
        return $this->input->recv();
    }

    /**
     * @throws Channel\Error\Closed if output channel is closed
     * @throws Channel\Error\IllegalValue if value is illegal
     */
    public function send(mixed $value): mixed {
        if (PARALLEL_EXT_LOADED) {
            $this->output->send($value);
        }

        return $value;
    }

    public function release(): bool {
        return $this->send(true);
    }

    /**
     * Shall close this two-way channel
     *
     * @throws Channel\Error\Closed if channel is closed
     */
    public function close(): void {
        $this->input->close();
        $this->output->close();
    }

}
