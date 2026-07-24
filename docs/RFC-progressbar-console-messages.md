# RFC: Console message output from workers while a ProgressBar is active

**Status:** Implemented in PR #28

> **Note:** The final implementation keeps the `ProgressBar` and `StreamOutput` inside the `Runner` thread instead of spawning a separate `ProgressBarWorker`/`ConsoleWorker`, because routing everything through the existing `Runner` channel proved simpler and avoided the CI deadlocks seen during development. The public API and behavior described below remain unchanged.

## Problem

When a worker calls `echo`/`fwrite` while the SDK is rendering a `Symfony\Component\Console\Helper\ProgressBar`, the next ProgressBar refresh overwrites the message. The ProgressBar keeps an internal cursor/line count and re-prints its output on top of whatever was last written to the terminal.

## Goals

- Allow workers to emit ad-hoc console messages that are **not** overwritten by the ProgressBar.
- Keep the feature optional: only workers that enabled `withProgress()` should rely on coordinated output.
- Preserve the existing architecture: workers run in isolated `parallel\Runtime`s and cannot share stream resources such as `ConsoleOutput`.

## Non-goals

- Provide a general `OutputInterface` injection point for arbitrary streams.
- Capture or redirect all `echo`/`print` statements automatically.

## Constraints

- `ext-parallel` cannot share objects that wrap PHP resources. `ConsoleOutput` holds `php://stdout`/`php://stderr` streams, so it cannot live in `Runner` and be passed into a worker thread.
- Messages must therefore be routed to a worker thread that owns the `ConsoleOutput`: the existing `ProgressBarWorker` when a progress bar is active, or a new `ConsoleWorker` spawned by `Runner` when it is not.

## Proposed public API

`ParallelWorker` will expose two new methods (implemented in `HDSSolutions\Console\Parallel\Internals\Worker\CommunicatesWithRunner`):

```php
public function write(string $message, bool $newline = false): void;
public function writeln(string $message): void;
```

They are intentionally **not** added to the `Contracts\ParallelWorker` interface to avoid a backwards-compatibility break. The intended usage is to extend `ParallelWorker`, which uses `CommunicatesWithRunner` and therefore inherits the implementation.

Usage inside a worker:

```php
final class ExampleWorker extends ParallelWorker {

    protected function process(int $number = 0): int {
        $this->setMessage("Processing #{$number}");
        $this->writeln("Starting heavy work for task #{$number}");

        // ... do work ...

        $this->writeln("Finished task #{$number}");
        $this->advance();

        return $number;
    }

}
```

`write()`/`writeln()` are different from `setMessage()`:

- `setMessage()` changes a ProgressBar placeholder and is only visible inside the bar.
- `write()`/`writeln()` emit a real console line above the bar.

## Implementation outline (final)

### 1. New command message

`src/Internals/Commands/Output/WriteOutputMessage.php`

```php
<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\Output;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

final class WriteOutputMessage extends ParallelCommandMessage {

    public function __construct(
        public readonly string $message,
        public readonly bool $newline = true,
    ) {
        parent::__construct(action: 'write_output', args: [ $message, $newline ]);
    }

}
```

The `write_output` action is dispatched by `ListenEventsAndExecuteActions` to `Runner::writeOutput()`.

### 2. Worker class / trait

`write()` and `writeln()` are implemented by the `CommunicatesWithRunner` trait. `ParallelWorker` uses this trait, so any class extending `ParallelWorker` gets the methods automatically. No changes are required in `Contracts\ParallelWorker.php`.

### 3. Worker trait

`src/Internals/Worker/CommunicatesWithRunner.php`

