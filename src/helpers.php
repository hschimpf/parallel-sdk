<?php declare(strict_types=1);

const PARALLEL_AUTOLOADER = __DIR__.'/autoload.php';

if ( !function_exists('cpu_count')) {
    function cpu_count(float $percent = 1.0): int {
        $cpu_count = 1;
        if ($percent > 1) {
            $percent = 1.0;
        }

        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cpu_count = count($matches[0]);

        } elseif (stripos(PHP_OS_FAMILY, 'WIN') === 0) {
            $process = @popen('wmic cpu get NumberOfCores', 'rb');
            if (false !== $process) {
                fgets($process);
                $cpu_count = (int) fgets($process);
                pclose($process);
            }

        } else {
            $ps = @popen('sysctl -a', 'rb');
            if (false !== $ps) {
                $output = stream_get_contents($ps);
                preg_match('/hw.ncpu: (\d+)/', $output, $matches);
                if ( !empty($matches)) {
                    $cpu_count = (int) $matches[1][0];
                }

                pclose($ps);
            }
        }

        return (int) ($cpu_count * $percent);
    }
}
