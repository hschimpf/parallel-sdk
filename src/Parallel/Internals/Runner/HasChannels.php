<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use parallel\Channel;

trait HasChannels {

    /**
     * @var Channel Communication channel to receive events
     */
    private Channel $input;

    /**
     * @var Channel Communication channel to gracefully join
     */
    private Channel $output;

    /**
     * @var Channel Communication channel with the eater
     */
    private Channel $eater_link;

    private function openChannels(): void {
        // channels to receive events and join
        $this->input = Channel::make(self::class.'@input');
        $this->output = Channel::make(self::class.'@output');
        // channel to comunicate with eater
        $this->eater_link = Channel::make(self::class.'@eater');
    }

    protected function recv(): mixed {
        return $this->input->recv();
    }

    protected function send(mixed $value, bool $eater = false): void {
        if ($eater) $this->eater_link->send($value);
        else $this->output->send($value);
    }

    protected function release(bool $eater = false): void {
        $this->send(true, $eater);
    }

    private function closeChannels(): void {
        // gracefully join
        $this->output->send(false);
        // close all channels
        $this->input->close();
        $this->output->close();
        $this->eater_link->close();
    }

}
