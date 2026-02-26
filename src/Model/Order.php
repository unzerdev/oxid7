<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Model;

use Unzer\UnzerPayment\Traits\ServiceContainer;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\EshopCommunity\Internal\Framework\Database\QueryBuilderFactoryInterface;

class Order extends Order_parent
{
    use ServiceContainer;

    public function getUnzerOrderNr(): string
    {
        $value = $this->getFieldData('oxunzerpayordernr');
        return is_string($value) ? $value : '';
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function setUnzerOrderNr(string $unzerOrderId): string
    {
        /** @var QueryBuilderFactoryInterface $queryBuilderFactory */
        $queryBuilderFactory = $this->getServiceFromContainer(QueryBuilderFactoryInterface::class);

        $queryBuilder = $queryBuilderFactory->create();

        $query = $queryBuilder
            ->update('oxorder')
            ->set("oxunzerpayordernr", ":oxunzerpayordernr")
            ->where("oxid = :oxid");

        $parameters = [
            ':oxunzerpayordernr' => $unzerOrderId,
            ':oxid' => $this->getId()
        ];

        $query->setParameters($parameters)->execute();
        $this->oxorder__oxunzerpayordernr = new Field($unzerOrderId);

        return $unzerOrderId;
    }

}