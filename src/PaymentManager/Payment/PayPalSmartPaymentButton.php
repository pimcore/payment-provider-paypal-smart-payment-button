<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\Payment;

use GuzzleHttp;
use GuzzleHttp\Utils;
use Pimcore\Bundle\EcommerceFrameworkBundle\EnvironmentInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\Exception\InvalidConfigException;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\Currency;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\OrderAgentInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\Status;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\StatusInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\PaymentInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentRequest\AbstractRequest;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentResponse\JsonResponse;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentResponse\StartPaymentResponseInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\PriceInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PayPalSmartPaymentButton extends AbstractPayment implements PaymentInterface
{
    const CAPTURE_STRATEGY_MANUAL = 'manual';
    const CAPTURE_STRATEGY_AUTOMATIC = 'automatic';

    const API_SANDBOX_BASE = 'api-m.sandbox.paypal.com';
    const API_LIVE_BASE = 'api.paypal.com';

    const GET_ORDER_URL = '/v2/checkout/orders/%s';
    const POST_ORDER_CREATE_URL = '/v2/checkout/orders';
    const POST_ORDER_CAPTURE_URL = '/v2/checkout/orders/%s/capture';
    /**
     * @var GuzzleHttp\Client
     */
    protected $payPalHttpClient;

    protected string $clientId;

    protected string $accessToken;

    /**
     * @var array
     */
    protected $applicationContext = [];

    /**
     * @var string
     */
    protected $captureStrategy = self::CAPTURE_STRATEGY_MANUAL;

    /**
     * @var EnvironmentInterface
     */
    protected $environment;

    protected $authorizedData;

    public function __construct(array $options, EnvironmentInterface $environment)
    {
        $this->processOptions(
            $this->configureOptions(new OptionsResolver())->resolve($options)
        );

        $this->environment = $environment;
    }

    /**
     * @return EnvironmentInterface
     */
    public function getEnvironment(): EnvironmentInterface
    {
        return $this->environment;
    }

    /**
     * @param EnvironmentInterface $environment
     */
    public function setEnvironment(EnvironmentInterface $environment): void
    {
        $this->environment = $environment;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'PayPalSmartButton';
    }

    /**
     * Creates a new PayPal Order
     */
    public function createOrder(PriceInterface $price, array $config): mixed
    {
        // check params
        $required = [
            'return_url' => null,
            'cancel_url' => null,
            'OrderDescription' => null,
            'InternalPaymentId' => null,
        ];

        $config = array_intersect_key($config, $required);

        if (count($required) != count($config)) {
            throw new \Exception(sprintf('required fields are missing! required: %s', implode(', ', array_keys(array_diff_key($required, $config)))));
        }

        $body = $this->buildRequestBody($price, $config);

        $response = $this->payPalHttpClient->post(self::POST_ORDER_CREATE_URL, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'json' => $body
        ]);

        return $response->getBody()->getContents();
    }

    protected function buildRequestBody(PriceInterface $price, array $config)
    {
        $applicationContext = $this->applicationContext;
        if ($config['return_url']) {
            $applicationContext['return_url'] = $config['return_url'];
        }
        if ($config['cancel_url']) {
            $applicationContext['cancel_url'] = $config['cancel_url'];
        }

        $requestBody = [
            'intent' => 'CAPTURE',
            'application_context' => $applicationContext,
            'purchase_units' => [
                [
                    'custom_id' => $config['InternalPaymentId'],
                    'description' => $config['OrderDescription'],
                    'amount' => [
                        'currency_code' => $price->getCurrency()->getShortName(),
                        'value' => $price->getGrossAmount()->asString(2),
                    ],
                ],
            ],
        ];

        return $requestBody;
    }

    /**
     * @inheritDoc
     */
    public function startPayment(OrderAgentInterface $orderAgent, PriceInterface $price, AbstractRequest $config): StartPaymentResponseInterface
    {
        $result = $this->createOrder($price, $config->asArray());

        if ($result instanceof \stdClass) {
            if ($json = json_encode($result)) {
                return new JsonResponse($orderAgent->getOrder(), $json);
            }
        }

        json_decode($result);
        if (json_last_error() == JSON_ERROR_NONE) {
            return new JsonResponse($orderAgent->getOrder(), $result);
        }

        throw new \Exception('The created order is neither stdClass nor JSON');
    }

    /**
     * Handles response of payment provider and creates payment status object
     *
     * @throws GuzzleHttp\Exception\GuzzleException
     */
    public function handleResponse(StatusInterface | array $response): StatusInterface
    {
        // check required fields
        $required = [
            'orderID' => null,
            'payerID' => null,
        ];

        $authorizedData = [
            'orderID' => null,
            'payerID' => null,
            'email_address' => null,
            'given_name' => null,
            'surname' => null,
        ];

        // check fields
        $response = array_intersect_key($response, $required);
        if (count($required) != count($response)) {
            throw new \Exception(sprintf(
                'required fields are missing! required: %s',
                implode(', ', array_keys(array_diff_key($required, $response)))
            ));
        }

        $orderId = $response['orderID'];

        $getOrder = $this->payPalHttpClient->get(sprintf(self::GET_ORDER_URL, urlencode($orderId)));

        /** @var object $statusResponse */
        $statusResponse = Utils::jsonDecode($getOrder->getBody());

        // handle
        $authorizedData = array_intersect_key($response, $authorizedData);
        $authorizedData['email_address'] = $statusResponse->payer->email_address;
        $authorizedData['given_name'] = $statusResponse->payer->name->given_name;
        $authorizedData['surname'] = $statusResponse->payer->name->surname;
        $this->setAuthorizedData($authorizedData);

        switch ($this->captureStrategy) {

            case self::CAPTURE_STRATEGY_MANUAL:

                return new Status(
                    $statusResponse->purchase_units[0]->custom_id,
                    $response['orderID'],
                    '',
                    $statusResponse->status == 'APPROVED' ? StatusInterface::STATUS_AUTHORIZED : StatusInterface::STATUS_CANCELLED,
                    [
                    ]
                );

            case self::CAPTURE_STRATEGY_AUTOMATIC:
                return $this->executeDebit();

            default:
                throw new InvalidConfigException("Unknown capture strategy '" . $this->captureStrategy . "'");
        }
    }

    /**
     * Returns the authorized data from payment provider
     *
     * @return array
     */
    public function getAuthorizedData(): array
    {
        return $this->authorizedData;
    }

    /**
     * Set authorized data from payment provider
     *
     * @param array $authorizedData
     */
    public function setAuthorizedData(array $authorizedData): void
    {
        $this->authorizedData = $authorizedData;
    }

    /**
     * Executes payment
     *
     * @param PriceInterface $price
     * @param string $reference
     *
     * @return StatusInterface
     */
    public function executeDebit(?PriceInterface $price = null, ?string $reference = null): StatusInterface
    {
        if (null !== $price) {
            throw new \Exception('Setting other price than defined in Order not supported by paypal api');
        }

        $orderId = $this->getAuthorizedData()['orderID'];
        $orderCapture = $this->payPalHttpClient->post(sprintf(self::POST_ORDER_CAPTURE_URL, urlencode($orderId)));

        /** @var object $statusResponse */
        $statusResponse = Utils::jsonDecode($orderCapture->getBody());

        return new Status(
            $statusResponse->purchase_units[0]->payments->captures[0]->custom_id,
            $orderId,
            '',
            $statusResponse->status == 'COMPLETED' ? StatusInterface::STATUS_CLEARED : StatusInterface::STATUS_CANCELLED,
            [
                'transactionId' => $statusResponse->purchase_units[0]->payments->captures[0]->id,
            ]
        );
    }

    /**
     * Executes credit
     *
     * @param PriceInterface $price
     * @param string $reference
     * @param string $transactionId
     *
     * @return StatusInterface
     */
    public function executeCredit(PriceInterface $price, string $reference, string $transactionId): StatusInterface
    {
        throw new \Exception('not implemented');
    }

    protected function configureOptions(OptionsResolver $resolver): OptionsResolver
    {
        parent::configureOptions($resolver);

        $resolver->setRequired([
            'mode',
            'client_id',
            'client_secret',
            'shipping_preference',
            'user_action',
            'capture_strategy',
        ]);

        $resolver
            ->setDefault('mode', 'sandbox')
            ->setAllowedValues('mode', ['sandbox', 'production'])
            ->setDefault('shipping_preference', 'NO_SHIPPING')
            ->setAllowedValues('shipping_preference', ['GET_FROM_FILE', 'NO_SHIPPING', 'SET_PROVIDED_ADDRESS'])
            ->setDefault('user_action', 'PAY_NOW')
            ->setAllowedValues('user_action', ['CONTINUE', 'PAY_NOW'])
            ->setDefault('capture_strategy', self::CAPTURE_STRATEGY_AUTOMATIC)
            ->setAllowedValues('capture_strategy', [self::CAPTURE_STRATEGY_AUTOMATIC, self::CAPTURE_STRATEGY_MANUAL]);

        $notEmptyValidator = function ($value) {
            return !empty($value);
        };

        foreach ($resolver->getRequiredOptions() as $requiredProperty) {
            $resolver->setAllowedValues($requiredProperty, $notEmptyValidator);
        }

        return $resolver;
    }

    protected function processOptions(array $options): void
    {
        parent::processOptions($options);
        $this->clientId = $options['client_id'];
        $this->applicationContext = [
            'shipping_preference' => $options['shipping_preference'],
            'user_action' => $options['user_action'],
        ];
        $this->captureStrategy = $options['capture_strategy'];

        $this->accessToken = $this->getAccessToken($options['client_secret'], $options['mode']);
        $this->payPalHttpClient = $this->buildPayPalClient($options['mode']);
    }

    /**
     * @param string $mode
     *
     * @return GuzzleHttp\Client
     */
    protected function buildPayPalClient(string $mode = 'sandbox'): GuzzleHttp\Client
    {
        $apiBaseUrl = self::API_LIVE_BASE;
        if ($mode === 'sandbox') {
            $apiBaseUrl = self::API_SANDBOX_BASE;
        }

        return new GuzzleHttp\Client([
            'base_uri' => 'https://'.$apiBaseUrl,
            'headers' => [
                'Authorization' => 'Bearer '.$this->accessToken,
                'Content-type' => 'application/json'
            ]
        ]);
    }

    /**
     * @return string
     *
     * @throws \Exception
     */
    protected function getAccessToken(string $clientSecret, string $mode = 'sandbox'): string
    {
        $apiBaseUrl = self::API_LIVE_BASE;
        if ($mode === 'sandbox') {
            $apiBaseUrl = self::API_SANDBOX_BASE;
        }

        $tokenClient = new GuzzleHttp\Client();
        $response = $tokenClient->post('https://' . $this->clientId . ':' . $clientSecret . '@' . $apiBaseUrl . '/v1/oauth2/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
            'header' => [
                'content_type' => 'application/x-www-form-urlencoded'
            ]
        ]);

        /** @var array $response */
        $response = Utils::jsonDecode($response->getBody()->getContents(), true);

        if (!isset($response['access_token'])) {
            throw new \Exception($response['error_description'] . ' check PayPal configuration');
        }

        return $response['access_token'];
    }

    /**
     * @param Currency|null $currency
     *
     * @return string
     */
    public function buildPaymentSDKLink(Currency $currency = null)
    {
        if (null === $currency) {
            $currency = $this->getEnvironment()->getDefaultCurrency();
        }

        return 'https://www.paypal.com/sdk/js?client-id=' . $this->clientId . '&currency=' . $currency->getShortName();
    }
}
