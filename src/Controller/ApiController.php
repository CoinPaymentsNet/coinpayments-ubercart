<?php

namespace Drupal\uc_coinpayments\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use GuzzleHttp\RequestOptions;

/**
 * Class ApiController
 * @package Drupal\commerce_coinpayments\Controller
 */
class ApiController
{

  const API_URL = 'https://api.coinpayments.net';
  const CHECKOUT_URL = 'https://checkout.coinpayments.net';
  const API_VERSION = '1';

  const API_SIMPLE_INVOICE_ACTION = 'invoices';
  const API_WEBHOOK_ACTION = 'merchant/clients/%s/webhooks';
  const API_MERCHANT_INVOICE_ACTION = 'merchant/invoices';
  const API_CURRENCIES_ACTION = 'currencies';
  const API_CHECKOUT_ACTION = 'checkout';
  const FIAT_TYPE = 'fiat';

  const PAID_EVENT = 'Paid';
  const CANCELLED_EVENT = 'Cancelled';

  protected $client_id;
  protected $client_secret;
  protected $webhooks;

  /**
   * ApiController constructor.
   * @param $client_id
   * @param bool $webhooks
   * @param bool $client_secret
   */
  public function __construct($client_id, $webhooks = false, $client_secret = false)
  {
    $this->client_id = $client_id;
    $this->webhooks = $webhooks;
    $this->client_secret = $client_secret;
  }

  /**
   * @return bool
   * @throws Exception
   */
  public function checkWebhook()
  {
    $exists = false;
    $webhooks_list = $this->getWebhooksList();
    if (!empty($webhooks_list)) {
      $webhooks_urls_list = array();
      if (!empty($webhooks_list['items'])) {
        $webhooks_urls_list = array_map(function ($webHook) {
          return $webHook['notificationsUrl'];
        }, $webhooks_list['items']);
      }
      if (
        in_array($this->getNotificationUrl(self::PAID_EVENT), $webhooks_urls_list) &&
        in_array($this->getNotificationUrl(self::CANCELLED_EVENT), $webhooks_urls_list)
      ) {
        $exists = true;
      } else {
        if (
          !empty($this->createWebHook(self::PAID_EVENT)) &&
          !empty($this->createWebHook(self::CANCELLED_EVENT))
        ) {
          $exists = true;
        }
      }
    }

    return $exists;
  }

  /**
   * @return bool|mixed
   * @throws Exception
   */
  public function getWebhooksList()
  {

    $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

    return $this->sendRequest('GET', $action, $this->client_id, null, $this->client_secret);
  }

  /**
   * @param $event
   * @return bool|mixed
   */
  public function createWebHook($event)
  {

    $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

    $params = array(
      "notificationsUrl" => $this->getNotificationUrl($event),
      "notifications" => [
        sprintf("invoice%s", $event),
      ],
    );

    return $this->sendRequest('POST', $action, $this->client_id, $params, $this->client_secret);
  }

  /**
   * @param $invoice_params
   * @return bool|mixed
   * @throws Exception
   */
  public function createInvoice($invoice_params)
  {

    if ($this->webhooks) {
      $action = self::API_MERCHANT_INVOICE_ACTION;
      $secret = $this->client_secret;
    } else {
      $action = self::API_SIMPLE_INVOICE_ACTION;
      $secret = false;
    }

    $params = array(
      'clientId' => $this->client_id,
      'invoiceId' => $invoice_params['invoice_id'],
      'amount' => [
        'currencyId' => $invoice_params['currency_id'],
        "displayValue" => $invoice_params['display_value'],
        'value' => $invoice_params['amount'],
      ],
    );

    if (isset($invoice_params['notes_link'])) {
      $params['notesToRecipient'] = $invoice_params['notes_link'];
    }

    if(isset($invoice_params['billing_data'])){
      $params = $this->appendBillingData($params, $invoice_params['billing_data']);
    }
    $params = $this->appendInvoiceMetadata($params);
    return $this->sendRequest('POST', $action, $this->client_id, $params, $secret);
  }

  /**
   * @param $name
   * @return mixed
   * @throws Exception
   */
  public function getCoinCurrency($name)
  {

    $params = array(
      'types' => self::FIAT_TYPE,
      'q' => $name,
    );
    $items = array();

    $listData = $this->getCoinCurrencies($params);
    if (!empty($listData['items'])) {
      $items = $listData['items'];
    }

    return array_shift($items);
  }

  /**
   * @param array $params
   * @return bool|mixed
   * @throws Exception
   */
  public function getCoinCurrencies($params = array())
  {
    return $this->sendRequest('GET', self::API_CURRENCIES_ACTION, false, $params);
  }

