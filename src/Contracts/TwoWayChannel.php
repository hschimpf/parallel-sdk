<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Contracts;

interface TwoWayChannel {

    /**
     * Shall receive a value from input channel
     *
     * @return mixed
     */
    public function receive(): mixed;

    /**
     * Shall send the given value to output channel
     *
     * @param  mixed  $value  Value to send
     *
     * @return mixed
     */
    public function send(mixed $value): mixed;

    /**
     * Shall send true as value to output channel
     *
     * @return bool
     */
    public function release(): bool;

    public function close(): void;

}
