<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Runner;

use HDSSolutions\Console\Parallel\Internals\RegisteredWorker;

trait ManagesWorkers {

    /**
     * @var RegisteredWorker[] Registered workers
     */
    private array $workers = [];

    /**
     * @var array{ string, int } HashMap of registered workers
     */
    private array $workers_hashmap = [];

    /**
     * @var int | false Currently selected worker
     */
    private int | false $selected_worker = false;

    private function selectWorker(int $idx): self {
        $this->selected_worker = $idx;

        return $this;
    }

    private function getSelectedWorker(): ?RegisteredWorker {
        if ($this->selected_worker === false) {
            return null;
        }

        return $this->workers[ $this->selected_worker ];
    }

}
