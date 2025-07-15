<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Core\Config;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\BasicContext;
use Webmozart\PathUtil\Path;

class Context extends BasicContext
{
    /** @var Config */
    protected $shopConfig;

    /**
     * @param Config $shopConfig
     */
    public function __construct(Config $shopConfig)
    {
        $this->shopConfig = $shopConfig;
    }

    /**
     * @return string
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function getUnzerLogFilePath(): string
    {
        return Path::join([
            $this->shopConfig->getLogsDir(),
            'unzer',
            $this->getUnzerLogFileName()
        ]);
    }

    /**
     * @return string
     */
    private function getUnzerLogFileName(): string
    {
        return "unzerpayment_" . date("Y-m-d") . ".log";
    }

    /**
     * @return int
     */
    public function getCurrentShopId(): int
    {
        return $this->shopConfig->getShopId();
    }

    /**
     * @return string
     */
    public function getActiveCurrencyName(): string
    {
        return $this->shopConfig->getActShopCurrencyObject()->name;
    }

    /**
     * @return string
     */
    public function getActiveCurrencySign(): string
    {
        return $this->shopConfig->getActShopCurrencyObject()->sign;
    }
}