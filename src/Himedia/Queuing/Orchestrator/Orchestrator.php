<?php

namespace Himedia\Queuing\Orchestrator;

use GAubry\Helpers\Helpers;
use Psr\Log\LoggerInterface;

class Orchestrator {

    /**
     * PSR-3 logger.
     * @var LoggerInterface
     */
    private $oLogger;

    public function __construct (LoggerInterface $oLogger)
    {
        $this->oLogger = $oLogger;
    }

    /**
     * Execute given scenario.
     *
     * @param array $aScenario Scenario's description: a list of commands to execute, and when execute them.
     * Timing are array's keys in seconds and are relative to first timing value.
     * Structure: array(
     *     (int)when => (string)Shell command,
     *     (int)when => array(  // List of Shell commands to execute in parallel
     *         (string)Shell command 1,
     *         ...
     *     ),
     *     (int)when => (callback)callback function,
     *     ...
     * )
     * @param string $sLogPathPattern Log path for each scenario's action,
     *   where %1$d is action's timing and %2$d is index of each action's entry.
     */
    public function run (array $aScenario, $sLogPathPattern)
    {
        $iLastTiming = 0;
        $aPids = array();
        foreach ($aScenario as $iTiming => $mActions) {
            $iSleep = max(0, $iTiming - $iLastTiming);
            if ($iSleep > 0) {
                echo "Sleep {$iSleep}s… " . PHP_EOL;
                sleep($iSleep);
            }
            if (is_callable($mActions)) {
                $mActions = $mActions();
            }
            if (! is_array($mActions)) {
                $mActions = array($mActions);
            }
            foreach ($mActions as $idx => $sAction) {
                $this->oLogger->info("Do: $sAction+++");
                $sLogPath = sprintf($sLogPathPattern, $iTiming, $idx);
                $sCmd = "($sAction) > '$sLogPath' 2>&1 & echo \$!";
                $aResult = Helpers::exec($sCmd);
                $this->oLogger->debug("⇒ pid={$aResult[0]}, output: $sLogPath---");
                $aPids[] = $aResult[0];
            }
            $iLastTiming = $iTiming;
        }

        $this->oLogger->info("Waiting end of tasks… (pids={" . implode(', ', $aPids) . "})");
        $sCmd = "for pid in " . implode(' ', $aPids) . "; do
    echo '>>waiting pid '\$pid'<<'
    status=0
    while [ \$status -eq 0 ]; do
        echo -n '.'
        sleep .2
        ps -p \$pid 2>&1 >/dev/null
        status=\$?
    done
    echo ' >> '\$pid' OK <<'
done";
        Helpers::exec($sCmd);
    }
}
