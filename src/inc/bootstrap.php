<?php

/**
 * Bootstrap.
 *
 * @author Geoffroy AUBRY <geoffroy.aubry@hi-media.com>
 */

use GAubry\ErrorHandler\ErrorHandler;

if (! file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    echo "\033[1m\033[4;33m/!\\\033[0;37m "
        . "You must set up the project dependencies, run the following commands:" . PHP_EOL
        . "    \033[0;33mcomposer install\033[0;37m or \033[0;33mphp composer.phar install\033[0;37m." . PHP_EOL
        . PHP_EOL
        . "If needed, to install \033[1;37mcomposer\033[0;37m locally: "
            . "\033[0;37m\033[0;33mcurl -sS https://getcomposer.org/installer | php\033[0;37m" . PHP_EOL
            . "Or check http://getcomposer.org/doc/00-intro.md#installation-nix for more information." . PHP_EOL
            . PHP_EOL;
    exit(1);
}

require __DIR__ . '/../../vendor/autoload.php';

$aConfig = require_once(__DIR__ . '/../../conf/queuing.php');

// set_include_path(
//     $aConfig['Himedia\DW']['dir']['lib'] . PATH_SEPARATOR .
//     get_include_path()
// );

new ErrorHandler($aConfig['GAubry\ErrorHandler']);

date_default_timezone_set('UTC');

require_once(__DIR__ . '/common.php');