```php
final public function write(string $message, bool $newline = false): void {
    $this->sendOutputMessage(new Commands\Output\WriteOutputMessage($message, $newline));
}

final public function writeln(string $message): void {
    $this->write($message, true);
}

private function sendOutputMessage(Commands\Output\WriteOutputMessage $message): void {
    if ($this->runner_channel !== null) {
        if (PARALLEL_EXT_LOADED) {
            $this->runner_channel->send($message);
        } else {
            ($this->runner_channel)($message);
        }

        return;
    }

    // fallback when no coordinator is available: write to a fresh stderr stream
    $stream = fopen('php://stderr', 'w');
    if ($stream !== false) {
        fwrite($stream, $message->args[0].($message->args[1] ? PHP_EOL : ''));
        fclose($stream);
    }
}
```

Notes:

- Every worker is connected to the `Runner` main channel, so `runner_channel` is always set.
- If the channel cannot be established, the worker writes directly to a fresh `php://stderr` stream.

### 4. Runner-owned output

The `Runner` thread owns the Symfony `ProgressBar` and the output stream. `src/Internals/Runner/HasProgressBar.php` creates the `ProgressBar` on a `StreamOutput` tied to `php://stderr`:

```php
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

trait HasProgressBar {

    private ProgressBar $progressBar;
    private bool $progressBarStarted = false;
    private OutputInterface $output;

    private function createProgressBar(): void {
        // use a fresh stderr stream owned by this thread
        $this->output = new StreamOutput(fopen('php://stderr', 'w'));
        $this->progressBar = new ProgressBar($this->output);

        // existing configuration stays unchanged
        $this->progressBar->setBarWidth(80);
        $this->progressBar->setRedrawFrequency(100);
        $this->progressBar->minSecondsBetweenRedraws(0.1);
        $this->progressBar->maxSecondsBetweenRedraws(0.2);
        $this->progressBar->setFormat(
            "%current% of %max%: %message%\n".
            "[%bar%] %percent:3s%%\n".
            "elapsed: %elapsed:6s%, remaining: %remaining:-6s%, %items_per_second% items/s".(PARALLEL_EXT_LOADED ? "\n" : ",").
            "memory: %threads_memory%\n");

        $this->progressBar->setMessage('Starting...');
        $this->progressBar->setMessage('??', 'items_per_second');
        $this->progressBar->setMessage('??', 'threads_memory');
    }

}
```

`src/Internals/Runner/HasSharedProgressBar.php` processes the `write_output`, `stats_report`, and `progress_bar_action` messages directly in the `Runner` thread:

```php
private function writeOutput(string $message, bool $newline = true): void {
    if ($this->progressBarStarted) {
        $this->progressBar->clear();
        $this->output->write($message, $newline);
        $this->progressBar->display();

        return;
    }

    $this->output->write($message, $newline);
}
```

This performs the exact sequence described in the issue: hide the bar, print the message, then redraw the bar so it recalculates its cursor position.

### 5. Connect every worker to the Runner

`src/Internals/Runner/ManagesTasks.php` ensures every worker gets a channel to the `Runner`:

```php
// init progressbar (it also handles console messages from this worker)
$this->initProgressBar();

// connect worker to the Runner's output handler
$worker->connectRunner(fn(Commands\ParallelCommandMessage $message) => $this->processMessage($message));

// check if worker has ProgressBar enabled
if ($registered_worker->hasProgressEnabled() && !$this->progressBarStarted) {
    // register worker
    $this->registerProgressBar($worker_class, $registered_worker->getSteps());
}
```

In threaded mode `connectRunner()` opens the `Runner` main channel; in sequential mode it stores the closure that dispatches messages back into `Runner::processMessage()`.

### 6. No separate coordinator threads

The original RFC considered a separate `ProgressBarWorker` thread and a `ConsoleWorker` thread for the fallback. The final implementation keeps the `ProgressBar` and `StreamOutput` inside the `Runner` thread and routes all worker messages through the existing `Runner` channel. This removes a persistent child-thread lifetime issue and avoids sharing stream resources across threads.

## Behaviour

### With a progress bar

When `write()` is called in a worker that has `withProgress()` enabled:

1. The worker thread sends a `WriteOutputMessage` through the `Runner` main channel.
2. The `Runner` thread receives it, calls `clear()` to erase the bar, writes the message line to `stderr` via the same `OutputInterface`, then calls `display()` to redraw the bar below the message.

