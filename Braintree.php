<?php
/**
 * @author Anton Tuyakhov <atuyakhov@gmail.com>
 */

namespace tuyakhov\braintree;

use Braintree\Address;
use Braintree\ClientToken;
use Braintree\CreditCard;
use Braintree\Configuration;
use Braintree\Customer;
use Braintree\MerchantAccount;
use Braintree\PaymentMethodNonce;
use Braintree\Plan;
use Braintree\Transaction;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;

class Braintree extends Component
{
    public $environment = 'sandbox';
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $clientSideKey;

    protected $options;

    /**
     * Sets up Braintree configuration from config file.
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        foreach (['merchantId', 'publicKey', 'privateKey', 'environment'] as $attribute) {
            if ($this->$attribute === null) {
                throw new InvalidConfigException(
                    strtr(
                        '"{class}::{attribute}" cannot be empty.',
                        [
                            '{class}' => static::className(),
                            '{attribute}' => '$' . $attribute,
                        ]
                    )
                );
            }
            Configuration::$attribute($this->$attribute);
        }
        $this->clientSideKey = ClientToken::generate();
        parent::init();
    }

    /**
     * Braintree sale function.
     * @param bool|true $submitForSettlement
     * @param bool|true $storeInVaultOnSuccess
     * @return array
     */
    public function singleCharge($submitForSettlement = true, $storeInVaultOnSuccess = true)
    {
        $this->options['options']['submitForSettlement'] = $submitForSettlement;
        $this->options['options']['storeInVaultOnSuccess'] = $storeInVaultOnSuccess;
        $result = Transaction::sale($this->options);

        if ($result->success) {
            return ['status' => true, 'result' => $result];
        } else {
            if ($result->transaction) {
                return ['status' => false, 'result' => $result];
            } else {
                return ['status' => false, 'result' => $result];
            }
        }
    }

    public function saleWithServiceFee($merchantAccountId, $amount, $paymentMethodNonce = null, $serviceFeeAmount)
    {
        $result = Transaction::sale(
            [
                'merchantAccountId' => $merchantAccountId,
                'amount' => $amount,
                'paymentMethodNonce' => $paymentMethodNonce,
                'serviceFeeAmount' => $serviceFeeAmount,
            ]
        );
        return $result;
    }

    public function saleWithPaymentNonce($amount, $paymentMethodNonce)
    {
        $result = Transaction::sale(
            [
                'amount' => $amount,
                'paymentMethodNonce' => $paymentMethodNonce,
                'options' => [
                    'submitForSettlement' => true,
                    'storeInVaultOnSuccess' => true,
                ],
            ]
        );
        return $result;
    }

    public function createPaymentMethodNonce($creditCardToken)
    {
        return PaymentMethodNonce::create($creditCardToken);
    }

    /**
     * Finds transaction by id.
     * @param string $id
     * @return Transaction
     */
    public function findTransaction($id)
    {
        return Transaction::find($id);
    }

    /**
     * This save customer to braintree and returns result array.
     * @return array
     */
    public function saveCustomer()
    {
        if (isset($this->options['customerId'])) {
            $this->options['customer']['id'] = $this->options['customerId'];
        }
        $result = Customer::create($this->options['customer']);

        if ($result->success) {
            return ['status' => true, 'result' => $result];
        } else {
            return ['status' => false, 'result' => $result];
        }
    }

    /**
     * This save credit cart to braintree.
     * @return array
     */
    public function saveCreditCard()
    {
        $sendArray = $this->options['creditCard'];
        if (isset($this->options['billing'])) {
            $sendArray['billingAddress'] = $this->options['billing'];
        }
        if (isset($this->options['customerId'])) {
            $sendArray['customerId'] = $this->options['customerId'];
        }
        $result = CreditCard::create($sendArray);

        if ($result->success) {
            return ['status' => true, 'result' => $result];
        } else {
            return ['status' => false, 'result' => $result];
        }
    }

    public function createCustomerCreditCard($params)
    {
        return CreditCard::create($params)->creditCard;
    }

    public function saveAddress()
    {
        $sendArray = $this->options['billing'];
        if (isset($this->options['customerId'])) {
            $sendArray['customerId'] = $this->options['customerId'];
        }
        $result = Address::create($sendArray);

        if ($result->success) {
            return ['status' => true, 'result' => $result];
        } else {
            return ['status' => false, 'result' => $result];
        }
    }

    /**
     * Constructs the Credit Card array for payment.
     * @param array $values array containing Credit Card values, the following keys are expected:
     *     integer 'number' (required) Credit Card Number
     *     integer 'cvv' (optional) Credit Card Security code
     *     integer 'expirationMonth' (optional) format: MM
     *         (use expirationMonth and expirationYear or expirationDate, not both)
     *     integer 'expirationYear' (optional) format: YYYY
     *         (use expirationMonth and expirationYear or expirationDate, not both)
     *     string 'expirationDate' (optional) format: MM/YYYY
     *         (use expirationMonth and expirationYear or expirationDate, not both)
     *     string 'cardholderName' (optional) the cardholder name associated with the credit card
     */
    public function setCreditCard($values)
    {
        $creditCard = ['number' => $values['number']];
        $optionalParamNames = ['cvv', 'expirationMonth', 'expirationYear', 'expirationDate', 'cardholderName'];
        foreach ($optionalParamNames as $optionalParamName) {
            $optionalValue = ArrayHelper::getValue($values, $optionalParamName);
            if (isset($optionalValue)) {
                $creditCard[$optionalParamName] = $optionalValue;
            }
        }
        $this->options['creditCard'] = $creditCard;
    }

    public function setOptions($values)
    {
        if (!empty($values)) {
            foreach ($values as $key => $value) {
                if ($key == 'amount') {
                    $this->setAmount($values['amount']);
                } elseif ($key == 'creditCard') {
                    $this->setCreditCard($values['creditCard']);
                } else {
                    $this->options[$key] = $value;
                }
            }
        }
    }

    /**
     * Set the amount to charge.
     * @param float $amount no dollar sign needed
     */
    public function setAmount($amount)
    {
        $this->options['amount'] = round($amount, 2);
    }

    public function createMerchant($individualParams, $businessParams, $fundingParams, $tosAccepted, $id = null)
    {
        $params = [
            'individual' => $individualParams,
            'business' => $businessParams,
            'funding' => $fundingParams,
            'tosAccepted' => $tosAccepted,
            'masterMerchantAccountId' => "masterMerchantAccount",
            'id' => $id,
        ];

        return MerchantAccount::create($params);
    }

    /**
     * @return Plan[]
     */
    public static function getAllPlans()
    {
        return Plan::all();
    }

    /**
     * @return array
     */
    public static function getPlanIds()
    {
        $plans = static::getAllPlans();
        $planIds = [];
        foreach ($plans as $plan) {
            $planIds[] = $plan->id;
        }
        return $planIds;
    }

    /**
     * @param string $planId
     * @return Plan|null
     */
    public static function getPlanById($planId)
    {
        $plans = static::getAllPlans();
        foreach ($plans as $plan) {
            if ($plan->id == $planId) {
                return $plan;
            }
        }
        return null;
    }

    /**
     * @param string $idMerchant
     * @return MerchantAccount
     */
    public function findMerchant($idMerchant)
    {
        return MerchantAccount::find($idMerchant);
    }

    /**
     * @param string $idCustomer
     * @return Customer
     */
    public function findCustomer($idCustomer)
    {
        return Customer::find($idCustomer);
    }
}
