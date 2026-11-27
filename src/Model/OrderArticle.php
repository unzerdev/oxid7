<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use OxidEsales\Eshop\Core\DatabaseProvider;
use Unzer\UnzerPayment\Service\PreOrderService;

class OrderArticle extends OrderArticle_parent
{
    public function updateArticleStock($dAddAmount, $blAllowNegativeStock = false)
    {
        $orderId = (string) $this->getFieldData('oxorderid');
        if ($orderId && PreOrderService::tableExists()) {
            $state = DatabaseProvider::getDb()->getOne('SELECT state FROM unzer_preorders WHERE oxid = ?', [$orderId]);
            if ($state === 'prepared') {
                return;
            }
        }
        return parent::updateArticleStock($dAddAmount, $blAllowNegativeStock);
    }
}
