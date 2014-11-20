<?php

use GAubry\Logger\ColoredIndentedLogger;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;
use Ulrichsg\Getopt\Getopt;

require(__DIR__ . '/inc/common.php');

// Script's options:
$oGetopt = parseOptions(array(
    array('d',  'duration',   Getopt::REQUIRED_ARGUMENT, 'Test duration in seconds (default 1)', 1),
    array('t',  'throughput', Getopt::REQUIRED_ARGUMENT, 'Nb of messages to send per second (default 0=no limit)', 0),
    array('o',  'output',     Getopt::REQUIRED_ARGUMENT, "Output directory (default '')",        ''),
    array(null, 'is-durable', Getopt::REQUIRED_ARGUMENT, 'Is a durable queue? (1/0, default 1)', 1),
    array(null, 'queue-name', Getopt::REQUIRED_ARGUMENT, "Name of the queue (default 'noname')", 'noname'),
    array('h',  'help',       Getopt::NO_ARGUMENT,       'Display this help'),
));

$iDuration   = (int)$oGetopt->getOption('duration');
$iThroughput = (int)$oGetopt->getOption('throughput') ?: 0;
$bIsDurable  = (bool)$oGetopt->getOption('is-durable');
$sOutputDir  = $oGetopt->getOption('output');
$sQueueName  = $oGetopt->getOption('queue-name');

// End of script's options ---------------------------------------------------



// Init variables:
if (empty($sOutputDir)) {
    $rOutput = null;
} else {
    if (!file_exists($sOutputDir)) {
        mkdir($sOutputDir, 0777, true);
    }
    $sOutputPath = $sOutputDir . '/' . sprintf($aConfig['AMQP']['csv_consumed_messages_id_filename'], getmypid());
    $rOutput     = fopen($sOutputPath, 'w');
}
shuffle($aConfig['AMQP']['cluster_rabbitmq']);
$oLogger = new ColoredIndentedLogger($aConfig['GAubry\Logger\ColoredIndentedLogger']);
$oChannel = initConsumer($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable, 'callback');

echo 'Consuming ' . ($iThroughput > 0 ? "up to $iThroughput msg/s" : 'messages')
    . " during {$iDuration}s.\n"
    . (empty($sOutputDir) ? 'No output file' : "Output file: $sOutputPath") . ".\n";

// Align process on next starting second:
echo "Waiting next second…\n";
$fNextSecond = ceil(microtime(true) + 1);
usleep(($fNextSecond-microtime(true)) * 1000000);

$fT0 = $fNow = $fLastDisplay = microtime(true);
$iNbMsgs = $iLastTotalNbMsgs = 0;

// Consuming messages during $iDuration seconds:
do {

    // Without throughput :
    if ($iThroughput === 0) {
        try {
            $oChannel->wait(null, false, 1);
        } catch (AMQPTimeoutException $oException) {
            echo 'Timeout, empty queue?' . PHP_EOL;
        } catch (Exception $oException) {
            var_dump($oException->getMessage());
            $oLogger->warning('Broken pipe or closed connection!');
            $oChannel = initConsumer($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable, 'callback');
        }

    // With throughtput :
    } else {
        $fLoopStart = microtime(true);
        while ($iNbMsgs-$iLastTotalNbMsgs < $iThroughput && microtime(true) < ceil($fLoopStart)) {
            try {
                $oChannel->wait(null, false, 1);
            } catch (AMQPTimeoutException $oException) {
                echo 'Timeout, empty queue?' . PHP_EOL;
            } catch (Exception $oException) {
                var_dump($oException->getMessage());
                $oLogger->warning('Broken pipe or closed connection!');
                $oChannel = initConsumer($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable, 'callback');
            }
        }

        $fNow = microtime(true);
        $fSleepms = ceil(max(0, ceil($fLoopStart) - $fNow) * 1000);
        $iMsgRate = ($iNbMsgs - $iLastTotalNbMsgs);
        $iLastTotalNbMsgs = $iNbMsgs;
        echo date('H:i:s', $fNow) . ": $iNbMsgs messages consumed (+$iMsgRate), sleep " . $fSleepms . " ms\n";
        usleep($fSleepms * 1000);
    }

    $fNow = microtime(true);
} while ($fNow - $fT0 < $iDuration);

// Results:
$fElapsedTime = microtime(true)-$fT0;
$sElapsedTime = round($fElapsedTime, 3);
$iMsgPerS = round($iNbMsgs/$fElapsedTime);
echo PHP_EOL . "$iNbMsgs consumed messages in $sElapsedTime seconds ⇒ ~$iMsgPerS msg/s" . PHP_EOL;

/**
 * Init connection to Rabbitmq's cluster.
 *
 * @param array $aCluster
 * @param LoggerInterface $oLogger
 * @param string $sQueueName
 * @param bool $bIsDurable
 * @param callable $callback
 * @return \PhpAmqpLib\Channel\AMQPChannel
 */
function initConsumer (array $aCluster, LoggerInterface $oLogger, $sQueueName, $bIsDurable, $callback)
{
    $oChannel = init($aCluster, $oLogger, $sQueueName, $bIsDurable);
    $oChannel->basic_qos(null, 40, null);
    $oChannel->basic_consume($sQueueName, 'consumer-' . getmygid(), false, false, false, false, $callback);
    return $oChannel;
}

/**
 * Callback called on each received message.
 *
 * @param $oMsg
 */
function callback ($oMsg) {
    global $iNbMsgs, $rOutput;
    $iNbMsgs++;
    $oMsg->delivery_info['channel']->basic_ack($oMsg->delivery_info['delivery_tag']);
    if ($rOutput !== null) {
        fwrite($rOutput, date('H:i:s', microtime(true)) . ',' . substr($oMsg->body, 7, 12) . "\n");
    }
};