  /**
   * @param $signature
   * @param $content
   * @param $event
   * @return bool
   */
  public function checkDataSignature($signature, $content, $event)
  {

    $request_url = $this->getNotificationUrl($event);
    $signature_string = sprintf('%s%s', $request_url, $content);
    $encoded_pure = $this->encodeSignatureString($signature_string, $this->client_secret);
    return $signature == $encoded_pure;
  }

  /**
   * @param $signature_string
   * @param $client_secret
   * @return string
   */
  public function encodeSignatureString($signature_string, $client_secret)
  {
    return base64_encode(hash_hmac('sha256', $signature_string, $client_secret, true));
  }

  /**
   * @param $action
   * @return string
   */
  public function getApiUrl($action)
  {
    return sprintf('%s/api/v%s/%s', self::API_URL, self::API_VERSION, $action);
  }

  /**
   * @param $event
   * @return string
   */
  protected function getNotificationUrl($event)
  {
    return Url::fromRoute('uc_coinpayments.notification', [
      'clientId' => $this->client_id,
      'event' => $event
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * @param $method
   * @param $api_action
   * @param $client_id
   * @param null $params
   * @param null $client_secret
   * @return bool|mixed
   * @throws Exception
   */
  protected function sendRequest($method, $api_action, $client_id, $params = null, $client_secret = null)
  {

    $response = false;

    $api_url = $this->getApiUrl($api_action);
    $date = new \Datetime();
    try {

      $client = \Drupal::httpClient();

      $options = array(
        RequestOptions::VERIFY => false,
        RequestOptions::HTTP_ERRORS => false,
      );

      $headers = array(
        'Content-Type: application/json',
      );

      if ($client_secret) {
        $signature = $this->createSignature($method, $api_url, $client_id, $date, $client_secret, $params);
        $headers['X-CoinPayments-Client'] = $client_id;
        $headers['X-CoinPayments-Timestamp'] = $date->format('c');
        $headers['X-CoinPayments-Signature'] = $signature;
      }

      $options[RequestOptions::HEADERS] = $headers;

      if ($method == 'POST') {
        $options[RequestOptions::JSON] = $params;
      } elseif ($method == 'GET' && !empty($params)) {
        $api_url .= '?' . http_build_query($params, '', '&');
      }

      $request = $client->request($method, $api_url, $options);
      $response = Json::decode($request->getBody());

    } catch (Exception $e) {
      watchdog_exception('commerce_coinpayments', $e->getMessage());
    }
    return $response;
  }

  /**
   * @param array $data
   * @return mixed
   */
  protected function request(array $data)
  {

    $result = false;
    try {
      $client = \Drupal::httpClient();

      $body = http_build_query($data, '', '&');

      $response = $client->post($this->api_url,
        [
          RequestOptions::BODY => $body,
          RequestOptions::HEADERS => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'HMAC' => hash_hmac('sha512', $body, $this->private_key),
          ],
          RequestOptions::HTTP_ERRORS => FALSE,
        ]
      );

      $result = Json::decode($response->getBody());
    } catch (RequestException $e) {
      watchdog_exception('commerce_coinpayments', $e->getMessage());
    }

    return $result;
  }

  /**
   * @param $request_params
   * @param $billing_data
   * @return array
   */
  function appendBillingData($request_params, $billing_data)
  {

    $request_params['buyer'] = array(
      'companyName' => $billing_data['company'],
      'name' => array(
        'firstName' => $billing_data['first_name'],
        'lastName' => $billing_data['last_name'],
      ),
      'emailAddress' => $billing_data['email'],
    );

    if (!empty($billing_data['street1']) &&
      !empty($billing_data['zone']) &&
      preg_match('/^([A-Z]{2})$/', $billing_data['country'])
    ) {
      $request_params['buyer']['address'] = array(
        'address1' => $billing_data['street1'],
        'address2' => $billing_data['street2'],
        'provinceOrState' => $billing_data['zone'],
        'city' => $billing_data['city'],
        'countryCode' => $billing_data['country'],
        'postalCode' => $billing_data['postal_code'],
      );

    }

    return $request_params;
  }

  /**
   * @param $request_data
   * @return mixed
   */
  protected function appendInvoiceMetadata($request_data)
  {
    $request_data['metadata'] = array(
      "integration" => sprintf("Ubercart"),
      "hostname" => \Drupal::request()->getSchemeAndHttpHost(),
    );

    return $request_data;
  }

  /**
   * @param $method
   * @param $api_url
   * @param $client_id
   * @param $date
   * @param $client_secret
   * @param $params
   * @return string
   */
  protected function createSignature($method, $api_url, $client_id, $date, $client_secret, $params)
  {

    if (!empty($params)) {
      $params = json_encode($params);
    }

    $signature_data = array(
      chr(239),
      chr(187),
      chr(191),
      $method,
      $api_url,
      $client_id,
      $date->format('c'),
      $params
    );

    $signature_string = implode('', $signature_data);

    return $this->encodeSignatureString($signature_string, $client_secret);
  }

}
