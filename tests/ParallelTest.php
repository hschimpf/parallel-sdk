<?php declare(strict_types=1);

namespace HDSSolutions\Console\Tests;

use HDSSolutions\Console\Parallel\Scheduler;
use PHPUnit\Framework\TestCase;
use Throwable;
use function parallel\bootstrap;

final class ParallelTest extends TestCase {

    public function testThatParallelExtensionIsAvailable(): void {
        // check that ext-parallel is available
        $this->assertTrue($loaded = extension_loaded('parallel'), 'Parallel extension isn\'t available');

        // check if extension is available
        if ($loaded) {
            // set parallel bootstrap file
            bootstrap(__DIR__.'/config/bootstrap.php');
        }
    }

    /**
     * @depends testThatParallelExtensionIsAvailable
     */
    public function testParallel(): void {
        // register worker
        Scheduler::with(new TestWorker())
            // register task finished callback
            ->onTaskFinished(static function($task_no) {
                echo sprintf("%s finished on TestWorker\n", $task_no);
            });
        // run example tasks
        for ($i = 1; $i <= 25; $i++) {
            try { Scheduler::runTask($i);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        // change worker
        Scheduler::with(new AnotherWorker())
            // register task finished callback
            ->onTaskFinished(static function($task_no) {
                echo sprintf("%s finished on AnotherWorker\n", $task_no);
            });
        // run more example tasks
        for ($i = 1; $i <= 25; $i++) {
            try { Scheduler::runTask($i);
            } catch (Throwable) {
                Scheduler::stop();
            }
        }

        foreach (Scheduler::getThreadsResults() as $task_result) {
            echo sprintf("Task result from #%u\n", $task_result);
        }

        Scheduler::disconnect();
    }

}
