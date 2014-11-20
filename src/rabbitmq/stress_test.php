<?php

use GAubry\Helpers\Helpers;
use GAubry\Logger\ColoredIndentedLogger;
use Himedia\Queuing\Orchestrator\Orchestrator;
use Ulrichsg\Getopt\Getopt;

require(__DIR__ . '/inc/common.php');

// Script's options:
$oGetopt = parseOptions(array(
    array('n',  'sec-per-segment', Getopt::REQUIRED_ARGUMENT, 'Nb seconds per segment (default 10)',   10),
    array('l',  'load-factor',     Getopt::REQUIRED_ARGUMENT, 'Msg multiplication factor (default 1)', 1),
    array('o',  'output',          Getopt::REQUIRED_ARGUMENT, "Output directory (default '/tmp')",     '/tmp'),
    array(null, 'is-durable',      Getopt::REQUIRED_ARGUMENT, 'Is a durable queue? (1/0, default 1)',  1),
    array(null, 'queue-name',      Getopt::REQUIRED_ARGUMENT, "Name of the queue (default 'noname')",  'noname'),
    array('h',  'help',            Getopt::NO_ARGUMENT,       'Display this help'),
));

$N           = (int)$oGetopt->getOption('sec-per-segment');
$M           = round($N/2);
$L           = (int)$oGetopt->getOption('load-factor');
$sOutputPath = $oGetopt->getOption('output');
$bIsDurable  = (bool)$oGetopt->getOption('is-durable');
$sQueueName  = $oGetopt->getOption('queue-name');

// End of script's options ---------------------------------------------------



// Define scenario:
if (! file_exists($sOutputPath)) {
    mkdir($sOutputPath);
}
$sBinPhp = 'php';
$sNode1 = $aConfig['AMQP']['ssh_user'] . '@' . $aConfig['AMQP']['cluster_rabbitmq'][0][0];
$sNode2 = $aConfig['AMQP']['ssh_user'] . '@' . $aConfig['AMQP']['cluster_rabbitmq'][1][0];
$sScriptsDir = $aConfig['AMQP']['dir']['scripts'];
$sSrcDir = $aConfig['AMQP']['dir']['src'];
$sProducer = "$sBinPhp $sSrcDir/rabbitmq/producer.php"
           . " --queue-name $sQueueName --with-acks 0 --is-durable " . (int)$bIsDurable . " --output $sOutputPath";
$sConsumer = "$sBinPhp $sSrcDir/rabbitmq/consumer.php"
           . " --queue-name $sQueueName --is-durable " . (int)$bIsDurable . " --output $sOutputPath";
$aScenario = array(

    // start servers 1 & 2, start recording of memory & cpu:
    -10 => array(
        "$sScriptsDir/rabbitmq/reset_server.sh $sNode1 & \\
         $sScriptsDir/rabbitmq/reset_server.sh $sNode2 & \\
         wait && \\
            $sScriptsDir/rabbitmq/add_server_to_cluster.sh $sNode2 " . $aConfig['AMQP']['cluster_rabbitmq'][0][0],

        "ssh $sNode1 \"rm -f /tmp/queuing_bench_sar*; sar -ur -o /tmp/queuing_bench_sar 1 " . (7*$N+20) . " 1>/dev/null 2>&1; \\
            sadf -d /tmp/queuing_bench_sar -- -r > /tmp/queuing_bench_sar_mem.csv; \\
            sadf -d /tmp/queuing_bench_sar -- -u > /tmp/queuing_bench_sar_cpu.csv\"",
        "ssh $sNode2 \"rm -f /tmp/queuing_bench_sar*; sar -ur -o /tmp/queuing_bench_sar 1 " . (7*$N+20) . " 1>/dev/null 2>&1; \\
            sadf -d /tmp/queuing_bench_sar -- -r > /tmp/queuing_bench_sar_mem.csv; \\
            sadf -d /tmp/queuing_bench_sar -- -u > /tmp/queuing_bench_sar_cpu.csv\"",
        "rm -f $sOutputPath/sar*; sar -ur -o $sOutputPath/sar 1 " . (7*$N+20) . " 1>/dev/null 2>&1; \\
            sadf -d $sOutputPath/sar -- -r > $sOutputPath/sar_mem_local.csv; \\
            sadf -d $sOutputPath/sar -- -u > $sOutputPath/sar_cpu_local.csv"
    ),

    // launch producer 1:
    0    => "$sProducer --duration=" . (4*$N) . " --throughput=" . (3*$L),

    // launch consumer 1:
    $N   => "$sConsumer --duration=" . (5*$N) . " --throughput=" . (4*$L),

    // shut down server 1
    $N+$M => "ssh $sNode1 rabbitmqctl stop",

    // launch producer 2:
    2*$N => "$sProducer --duration=" . (3*$N) . " --throughput=" . (5*$L),

    // restart server 1
    2*$N+$M => "ssh $sNode1 /etc/init.d/rabbitmq-server restart",

    // launch consumer 2:
    3*$N => "$sConsumer --duration=" . (4*$N) . " --throughput=" . (2*$L),

    // shut down server 2:
    4*$N+$M => "ssh $sNode2 rabbitmqctl stop",

    // restart server 1:
    5*$N+$M => "ssh $sNode2 /etc/init.d/rabbitmq-server restart",

    // retrieve memory & cpu metrics:
    7*$N+15 => array(
        "scp $sNode1:/tmp/queuing_bench_sar_mem.csv $sOutputPath/sar_mem_111.csv",
        "scp $sNode1:/tmp/queuing_bench_sar_cpu.csv $sOutputPath/sar_cpu_111.csv",
        "scp $sNode2:/tmp/queuing_bench_sar_mem.csv $sOutputPath/sar_mem_112.csv",
        "scp $sNode2:/tmp/queuing_bench_sar_cpu.csv $sOutputPath/sar_cpu_112.csv",
    ),
);

// Run scenario:
$oLogger = new ColoredIndentedLogger($aConfig['GAubry\Logger\ColoredIndentedLogger']);
$oOrchestrator = new Orchestrator($oLogger);
$oOrchestrator->run($aScenario, $sOutputPath . '/' . $aConfig['AMQP']['log_action_filenames']);

// Generate stats and graphs:
echo "Generating stats:\n";
$sCmd = 'bash ' . "$sScriptsDir/rabbitmq/stats/generate_stats.sh '$sOutputPath' $N $L " . (int)$bIsDurable;
$aResult = Helpers::exec($sCmd);
echo implode("\n", $aResult) . "\n";
