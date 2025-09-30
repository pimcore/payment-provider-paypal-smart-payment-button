<?php

/**
 * This source file is available under the terms of the
 * Pimcore Open Core License (POCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (https://www.pimcore.com)
 *  @license    Pimcore Open Core License (POCL)
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\PayPalSmartPaymentButton;

use Pimcore\Bundle\EcommerceFrameworkBundle\Tools\PaymentProviderInstaller;

class Installer extends PaymentProviderInstaller
{
    protected string $bricksPath = __DIR__ . '/../../install/objectbrick_sources/';

    protected array $bricksToInstall = [
        'PaymentProviderPayPalSmartButton' => 'objectbrick_PaymentProviderPayPalSmartButton_export.json',
    ];
}
