<?php declare(strict_types=1);

require_once __DIR__.'/helpers.php';

spl_autoload_register(static function($class) {
    // build class file path
    $classfile = sprintf('%s/%s.php', __DIR__,
        // replace namespace and invert slashes
        str_replace([ 'HDSSolutions\\Console\\Parallel\\', '\\' ], [ '', '/' ], $class));
    // check if exists
    if (is_file($classfile)) {
        // include class file
        include_once $classfile;
    }
}, prepend: true);
