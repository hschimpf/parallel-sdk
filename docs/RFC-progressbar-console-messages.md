# RFC: Console message output from workers while a ProgressBar is active

**Status:** Proposed

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

`ParallelWorker` will expose two new methods (implemented in `HDSSolutions\Console\Parallel\Internals\Worker\CommunicatesWithProgressBarWorker`):

```php
public function write(string $message, bool $newline = false): void;
public function writeln(string $message): void;
```

They are intentionally **not** added to the `Contracts\ParallelWorker` interface to avoid a backwards-compatibility break. The intended usage is to extend `ParallelWorker`, which uses `CommunicatesWithProgressBarWorker` and therefore inherits the implementation.

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

## Implementation outline

### 1. New command message

`src/Internals/Commands/ProgressBar/WriteOutputMessage.php`

```php
<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals\Commands\ProgressBar;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;

final readonly class WriteOutputMessage extends ParallelCommandMessage {

    public function __construct(string $message, bool $newline = true) {
        parent::__construct('write_output', [ $message, $newline ]);
    }

}
```

The `write_output` action maps to a new `writeOutput()` method on `ProgressBarWorker` via `ListenEventsAndExecuteActions`.

### 2. Worker class / trait

`write()` and `writeln()` are implemented by the `CommunicatesWithProgressBarWorker` trait. `ParallelWorker` uses this trait, so any class extending `ParallelWorker` gets the methods automatically. No changes are required in `Contracts\ParallelWorker.php`.

### 3. Worker trait

`src/Internals/Worker/CommunicatesWithProgressBarWorker.php`

```php
final public function write(string $message, bool $newline = false): void {
    $message = new Commands\ProgressBar\WriteOutputMessage($message, $newline);

    if ($this->progressbar_channel !== null) {
        // progress bar is active: route through ProgressBarWorker
        if (PARALLEL_EXT_LOADED) {
            $this->progressbar_channel->send($message);
        } else {
            ($this->progressbar_channel)($message);
        }

        return;
    }

    if ($this->console_channel !== null) {
        // fallback: route to Runner/ConsoleWorker console output
        if (PARALLEL_EXT_LOADED) {
            $this->console_channel->send($message);
        } else {
            ($this->console_channel)($message);
        }

        return;
    }

    // last resort: no coordinator available
    fwrite(STDERR, $message.($newline ? PHP_EOL : ''));
}

final public function writeln(string $message): void {
    $this->write($message, true);
}
```

Notes:

- If a progress bar is active, the message is serialized through the existing channel to the `ProgressBarWorker` thread.
- If no progress bar is active but a console channel is connected, the message is routed to `Runner` (or a console worker it spawned).
- If neither is available, the worker writes directly to `STDERR` as a last resort.

### 4. ProgressBarWorker

`src/Internals/ProgressBarWorker.php`

```php
private function writeOutput(string $message, bool $newline = true): void {
    $this->progressBar->clear();
    $this->output->write($message, $newline);
    $this->progressBar->display();
}
```

This performs the exact sequence described in the issue: hide the bar, print the message, then redraw the bar so it recalculates its cursor position.

### 5. ProgressBarWorker trait

`src/Internals/ProgressBarWorker/HasProgressBar.php`

Store the same `OutputInterface` that `ProgressBar` will use. `ProgressBar` switches a `ConsoleOutput` to its error output, so both the bar and messages end up on `stderr`:

```php
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

trait HasProgressBar {

    private ProgressBar $progressBar;
    private bool $progressBarStarted = false;
    private OutputInterface $output;

    private function createProgressBar(): void {
        $this->output = (new ConsoleOutput())->getErrorOutput();
        $this->progressBar = new ProgressBar($this->output);

        // existing configuration stays unchanged
        $this->progressBar->setBarWidth(80);
        $this->progressBar->setRedrawFrequency(100);
        $this->progressBar->minSecondsBetweenRedraws(0.1);
        $this->progressBar->maxSecondsBetweenRedraws(0.2);
        $this->progressBar->setFormat(format:
            "%current% of %max%: %message%\n".
            "[%bar%] %percent:3s%%\n".
            "elapsed: %elapsed:6s%, remaining: %remaining:-6s%, %items_per_second% items/s"."...".
            "memory: %threads_memory%\n");

        $this->progressBar->setMessage('Starting...');
        $this->progressBar->setMessage('??', 'items_per_second');
        $this->progressBar->setMessage('??', 'threads_memory');
    }

}
```

`ProgressBar` receives the `stderr` `OutputInterface` directly, so both the bar and `write()` messages render on `stderr`.

### 6. Sequential fallback

`src/Internals/Runner/ManagesTasks.php`

The closures that forward messages to local handlers are currently typed as `ProgressBarActionMessage`. They need to accept any `ParallelCommandMessage`:

