<?php

$sRootDir = realpath(__DIR__ . '/..');
$aDirs = array(
    'root'    => $sRootDir,
    'conf'    => $sRootDir . '/conf',
    'src'     => $sRootDir . '/src',
    'inc'     => $sRootDir . '/src/inc',
    'lib'     => $sRootDir . '/lib',
    'scripts' => $sRootDir . '/scripts',
    'tests'   => $sRootDir . '/tests',
    'tmp'     => '/tmp/queuing',
    'vendor'  => $sRootDir . '/vendor',
    'output'  => $sRootDir . '/output',
);

return array(
    'AMQP' => array(
        'dir' => $aDirs,

        // Rabbitmq cluster: array(
        //     array('ip or hostname', port, 'login', 'password'),
        //     …
        // )
        'cluster_rabbitmq' => array(
        ),

        // SSH user to login to cluster's servers:
        'ssh_user' => '',

        // CSV of each produced messages, where %d is the producer's pid
        'csv_produced_messages_id_filename' => 'msg_produced_%d.csv',

        // CSV of each consumed messages, where %d is the consumer's pid
        'csv_consumed_messages_id_filename' => 'msg_consumed_%d.csv',

        // log both STDIN & STDOUT of each scenario's action,
        // where %1$d is action's timing and %2$d is index of each action's entry
        'log_action_filenames'              => 'action_at_%1$d_%2$d.log',
    ),
    'GAubry\ErrorHandler' => array(
        'display_errors'      => true,
        'error_log_path'      => '/var/log/queue-test.error.log',
        'error_level'         => -1,
        'auth_error_suppr_op' => false
    ),
    'GAubry\Logger\ColoredIndentedLogger' => array(
        // http://en.wikipedia.org/wiki/ANSI_escape_code#CSI_codes
        'colors' => array(
            'normal'             => '',
            'processing'         => "\033[0;34m",
            'warning'            => "\033[0;33m",
            'error'              => "\033[1m\033[4;33m/!\\\033[0;37m \033[1;31m",
            'debug'              => "\033[0;30m",
        ),
        'base_indentation'     => "\033[0;30m┆\033[0m   ",
        'indent_tag'           => '+++',
        'unindent_tag'         => '---',
        'min_message_level'    => 'debug',
        'reset_color_sequence' => "\033[0m",
        'color_tag_prefix'     => 'C.'
    ),
);
