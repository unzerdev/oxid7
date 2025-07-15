<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use Monolog\Logger;
use OxidEsales\EshopCommunity\Core\Di\ContainerFacade;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use Unzer\UnzerPayment\Constants\Constants;
use UnzerSDK\Interfaces\DebugHandlerInterface;

class DebugHandler implements DebugHandlerInterface
{
    /** @var Logger */
    protected $logger;

    /**
     * @var array
     */
    private static $loglevels = [
        1 => 'ERROR',
        2 => 'WARNING',
        3 => 'DEBUG'
    ];

    /**
     * loglevel = 1 ... logs only errors
     * loglevel = 2 ... logs more information
     * loglevel = 3 ... debug level, everything logged
     *
     * @var int
     */
    private $loglevel = 1;

    /**
     * @param Logger $moduleLogger
     */
    public function __construct(Logger $moduleLogger)
    {
        $this->logger = $moduleLogger;
        $moduleSettingService = ContainerFacade::get(ModuleSettingServiceInterface::class);
        $loglevelConfig = $moduleSettingService->getString('UnzerPaymentLogLevel', Constants::MODULE_ID);
        foreach (self::$loglevels as $loglevelKey => $loglevel) {
            if ($loglevel == $loglevelConfig) {
                $this->loglevel = $loglevelKey;
            }
        }
    }

    /**
     * @param string $message
     */
    public function log(string $message): void
    {
        $this->logger->info($message);
    }

    /**
     * Add message to Logfile, if level critical also to PS-log table
     *
     * @param $message
     * @param int $loglevel
     * @param \Exception $exception|false
     * @param array $dataarray
     */
    public function addLog(
        $message,
        $loglevel = 3,
        $exception = false,
        $dataarray = []
    ) {
        if ($this->loglevel >= $loglevel) {
            $backtrace = debug_backtrace();
            $fileinfo = '';
            $callsinfo = '';
            if (!empty($backtrace[0]) && is_array($backtrace[0])) {
                $fileinfo = $backtrace[0]['file'] . ": " . $backtrace[0]['line'];
                for ($x = 1; $x < 5; $x++) {
                    if (!empty($backtrace[$x]) && is_array($backtrace[$x])) {
                        $callsinfo .= "\r\n" . $backtrace[$x]['file'] . ": " . $backtrace[$x]['line'];
                    }
                }
            }
            $logstr = date("Y-m-d H:i:s");
            $logstr .= ' [' . self::$loglevels[$loglevel] . '] ';
            $logstr .= $message;
            $logstr .= ' - ' . $fileinfo;
            $logstr .= "\r\n";
            $logstr .= 'URL: ' . $_SERVER['REQUEST_URI'];
            $logstr .= "\r\n";
            if ($callsinfo != '') {
                $logstr .= 'Backtrace :';
                $logstr .= $callsinfo . "\r\n";
            }
            $this->log($logstr);

            if ($exception) {
                $exceptionlog = 'Exception thrown: ';
                $exceptionlog .= $exception->getCode() . ': ' . $exception->getMessage() . ' - ';
                $exceptionlog .= $exception->getFile() . ': ' . $exception->getLine();
                $exceptionlog .= "\r\n";
                $this->log($exceptionlog);
            }
            if (sizeof($dataarray) > 0) {
                $arraylog = 'Data-Array :';
                $arraylog .= "\r\n";
                $arraylog .= print_r($dataarray, true);
                $arraylog .= "\r\n";
                $this->log($arraylog);
            }
        }
    }

}