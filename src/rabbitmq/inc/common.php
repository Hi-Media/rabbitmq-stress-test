<?php

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPConnection;
use Psr\Log\LoggerInterface;

require(__DIR__ . '/../../inc/bootstrap.php');

/**
 * Create queue with default router.
 *
 * @param AMQPChannel $oChannel
 * @param string $sQueueName
 * @param bool $bIsDurable
 */
function createQueue (AMQPChannel $oChannel, $sQueueName, $bIsDurable)
{
    $oChannel->queue_declare($sQueueName, false, $bIsDurable, false, false, false);
    $oChannel->exchange_declare('router', 'direct', false, $bIsDurable, false);
    $oChannel->queue_bind($sQueueName, 'router');
}

/**
 * Connect to one of cluster's server.
 * Try another server if one is down.
 *
 * @param array $aCluster Rabbitmq cluster: array(
 *     array('ip', port, 'login', 'password'),
 *     …
 * )
 * @param LoggerInterface $oLogger
 * @return null|AMQPConnection
 */
function connect (array $aCluster, LoggerInterface $oLogger)
{
    $oConnection = null;
    $iNode = 0;
    do {
        try {
            $aNode = $aCluster[$iNode];
            $oLogger->info('Connecting to node ' . json_encode($aNode));
            $oConnection = new AMQPConnection($aNode[0], $aNode[1], $aNode[2], $aNode[3]);
            $bIsConnected = true;
        } catch (Exception $oException) {
            if (strpos($oException->getMessage(), 'Connection refused') !== false
                && ++$iNode < count($aCluster)
            ) {
                $bIsConnected = false;
                $oLogger->warning('Connection refused! Trying another node…');
            } else {
                $bIsConnected = false;
                $oLogger->warning('Connection refused! No other node to try… Waiting 200ms before retry the first node.');
                usleep(200000);
                $iNode = 0;
            }
        }
    } while (! $bIsConnected);
    return $oConnection;
}

/**
 * Init connection to Rabbitmq's cluster.
 *
 * @param array $aCluster Rabbitmq cluster: array(
 *     array('ip', port, 'login', 'password'),
 *     …
 * )
 * @param LoggerInterface $oLogger
 * @param string $sQueueName
 * @param bool $bIsDurable
 * @return AMQPChannel
 */
function init (array $aCluster, LoggerInterface $oLogger, $sQueueName, $bIsDurable)
{
    $oConnection = connect($aCluster, $oLogger);
    $oChannel = $oConnection->channel();
    createQueue($oChannel, $sQueueName, $bIsDurable);
    register_shutdown_function('shutdown', $oChannel, $oConnection);
    return $oChannel;
}

/**
 * Close all connections.
 *
 * @param AMQPChannel $oChannel
 * @param AMQPConnection $oConnection
 */
function shutdown (AMQPChannel $oChannel, AMQPConnection $oConnection)
{
    try {
        $oChannel->close();
        $oConnection->close();
    } catch (\Exception $oException) {
        echo $oException->getMessage();
    }
}
