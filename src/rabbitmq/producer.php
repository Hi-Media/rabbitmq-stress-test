<?php

use GAubry\Logger\ColoredIndentedLogger;
use PhpAmqpLib\Message\AMQPMessage;
use Ulrichsg\Getopt\Getopt;

require(__DIR__ . '/inc/common.php');

// Script's options:
$oGetopt = parseOptions(array(
    array('d',  'duration',   Getopt::REQUIRED_ARGUMENT, 'Test duration in seconds (default 1)',  1),
    array('t',  'throughput', Getopt::REQUIRED_ARGUMENT, 'Nb of messages to send per second (default 0=no limit)', 0),
    array('o',  'output',     Getopt::REQUIRED_ARGUMENT, "Output directory (default '')",         ''),
    array(null, 'is-durable', Getopt::REQUIRED_ARGUMENT, 'Is a durable queue? (1/0, default 1)',  1),
    array(null, 'with-acks',  Getopt::REQUIRED_ARGUMENT, 'Waiting server acks? (1/0, default 1)', 1),
    array(null, 'queue-name', Getopt::REQUIRED_ARGUMENT, "Name of the queue (default 'noname')",  'noname'),
    array('h',  'help',       Getopt::NO_ARGUMENT,       'Display this help'),
));

$iDuration   = (int)$oGetopt->getOption('duration');
$iThroughput = (int)$oGetopt->getOption('throughput') ?: 0;
$sOutputDir  = $oGetopt->getOption('output');
$bIsDurable  = (bool)$oGetopt->getOption('is-durable');
$bWithAcks   = (bool)$oGetopt->getOption('with-acks');
$sQueueName  = $oGetopt->getOption('queue-name');

// End of script's options ----------------------------------------------------



// Init variables:
if (empty($sOutputDir)) {
    $rOutput = null;
} else {
    if (! file_exists($sOutputDir)) {
        mkdir($sOutputDir, 0777, true);
    }
    $sOutputPath = $sOutputDir . '/' . sprintf($aConfig['AMQP']['csv_produced_messages_id_filename'], getmypid());
    $rOutput = fopen($sOutputPath, 'w');
}
shuffle($aConfig['AMQP']['cluster_rabbitmq']);
$oLogger = new ColoredIndentedLogger($aConfig['GAubry\Logger\ColoredIndentedLogger']);
$oChannel = init($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable);

echo 'Sending ' . ($iThroughput > 0 ? "$iThroughput msg/s" : 'messages')
    . " during {$iDuration}s, "
    . ($bWithAcks ? 'with acks' : 'w/o acks') . ".\n"
    . (empty($sOutputDir) ? 'No output file' : "Output file: $sOutputPath") . ".\n";

// Set ack callback if needed:
$iAckedMsgs = 0;
if ($bWithAcks) {
    $oChannel->set_ack_handler(
        function (AMQPMessage $message) use ($rOutput, &$iAckedMsgs) {
            $iAckedMsgs++;
            if ($rOutput !== null) {
                fwrite($rOutput, date('H:i:s', microtime(true)) . ', ACK ' . substr($message->body, 7, 12) . "\n");
            }
        }
    );
    $oChannel->confirm_select();
}

$oMsg = new AMQPMessage('', array('content_type' => 'text/plain', 'delivery_mode' => 2));
$iNbMsgs = 0;
$sMsgPrefixId = md5(microtime(true).rand());

// Align process on next starting second:
echo "Waiting next second…\n";
$fNextSecond = ceil(microtime(true) + 1);
usleep(($fNextSecond-microtime(true)) * 1000000);
$fT0 = microtime(true);
$tLastPendingAcks = $fT0;

// Send messages during $iDuration seconds:
do {

    // Without throughput :
    if ($iThroughput === 0) {
        $sMsg = getMessage($sMsgPrefixId);
        $oMsg->body = $sMsg;
        try {
            $oChannel->basic_publish($oMsg, 'router');
        } catch (Exception $oException) {
            $oLogger->warning('Broken pipe or closed connection!');
            $oChannel = init($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable);
            $oChannel->basic_publish($oMsg, 'router');
        }

        $iNbMsgs++;
        if ($rOutput !== null) {
            fwrite($rOutput, date('H:i:s', microtime(true)) . ',' . substr($sMsg, 7, 12) . "\n");
        }

    // With throughtput :
    } else {
        $fLoopStart = microtime(true);
        for($i=0; $i<$iThroughput; $i++) {
            $sMsg = getMessage($sMsgPrefixId);
            $oMsg->body = $sMsg;
            try {
                $oChannel->basic_publish($oMsg, 'router');
            } catch (Exception $oException) {
                $oLogger->warning('Broken pipe or closed connection!');
                $oChannel = init($aConfig['AMQP']['cluster_rabbitmq'], $oLogger, $sQueueName, $bIsDurable);
                $oChannel->basic_publish($oMsg, 'router');
            }
            if ($rOutput !== null) {
                fwrite($rOutput, date('H:i:s', microtime(true)) . ',' . substr($sMsg, 7, 12) . "\n");
            }
        }
        $iNbMsgs += $iThroughput;

        $fNow = microtime(true);
        $fSleepms = ceil(max(0, ceil($fLoopStart) - $fNow) * 1000);
        echo date('H:i:s', $fNow) . ": $iNbMsgs messages sent (+$iThroughput), sleep " . $fSleepms . " ms\n";
        usleep($fSleepms * 1000);
    }

    // Need ack?
    $fNow = microtime(true);
    if ($bWithAcks && $fNow - $tLastPendingAcks > 1) {
        $tLastPendingAcks = $fNow;
        $oChannel->wait_for_pending_acks();
    }

} while ($fNow - $fT0 < $iDuration);
if ($bWithAcks) {
    $oChannel->wait_for_pending_acks();
}

// Results:
$fElapsedTime = microtime(true)-$fT0;
$sElapsedTime = round($fElapsedTime, 3);
$iMsgPerS = round($iNbMsgs/$fElapsedTime);
echo PHP_EOL . "$iNbMsgs sent messages ($iAckedMsgs acked) in $sElapsedTime seconds ⇒ ~$iMsgPerS msg/s" . PHP_EOL;