Because all ProgressBar actions are already processed sequentially through the channel, the `clear`/`write`/`display` sequence is atomic with respect to other bar updates.

### Without a progress bar

When `write()` is called in a worker that does **not** have `withProgress()` enabled:

1. The worker thread still sends a `WriteOutputMessage` through the `Runner` main channel.
2. The `Runner` thread receives it and writes the message line directly to the `stderr` `OutputInterface`.

There is no `clear()`/`display()` because no progress bar is active.

## Example

Scheduler code:

```php
Scheduler::using(LogWorker::class)
    ->withProgress(steps: 10);

foreach (range(1, 10) as $i) {
    Scheduler::runTask($i);
}

Scheduler::awaitTasksCompletion();
```

Worker:

```php
final class LogWorker extends ParallelWorker {

    protected function process(int $number = 0): int {
        $this->setMessage("Task #{$number}");
        $this->writeln("Starting task #{$number}");

        usleep(100_000);

        $this->writeln("Finished task #{$number}");
        $this->advance();

        return $number;
    }

}
```

Expected terminal flow:

```
Starting task #1
 1 of 10: Task #1
 [=====>---------------------------------------------]  10%
 ...
Finished task #1
 2 of 10: Task #2
 [=========>-----------------------------------------]  20%
```

## Backwards compatibility

- `write()` and `writeln()` are added to the `ParallelWorker` abstract class via the `CommunicatesWithRunner` trait, not to the `Contracts\ParallelWorker` interface. This avoids a BC break for any code that implements the interface directly.
- No existing methods are changed or removed.

## Alternatives considered

1. **Pass a `ConsoleOutput` object from `Runner` into workers**
   - Not possible with `ext-parallel` because the output wraps stream resources.
   - Sending message payloads to a `ConsoleOutput` owned by `Runner` (or a worker it spawns) is valid and is the chosen fallback.

2. **Use `echo`/`fwrite` in the worker and pause the ProgressBar**
   - The bar still overwrites the message on its next refresh because the cursor logic is unaware of the extra line.

3. **Use `ConsoleOutput->section()`**
   - More robust long-term, but requires a larger rewrite of `HasProgressBar` and coordination of multiple `ConsoleSectionOutput` instances.
   - Symfony's `ProgressBar` supports section outputs, but the SDK currently uses a plain `ConsoleOutput`. This could be a future enhancement.

4. **Single `writeMessage(string $message)` instead of `write`/`writeln`**
   - Rejected in favor of Symfony's `write()` + `writeln()` naming.

## Decisions made

- **Naming:** Use Symfony `OutputInterface` naming: `write()` + `writeln()`.
- **Memory stats:** Do not update memory stats on `write()`.
- **Output stream:** Use a single `stderr` `StreamOutput` for the `ProgressBar` and messages. `ProgressBar` and `write()` output are emitted through the same `OutputInterface` so `clear()`/`write()`/`display()` work as Symfony intended.
- **Coordinator:** The `Runner` thread owns the `ProgressBar` and the `StreamOutput`. All worker messages (including `write_output`) are routed through the existing `Runner` channel; no separate `ProgressBarWorker` or `ConsoleWorker` threads are spawned.
- **Output injection:** Out of scope for this RFC.
- **Fallback:** If a worker cannot connect to the `Runner` channel, it opens a fresh `php://stderr` stream and writes the message directly.

## Known caveats

- None currently; messages and the progress bar share `stderr`, so `clear()`/`write()`/`display()` work as Symfony intended.

## Recommended next steps

- [x] Implement the approved design.
- [x] Add PHPUnit tests for both the progress-bar path and the non-progress-bar path.
- [x] Update `README.md` to document `write()`/`writeln()`.
- [x] Rename `CommunicatesWithProgressBarWorker` to a name that reflects both progress-bar and console-message responsibilities (e.g. `CommunicatesWithRunner`).
