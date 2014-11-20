<?php

use Ulrichsg\Getopt\Getopt;

/**
 * Message generator.
 * 100 000 messages in ~1s.
 *
 * @param string $sPrefixId
 * @return string
 */
function getMessage ($sPrefixId)
{
    static $i = 0;

    return '{"id":"' . substr($sPrefixId, 0, 5) . '-' . str_pad(++$i, 6, '0', STR_PAD_LEFT)
        . '","data":{"name":"' . substr($sPrefixId . $sPrefixId, 17) . '","age":' . (18 + $i%70)
        . ',"timestamp":' . microtime(true) . ',"full_id":"' . $sPrefixId . '"}}';
}

/**
 * Helper for parsing options sent to Ulrichsg\Getopt.
 *
 * @param array $aOptions
 * @return Getopt
 */
function parseOptions (array $aOptions)
{
    $oGetopt = new Getopt($aOptions);
    $oException = null;
    try {
        $oGetopt->parse();
    } catch (Exception $oException) {
        // nothing to do…
    }

    if ($oException !== null) {
        echo $oException . "\n\n";
    }
    if ($oGetopt === null) {
        exit;
    } elseif ($GLOBALS['argc'] == 1 || $oException !== null || $oGetopt->getOption('help') !== null) {
        echo $oGetopt->getHelpText();
        exit;
    } else {
        return $oGetopt;
    }
}