```php
// for workers with a progress bar
$worker->connectProgressBar(fn(Commands\ParallelCommandMessage $message) => $this->progressBar->processMessage($message));

// for workers without a progress bar
$worker->connectConsole(fn(Commands\ParallelCommandMessage $message) => $this->writeOutput($message));
```

`Runner::writeOutput()` would simply write to its local `ConsoleOutput`'s error output (no `clear()`/`display()` needed when no progress bar is active).

### 7. Fallback for workers without a progress bar

For workers that do **not** call `withProgress()`, `write()` should still be coordinated so messages are not lost in a multi-threaded run. `Runner` spawns a dedicated `ConsoleWorker` thread that owns the fallback `ConsoleOutput`; `ParallelWorker` routes messages to it when no progress bar channel is connected.

Design:

- `Runner` creates a dedicated console output channel (e.g. `ConsoleWorker::class.'@'.$uuid`) and spawns a `ConsoleWorker` thread.
- `ConsoleWorker` listens on that channel and writes each `WriteOutputMessage` to the `stderr` `OutputInterface` from its own `ConsoleOutput`.
- `ParallelWorker` connects to the console channel via `connectConsole(string $uuid)` when it starts.
- In sequential fallback, `ManagesTasks` passes a closure to `connectConsole()` that writes through `Runner`'s own `ConsoleOutput`.

```php
final public function write(string $message, bool $newline = false): void {
    $message = new Commands\ProgressBar\WriteOutputMessage($message, $newline);

    if ($this->progressbar_channel !== null) {
        // route through ProgressBarWorker
        if (PARALLEL_EXT_LOADED) {
            $this->progressbar_channel->send($message);
        } else {
            ($this->progressbar_channel)($message);
        }

        return;
    }

    if ($this->console_channel !== null) {
        // route to ConsoleWorker fallback
        if (PARALLEL_EXT_LOADED) {
            $this->console_channel->send($message);
        } else {
            ($this->console_channel)($message);
        }

        return;
    }

    // last resort
    fwrite(STDERR, $message.($newline ? PHP_EOL : ''));
}
```

`connectConsole(string $uuid)` (or a closure in sequential mode) sets `$this->console_channel`, analogous to `connectProgressBar()`.

### 8. ConsoleWorker

`src/Internals/ConsoleWorker.php`

A new worker thread that owns the fallback output and listens for `WriteOutputMessage`s:

```php
<?php declare(strict_types=1);

namespace HDSSolutions\Console\Parallel\Internals;

use HDSSolutions\Console\Parallel\Internals\Commands\ParallelCommandMessage;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleWorker {
    use Common\ListenEventsAndExecuteActions;

    private OutputInterface $output;

    public function __construct(
        private readonly string $uuid,
    ) {
        // open the console output channel
        // e.g. TwoWayChannel::make(self::class.'@'.$uuid);
        $this->output = (new ConsoleOutput())->getErrorOutput();
    }

    public function afterListening(): void {
        // close the console output channel
    }

    private function writeOutput(string $message, bool $newline = true): void {
        $this->output->write($message, $newline);
    }

}
```

`Runner` creates the channel and starts this thread in the same way it starts `ProgressBarWorker`.

## Behaviour

### With a progress bar

When `write()` is called in a worker that has `withProgress()` enabled:

1. The worker thread sends a `WriteOutputMessage` through the progress bar channel.
2. The `ProgressBarWorker` thread receives it, calls `clear()` to erase the bar, writes the message line to `stderr` via the same `OutputInterface`, then calls `display()` to redraw the bar below the message.

Because all ProgressBar actions are already processed sequentially through the channel, the `clear`/`write`/`display` sequence is atomic with respect to other bar updates.

### Without a progress bar

When `write()` is called in a worker that does **not** have `withProgress()` enabled:

1. The worker thread sends a `WriteOutputMessage` through the console fallback channel.
2. The `ConsoleWorker` thread receives it and writes the message line to `stderr`.

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

- `write()` and `writeln()` are added to the `ParallelWorker` abstract class via the `CommunicatesWithProgressBarWorker` trait, not to the `Contracts\ParallelWorker` interface. This avoids a BC break for any code that implements the interface directly.
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
- **Output stream for progress-bar workers:** Use the same `stderr` stream as `ProgressBar` for messages. `ProgressBar` switches a `ConsoleOutput` to its error output; we use that same `OutputInterface` for `write()`.
- **Output injection:** Out of scope for this RFC.
- **Fallback:** For workers without a progress bar, route messages to the `stderr` `OutputInterface` from a `ConsoleOutput` owned by a `ConsoleWorker` thread spawned by `Runner`. A last-resort `fwrite(STDERR)` remains only when no coordinator is available.

## Known caveats

- None currently; messages and the progress bar share `stderr`, so `clear()`/`write()`/`display()` work as Symfony intended.

## Recommended next steps

1. Implement the approved design.
2. Add PHPUnit tests for both the progress-bar path and the non-progress-bar fallback path.
3. Update `README.md` to document `write()`/`writeln()`.
