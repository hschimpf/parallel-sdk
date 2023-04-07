<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Scheduler;

use Closure;
use HDSSolutions\Console\Parallel\Internals\Commands;
use HDSSolutions\Console\Parallel\RegisteredWorker;
use HDSSolutions\Console\Parallel\Internals\Runner;
use parallel\Future;

trait HasRunner {

    /**
     * @var Future|Runner Instance of the Runner
     */
    private Future | Runner $runner;

    private function getRegisteredWorker(string $worker): RegisteredWorker | false {
        $message = new Commands\Runner\GetRegisteredWorkerMessage($worker);

        if (PARALLEL_EXT_LOADED) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

    private function registerWorker(string | Closure $worker, array $args): RegisteredWorker {
        $message = new Commands\Runner\RegisterWorkerMessage($worker, $args);

        if (PARALLEL_EXT_LOADED) {
            $this->send($message);

            return $this->recv();
        }

        return $this->runner->processMessage($message);
    }

}
