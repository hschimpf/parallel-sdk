<?php declare(strict_types=1);

namespace HDSSolutions\Parallel\Tests;

use HDSSolutions\Parallel\Parallel;
use PHPUnit\Framework\TestCase;
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
        // TODO
    }

}
