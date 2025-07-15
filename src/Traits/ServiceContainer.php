<?php

declare(strict_types=1);

namespace Unzer\UnzerPayment\Traits;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

trait ServiceContainer
{
    /**
     * @template T
     * @param class-string<T> $serviceName
     * @return T
     */
    protected function getServiceFromContainer(string $serviceName)
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get($serviceName);
    }
}