# Parallel SDK
An implementation of [krakjoe/parallel](https://github.com/krakjoe/parallel) PHP extension.

[![Latest stable version](https://img.shields.io/packagist/v/hds-solutions/parallel-sdk?style=flat-square&label=latest&color=0092CB)](https://github.com/hschimpf/parallel-sdk/releases/latest)
[![License](https://img.shields.io/github/license/hds-solutions/parallel-sdk?style=flat-square&color=009664)](https://github.com/hschimpf/parallel-sdk/blob/main/LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/hds-solutions/parallel-sdk?style=flat-square&color=747474)](https://packagist.org/packages/hds-solutions/parallel-sdk)
[![Monthly Downloads](https://img.shields.io/packagist/dm/hds-solutions/parallel-sdk?style=flat-square&color=747474&label)](https://packagist.org/packages/hds-solutions/parallel-sdk)
[![Required PHP version](https://img.shields.io/packagist/dependency-v/hds-solutions/parallel-sdk/php?style=flat-square&color=006496&logo=php&logoColor=white)](https://packagist.org/packages/hds-solutions/parallel-sdk)

[![PHP 8.2](https://img.shields.io/github/actions/workflow/status/hds-solutions/parallel-sdk/linux-php-8.2.yml?style=flat-square&logo=github&label=PHP%208.2)](https://github.com/hschimpf/parallel-sdk/actions/workflows/linux-php-8.2.yml)
[![PHP 8.3](https://img.shields.io/github/actions/workflow/status/hds-solutions/parallel-sdk/linux-php-8.3.yml?style=flat-square&logo=github&label=PHP%208.3)](https://github.com/hschimpf/parallel-sdk/actions/workflows/linux-php-8.3.yml)
[![PHP 8.4](https://img.shields.io/github/actions/workflow/status/hds-solutions/parallel-sdk/linux-php-8.4.yml?style=flat-square&logo=github&label=PHP%208.4)](https://github.com/hschimpf/parallel-sdk/actions/workflows/linux-php-8.4.yml)

This library is designed to work even if the `parallel` extension isn't available. In that case, the tasks will be executed un sequential order.
That allow that your code can be deployed in any environment, and if `parallel` is enabled you will get the advantage of parallel processing.

## Installation
### Dependencies
You need these dependencies to execute tasks in parallel.
- PHP >= 8.2 with ZTS enabled
- parallel PECL extension _(v1.2.5 or higher)_

Parallel extension documentation can be found on https://php.net/parallel.

### Through composer
```bash
composer require hds-solutions/parallel-sdk
```

## Usage

You should set the bootstrap file for the parallel threads. Setting the composer's autoloader is enough.

```php
// check if extension is loaded to allow deploying even in environments where parallel isn't installed
if (extension_loaded('parallel')) {
    // set the path to composer's autoloader
    parallel\bootstrap(__DIR__.'/vendor/autoload.php');
}
```

Behind the scenes, the parallel extension creates an empty Runtime _(thread)_ where the tasks are executed. Every
Runtime is a clean, empty, isolated environment without any preloaded classes, functions, or autoloaders from the parent
thread/process. This isolation ensures that each runtime starts with a minimal footprint. See
references [#1](#references) and [#2](#references) for more info.

Then you define a `Worker` that will process the tasks. There are two options:
1. Using an anonymous function as a `Worker`.
2. Creating a class that extends from `ParallelWorker` and implements the `process()` method.

Then you can schedule tasks to run in parallel using `Scheduler::runTask()` method.

### Bootstrap a Laravel app
Since ZTS is only available on the cli, you should set the bootstrap file for parallel threads in the `artisan` file.
```diff
#!/usr/bin/env php
<?php

+ // check if parallel extension is loaded
+ if (extension_loaded('parallel')) {
+     // and register the bootstrap file for the threads
+     parallel\bootstrap(__DIR__.'/bootstrap/parallel.php');
+ }

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
```

Then, in the bootstrap file for the parallel threads, you just need to get an instance of the app and bootstrap the Laravel kernel. This way you will have all Laravel service providers registered.
`bootstrap/parallel.php`:
```php
<?php

require __DIR__.'/../vendor/autoload.php';

// Bootstrap the Console Kernel
(require_once __DIR__.'/app.php')
    ->make(Illuminate\Contracts\Console\Kernel::class)
    ->bootstrap();
```

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
Creating a class that extends from `ParallelWorker` class. This could be useful for complex processes and to maintain your code clean.

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
        // also, Workers have some limitations, see Reference #3 for more info
    }
}
```

### Check Tasks state
Every task has a state. There is also helper functions to check current Task state:
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
                $result = $task->getOutput();
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

You can also specify a time limit for waiting. The process will pause until all tasks are processed or until max time has been reached, whatever comes first.
```php
use HDSSolutions\Console\Parallel\Scheduler;

// Pause until all tasks are processed or until 15 minutes pass
Scheduler::awaitTasksCompletion(wait_until: new DateInterval('PT15M'));
```

### Get processed tasks result

```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

foreach (Scheduler::getTasks() as $task) {
    // you have access to the Worker class that was used to process the task
    $worker = $task->getWorkerClass();
    // and the result of the task processed
    $result = $task->getOutput();
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
        $results[] = $task->getOutput();
    } else {
        // tasks that were not processed, will remain in the Pending state
        $unprocessed_tasks[] = $task;
    }
}
```

### Remove a pending/running task
You can remove a specific task from the processing queue if you need to.
```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\Task;

foreach (Scheduler::getTasks() as $task) {
    // if for some reason you want to remove a task, or just want to free memory when a task finishes
    if (someValidation($task) || $task->wasProcessed()) {
        // this will remove the task from the processing queue
        // IMPORTANT: if the task is already running, it will be stopped
        Scheduler::removeTask($task);
    }
}
```

### Stop processing all tasks immediately
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

### Specifying the No. of CPU Cores
You can control the maximum percentage or number of CPU cores to use by calling the following methods:
```php
use HDSSolutions\Console\Parallel\Scheduler;

Scheduler::setMaxCpuCountUsage(2);        // Use at max two CPU cores
Scheduler::setMaxCpuPercentageUsage(0.5); // Use at max 50% of the total of CPU cores
```

### ProgressBar

#### Requirements
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
2. [parallel\Runtime](https://www.php.net/manual/en/class.parallel-runtime.php)
3. [Parallel\Runtime::run() Task Characteristics](https://www.php.net/manual/en/parallel-runtime.run.php#refsect1-parallel-runtime.run-closure-characteristics)

# Security Vulnerabilities
If you encounter any security-related issues, please feel free to raise a ticket on the issue tracker.

# Contributing
Contributions are welcome! If you find any issues or would like to add new features or improvements, please feel free to submit a pull request.

## Contributors
- [Hermann D. Schimpf](https://hds-solutions.net)

# Licence
This library is open-source software licensed under the [MIT License](LICENSE).
Please see the [License File](LICENSE) for more information.
