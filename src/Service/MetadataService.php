<?php

namespace Unzer\UnzerPayment\Service;

use OxidEsales\Eshop\Core\ShopVersion;
use OxidEsales\Facts\Facts;
use Unzer\UnzerPayment\Constants\Constants;

class MetadataService
{

    public function getMetadata($is_express = 0)
    {
        $oModule = oxNew('oxModule');
        $oModule->load(Constants::MODULE_ID);

        $metadata = new \UnzerSDK\Resources\Metadata();
        $metadata->setShopType('Oxid eShop ' . (new Facts())->getEdition());
        $metadata->setShopVersion(ShopVersion::getVersion());
        $metadata->addMetadata('pluginType', 'unzerdev/oxid7');
        $metadata->addMetadata('pluginVersion', $oModule->getInfo('version'));
        $metadata->addMetadata('isExpress', (int)$is_express);
        return $metadata;
    }

}