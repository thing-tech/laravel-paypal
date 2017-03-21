<?php

namespace Srmklive\PayPal\Traits;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException as HttpBadResponseException;
use GuzzleHttp\Exception\ClientException as HttpClientException;
use GuzzleHttp\Exception\ServerException as HttpServerException;
use Illuminate\Support\Collection;

trait PayPalRequest
{
    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $post;

    /**
     * @var array
     */
    private $config;

    /**
     * @var string
     */
    private $currency;

    /**
     * @var array
     */
    private $options;

    /**
     * @var string
     */
    private $paymentAction;

    /**
     * @var string
     */
    private $locale;

    /**
     * @var string
     */
    private $notifyUrl;

    /**
     * Function To Set PayPal API Configuration.
     *
     * @return void
     */
    private function setConfig()
    {
        // Setting Http Client
        $this->client = $this->setClient();

        // Set Api Credentials
        if (function_exists('config')) {
            $this->setApiCredentials(
                config('paypal')
            );
        }

        $this->setRequestData();
    }

    /**
     * Function to initialize Http Client.
     *
     * @return HttpClient
     */
    protected function setClient()
    {
        return new HttpClient([
            'curl' => [
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ]);
    }

    /**
     * Set PayPal API Credentials.
     *
     * @param array  $credentials
     * @param string $mode
     *
     * @throws \Exception
     *
     * @return void
     */
    public function setApiCredentials($credentials, $mode = '')
    {
        // Setting Default PayPal Mode If not set
        if (empty($credentials['mode']) ||
            (!in_array($credentials['mode'], ['sandbox', 'live']))
        ) {
            $credentials['mode'] = 'live';
        }

        // Setting default mode.
        if (empty($mode)) {
            $mode = $credentials['mode'];
        }

        // Setting PayPal API Credentials
        foreach ($credentials[$mode] as $key => $value) {
            $this->config[$key] = $value;
        }

        // Setup PayPal API Signature value to use.
        if (!empty($this->config['secret'])) {
            $this->config['signature'] = $this->config['secret'];
        } else {
            $this->config['signature'] = file_get_contents($this->config['certificate']);
        }

        if ($this instanceof \Srmklive\PayPal\Services\AdaptivePayments) {
            $this->setAdaptivePaymentsOptions($mode);
        } elseif ($this instanceof \Srmklive\PayPal\Services\ExpressCheckout) {
            $this->setExpressCheckoutOptions($credentials, $mode);
        } else {
            throw new \Exception('Invalid api credentials provided for PayPal!. Please provide the right api credentials.');
        }

        // Set default currency.
        $this->setCurrency($credentials['currency']);

        // Set default payment action.
        $this->paymentAction = !empty($this->config['payment_action']) ?
            $this->config['payment_action'] : 'Sale';

        // Set default locale.
        $this->locale = !empty($this->config['locale']) ?
            $this->config['locale'] : 'en_US';

        // Set PayPal IPN Notification URL
        $this->notifyUrl = $credentials['notify_url'];
    }

    /**
     * Setup request data to be sent to PayPal.
     *
     * @param array $data
     *
     * @return \Illuminate\Support\Collection
     */
    protected function setRequestData(array $data = [])
    {
        if (($this->post instanceof Collection) && ($this->post->isNotEmpty())) {
            unset($this->post);
        }

        $this->post = new Collection($data);

        return $this->post;
    }

    /**
     * Set other/override PayPal API parameters.
     *
     * @param array $options
     *
     * @return $this
     */
    public function addOptions(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Function to set currency.
     *
     * @param string $currency
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function setCurrency($currency = 'USD')
    {
        $allowedCurrencies = ['AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD', 'RUB'];

        // Check if provided currency is valid.
        if (!in_array($currency, $allowedCurrencies)) {
            throw new \Exception('Currency is not supported by PayPal.');
        }

        $this->currency = $currency;

        return $this;
    }

    /**
     * Retrieve PayPal IPN Response.
     *
     * @param array $post
     *
     * @return array
     */
    public function verifyIPN($post)
    {
        $this->setRequestData($post);

        return $this->doPayPalRequest('verifyipn');
    }

    /**
     * Refund PayPal Transaction.
     *
     * @param string $transaction
     *
     * @return array
     */
    public function refundTransaction($transaction)
    {
        $this->setRequestData([
            'TRANSACTIONID' => $transaction,
        ]);

        return $this->doPayPalRequest('RefundTransaction');
    }

    /**
     * Search Transactions On PayPal.
     *
     * @param array $post
     *
     * @return array
     */
    public function searchTransactions($post)
    {
        $this->setRequestData($post);

        return $this->doPayPalRequest('TransactionSearch');
    }

    /**
     * Function To Perform PayPal API Request.
     *
     * @param string $method
     *
     * @throws \Exception
     *
     * @return array|\Psr\Http\Message\StreamInterface
     */
    private function doPayPalRequest($method)
    {
        // Check configuration settings. Reset them if empty.
        if (empty($this->config)) {
            self::setConfig();
        }

        // Throw exception if configuration is still not set.
        if (empty($this->config)) {
            throw new \Exception('PayPal api settings not found.');
        }

        // Setting API Credentials, Version & Method
        $this->post->merge([
            'USER'      => $this->config['username'],
            'PWD'       => $this->config['password'],
            'SIGNATURE' => $this->config['signature'],
            'VERSION'   => 123,
            'METHOD'    => $method,
        ]);

        // Checking Whether The Request Is PayPal IPN Response
        if ($method == 'verifyipn') {
            $this->post = $this->post->filter(function ($value, $key) {
                if ($key !== 'METHOD') {
                    return $value;
                }
            });

            $post_url = $this->config['gateway_url'].'/cgi-bin/webscr';
        } else {
            $post_url = $this->config['api_url'];
        }

        // Merge $options array if set.
        if (!empty($this->options)) {
            $this->post->merge($this->options);
        }

        try {
            $request = $this->client->post($post_url, [
                'form_params' => $this->post->toArray(),
            ]);

            $response = $request->getBody(true);

            return ($method == 'verifyipn') ? $response : $this->retrieveData($response);
        } catch (HttpClientException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        } catch (HttpServerException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        } catch (HttpBadResponseException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        return [
            'type'      => 'error',
            'message'   => $message,
        ];
    }

    /**
     * Parse PayPal NVP Response.
     *
     * @param string $request
     * @param array  $response
     *
     * @return array
     */
    private function retrieveData($request, array $response = null)
    {
        parse_str($request, $response);

        return $response;
    }
}
