<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Controller\Admin;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Exception\DatabaseConnectionException;
use OxidEsales\Eshop\Core\Registry;

class OrderList extends OrderList_parent
{
    protected function prepareWhereQuery($whereQuery, $fullQuery)
    {
        $orderNrSearch = '';
        if (isset($whereQuery['oxorder.oxordernr'])) {
            $orderNrSearch = $whereQuery['oxorder.oxordernr'];
            unset($whereQuery['oxorder.oxordernr']);
        }

        $database = DatabaseProvider::getDb();
        $query = parent::prepareWhereQuery($whereQuery, $fullQuery);

        // glue oxordernr
        if ($orderNrSearch) {
            $oxOrderNr = $database->quoteIdentifier("oxorder.oxordernr");
            $oxUnzerOrderNr = $database->quoteIdentifier("oxorder.oxunzerpayordernr");
            $orderNrValue = $database->quote($orderNrSearch);
            $query .= " and ({$oxOrderNr} like {$orderNrValue} or {$oxUnzerOrderNr} like {$orderNrValue}) ";
        }

        return $query;
    }
}
