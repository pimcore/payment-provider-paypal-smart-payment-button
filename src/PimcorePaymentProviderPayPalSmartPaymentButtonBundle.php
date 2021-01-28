<?php

/**
 * Pimcore
 *
 * This source file is available under following license:
 * - Pimcore Enterprise License (PEL)
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     PEL
 */
namespace Pimcore\Bundle\EcommerceFrameworkBundle;

use Pimcore\Bundle\EcommerceFrameworkBundle\PayPalSmartPaymentButton\Installer;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;

class PimcorePaymentProviderPayPalSmartPaymentButtonBundle extends AbstractPimcoreBundle
{
    use PackageVersionTrait;

    /**
     * {@inheritdoc}
     */
    protected function getComposerPackageName()
    {
        return 'pimcore/payment-provider-paypal-smart-payment-button';
    }

    public function getInstaller()
    {
        return $this->container->get(Installer::class);
    }
}
