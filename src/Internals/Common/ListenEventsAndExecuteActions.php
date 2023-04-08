<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Common;

use HDSSolutions\Console\Parallel\Exceptions\ParallelException;
use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use RuntimeException;
use parallel\Channel;
use parallel\Events\Event;
use Throwable;

trait ListenEventsAndExecuteActions {

    /**
     * Watch for events. This is used only on a multi-threaded environment
     */
    final public function listen(): void {
        // notify successful start
        $this->release();

        // read messages
        try { while (Event\Type::Close !== $message = $this->recv()) {
            try {
                // check if we got a valid message
                if ( !($message instanceof ParallelCommandMessage)) {
                    throw new RuntimeException('Invalid message received!');
                }

                // process message
                $this->processMessage($message);

            } catch (Throwable $e) {
                // redirect exception to caller using output channel
                $this->send(new ParallelException($e));
            }

        }} catch (Channel\Error\Closed) {}

        $this->afterListening();
    }

    abstract protected function afterListening(): void;

    /**
     * @param  ParallelCommandMessage  $message
     *
     * @return mixed
     * @throws RuntimeException If the requested action isn't implemented
     */
    final public function processMessage(ParallelCommandMessage $message): mixed {
        // check if action is implemented
        if ( !method_exists($this, $method = lcfirst(implode('', array_map('ucfirst', explode('_', $message->action)))))) {
            throw new RuntimeException(sprintf('Action "%s" not yet implemented', $message->action));
        }

        // execute action and return the result
        return $this->{$method}(...$message->args);
    }

}
