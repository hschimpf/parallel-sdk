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
        private readonly string $name,
        private readonly bool $creator = false,
    ) {}

    /**
     * Shall make an unbuffered two-way channel with the given name<br/>
     * Shall make a buffered two-way channel with the given name and capacity
     *
     * @param  string  $name  The name of the channel
     *
     * @return self
     * @throws Channel\Error\Existence if channel already exists
     */
    public static function make(string $name): self {
        $instance = new self($name, true);
        // create channels
        $instance->input = Channel::make("$name@input");
        $instance->output = Channel::make("$name@output");

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
        $instance = new self($name, false);
        // create channels
        $instance->input = Channel::open("$name@output");
        $instance->output = Channel::open("$name@input");

        return $instance;
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

    /**
     * @throws Channel\Error\Closed if input channel is closed.
     */
    public function receive(): mixed {
        return $this->input->recv();
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

    public function release(): bool {
        return $this->send(true);
    }

}
