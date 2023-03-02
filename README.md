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
// check if extension is loaded to allow deploying even in envorinments where parallel isn't installed
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

$worker = new ExampleWorker();
Scheduler::using($worker);
```

### Schedule tasks
After defining a Worker, you can schedule tasks that will run in parallel.
```php
use HDSSolutions\Console\Parallel\Scheduler;

foreach (range(1, 100) as $task) {
    try {
        // tasks will start as soon as a thread is available
        Scheduler::runTask($task);

    } catch (Throwable) {
        // if no Worker was defined, a RuntimeException will be thrown
        // also, Workers have some limitations, see Reference #2 for more info
    }
}
```

### Get processed tasks result

```php
use HDSSolutions\Console\Parallel\Scheduler;
use HDSSolutions\Console\Parallel\ProcessedTask;

foreach (Scheduler::getProcessedTasks() as $processed_task) {
    // you have access to the Worker class that was used to processed the task
    $worker = $processed_task->getWorkerClass();
    // and the result of the task processed
    $result = $processed_task->getResult();
}
```

## Graceful close all resources
This method will close all resources used internally by the `Scheduler` instance.
```php
use HDSSolutions\Console\Parallel\Scheduler;

Scheduler::disconnect();
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
