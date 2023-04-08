# Parallel SDK
An implementation of [krakjoe/parallel](https://github.com/krakjoe/parallel) PHP extension.

[![Latest Stable Version](http://poser.pugx.org/hds-solutions/parallel-sdk/v)](https://packagist.org/packages/hds-solutions/parallel-sdk) [![Total Downloads](http://poser.pugx.org/hds-solutions/parallel-sdk/downloads)](https://packagist.org/packages/hds-solutions/parallel-sdk) [![License](http://poser.pugx.org/hds-solutions/parallel-sdk/license)](https://packagist.org/packages/hds-solutions/parallel-sdk) [![PHP Version Require](http://poser.pugx.org/hds-solutions/parallel-sdk/require/php)](https://packagist.org/packages/hds-solutions/parallel-sdk)

This library is designed to work even if the `parallel` extension isn't available. In that case, the tasks will be executed un sequential order.
That allow that your code can be deployed in any environment, and if `parallel` is enabled you will get the advantage of parallel processing.

## Installation
### Dependencies
You need this dependencies to execute tasks in parallel.
- PHP >= 8.0 with ZTS enabled
- parallel PECL extension

Parallel extension documentation can be found on https://php.net/parallel.

### Through composer
```bash
composer require hds-solutions/parallel-sdk
```

## Usage
Firstly, you need to set the bootstrap file for parallel. Setting the composer's autoloader is enough. See reference [#1](#references) for more info.
```php
// check if extension is loaded to allow deploying even in environments where parallel isn't installed
if (extension_loaded('parallel')) {
    // set the path to composer's autoloader
    parallel\bootstrap(__DIR__.'/vendor/autoload.php');
}
```

You need to define a `Worker` that will process the tasks. There are two options:
1. Using an anonymous function as a `Worker`.
2. Creating a class that extends from `ParallelWorker` and implements the `process()` method.

Then you can schedule tasks to run in parallel using `Scheduler::runTask()` method.

### Anonymous worker
Defining an anonymous function as a `Worker` to process the tasks.
```php
use HDSSolutions\Console\Parallel\Scheduler;

Scheduler::using(static function(int $number): int {
    // here you do some work with the received data
    // this portion of code will run on a separated thread
    
    // example process
    $microseconds = random_int(100, 500);
    echo sprintf("AnonymousWorker >> Hello from task #%u, I'll wait %sms\n", $number, $microseconds);
    usleep($microseconds * 1000);
    // end example process
    
    // the data returned will be available later
    return $number;
});
```

### Worker instance
Creating a class that extends from `ParallelWorker` class. This could be usefull for complex processes and to maintain your code clean.

`ExampleWorker.php`:
```php
use HDSSolutions\Console\Parallel\ParallelWorker;

final class ExampleWorker extends ParallelWorker {

    protected function process(int $number = 0): int {
        // example process
        $microseconds = random_int(100, 500);
        echo sprintf("ExampleWorker >> Hello from task #%u, I'll wait %sms\n", $number, $microseconds);
        usleep($microseconds * 1000);
        // end example process

        return $number;
    }

}
```

```php
use HDSSolutions\Console\Parallel\Scheduler;

Scheduler::using(ExampleWorker::class);
```

You can also send parameters to the Worker's constructor.
```php
use HDSSolutions\Console\Parallel\ParallelWorker;

final class ExampleWorker extends ParallelWorker {

    public function __construct(
        private array $multipliers,
    ) {}

}
```

```php
use HDSSolutions\Console\Parallel\Scheduler;

Scheduler::using(ExampleWorker::class, [ 2, 4, 8 ]);
```

### Schedule tasks
After defining a Worker, you can schedule tasks that will run in parallel.
```php
use HDSSolutions\Console\Parallel\Scheduler;

foreach (range(1, 100) as $task_data) {
    try {
        // tasks will start as soon as a thread is available
        Scheduler::runTask($task_data);

    } catch (Throwable) {
        // if no Worker was defined, a RuntimeException will be thrown
        // also, Workers have some limitations, see Reference #2 for more info
    }
}
```

### Check Tasks state
Every task has an state. There is also helper functions to check current Task state:
```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

do {
    $all_processed = true;
    foreach (Scheduler::getTasks() as $task) {
        switch (true) {
            case $task->isPending():
                $all_processed = false;
                break;
    
            case $task->isBeingProcessed():
                $all_processed = false;
                break;
    
            case $task->wasProcessed():
                $result = $task->getResult();
                break;
        }
    }
} while ($all_processed == false);
```

### Wait for tasks completion
Instead of checking every task state, you can wait for all tasks to be processed before continue your code execution.
```php
use HDSSolutions\Console\Parallel\Scheduler;

// This will pause execution until all tasks are processed
Scheduler::awaitTasksCompletion();
```

### Get processed tasks result

```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

foreach (Scheduler::getTasks() as $task) {
    // you have access to the Worker class that was used to processed the task
    $worker = $task->getWorkerClass();
    // and the result of the task processed
    $result = $task->getResult();
}
```

### Remove pending tasks
You can stop processing queued tasks if your process needs to stop earlier.
```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

// this will remove tasks from the pending queue
Scheduler::removePendingTasks();

// after cleaning the queue, you should wait for tasks that are currently being processed to finish
Scheduler::awaitTasksCompletion();

$results = [];
$unprocessed_tasks = [];
foreach (Scheduler::getTasks() as $task) {
    if ($task->wasProcessed()) {
        $results[] = $task->getResult();
    } else {
        // tasks that were not processed, will remain in the Pending state
        $unprocessed_tasks[] = $task;
    }
}
```

### Stop all processing immediately
If you need to stop all right away, you can call the `Scheduler::stop()` method. This will stop processing all tasks immediately.
```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

// this will stop processing tasks immediately
Scheduler::stop();

// in this state, Tasks should have 3 of the following states
foreach (Scheduler::getTasks() as $task) {
    switch (true) {
        case $task->isPending():
            // Task was never processed
            break;

        case $task->wasProcessed():
            // Task was processed by the Worker
            break;

        case $task->wasCancelled():
            // Task was cancelled while was being processed
            break;
    }
}
```

### ProgressBar

#### Requeriments
- `symfony/console` package
- Enable a ProgressBar for the worker calling the `withProgress()` method.

```php
use HDSSolutions\Console\Parallel\Scheduler;

$tasks = range(1, 10);

Scheduler::using(ExampleWorker::class)
    ->withProgress(steps: count($tasks));
```

#### Usage from Worker
Available methods are:
- `setMessage(string $message)`
- `advance(int $steps)`
- `setProgress(int $step)`
- `display()`
- `clear()`

```php
use HDSSolutions\Console\Parallel\ParallelWorker;

final class ExampleWorker extends ParallelWorker {

    protected function process(int $number = 0): int {
        // example process
        $microseconds = random_int(100, 500);
        $this->setMessage(sprintf("ExampleWorker >> Hello from task #%u, I'll wait %sms", $number, $microseconds));
        usleep($microseconds * 1000);
        $this->advance();
        // end example process

        return $number;
    }

}
```

#### Example output
```bash
 28 of 52: ExampleWorker >> Hello from task #123, I'll wait 604ms
 [===========================================>------------------------------------]  53%
 elapsed: 2 secs, remaining: 2 secs, ~13.50 items/s
 memory: 562 KiB, threads: 12x ~474 KiB, Σ 5,6 MiB ↑ 5,6 MiB
```

### References
1. [parallel\bootstrap()](https://www.php.net/manual/en/parallel.bootstrap.php)
2. [Parallel\Runtime::run() Task Characteristics](https://www.php.net/manual/en/parallel-runtime.run.php#refsect1-parallel-runtime.run-closure-characteristics)

# Security Vulnerabilities
If you encounter any security related issue, feel free to raise a ticket on the issue traker.

# Contributors
- [Hermann D. Schimpf](https://hds-solutions.net)

# Licence
GPL-3.0 Please see [License File](LICENSE) for more information.

