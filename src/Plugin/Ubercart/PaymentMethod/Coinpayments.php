<?php

namespace Drupal\uc_coinpayments\Plugin\Ubercart\PaymentMethod;

use Drupal\uc_coinpayments\Controller\ApiController;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_order\Entity\OrderStatus;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\ExpressPaymentMethodPluginInterface;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;

/**
 * Defines the coinpayments payment method.
 *
 * @UbercartPaymentMethod(
 *   id = "coinpayments",
 *   name = @Translation("Coinpayments.NET"),
 *   redirect = "\Drupal\uc_coinpayments\Form\coinpaymentsForm",
 * )
 */
class Coinpayments extends PaymentMethodPluginBase implements ExpressPaymentMethodPluginInterface, OffsitePaymentMethodPluginInterface
{

  /**
   * Display label for payment method
   * @param string $label
   * @return mixed
   */
  public function getDisplayLabel($label)
  {
    $build['label'] = [
      '#plain_text' => $label,
    ];
    $build['image'] = [
      '#prefix' => '<div class="uc-coinpayments-logo">',
      '#theme' => 'image',
      '#uri' => drupal_get_path('module', 'uc_coinpayments') . '/images/coinpayments.svg',
      '#alt' => $this->t('coinpayments'),
      '#attributes' => ['class' => ['uc-coinpayments-logo'], 'style' => 'width:250px'],
      '#suffix' => '</div>',
    ];

    return $build;
  }

  /**
   * @param $method_id
   * @return array
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function getExpressButton($method_id)
  {


    $items = \Drupal::service('uc_cart.manager')->get()->getContents();
    $express_checkout_cid = 'uc_coinpayments:express_checkout:' . md5(json_encode($items));
    if (!$invoice = \Drupal::cache()->get($express_checkout_cid)) {

      $invoice_data = $this->getExpressButtonInvoice($method_id, $items);

      \Drupal::cache()->set($express_checkout_cid, $invoice_data, \Drupal::time()->getRequestTime() + 3600);
    } else {
      $invoice_data = $invoice->data;
    }

    return [
      '#markup' => '<div id = "coinpayments-checkout-button" style="text-align: right"></div>',
      '#attached' => [
        'library' => [
          'uc_coinpayments/checkout.script',
          'uc_coinpayments/checkout.button',
        ],
        'drupalSettings' => [
          'uc_coinpayments' => [
            'invoice' => $invoice_data
          ]
        ],
      ],
    ];

  }

  /**
   * {@inheritdoc}
   */
  public function cartDetails(OrderInterface $order, array $form, FormStateInterface $form_state)
  {

    $coinpayments_link = sprintf(
      '<a href="%s" target="_blank" title="CoinPayments.net">CoinPayments.net</a>',
      'https://alpha.coinpayments.net/'
    );
    $coin_description = 'Pay with Bitcoin, Litecoin, or other altcoins via ';

    return ['details' => [
      '#markup' => sprintf('%s<br/>%s', $coin_description, $coinpayments_link),
    ]];
  }

  /**
   * Setup (settings) form for module
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {


    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => t('Client ID'),
      '#default_value' => $this->configuration['client_id'],
      '#description' => t('The Client ID of your CoinPayments.net account.'),
      '#required' => TRUE,
    ];

    $form['webhooks'] = [
      '#type' => 'select',
      '#title' => t('Gateway webhooks'),
      '#options' => [
        0 => t('Disabled'),
        1 => t('Enabled'),
      ],
      '#default_value' => $this->configuration['webhooks'],
      '#description' => t('Enable CoinPayments.net gateway webhooks.'),
    ];

    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => t('Client Secret'),
      '#default_value' => $this->configuration['client_secret'],
      '#description' => t('Client Secret of your CoinPayments.net account.'),
      '#states' => array(
        'required' => array(
          '#edit-settings-webhooks' => array('value' => 1),
        ),
        'visible' => array(
          '#edit-settings-webhooks' => array('value' => 1),
        ),
      ),
    ];

    return $form;

  }

  /**
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    $client_id = $values['client_id'];
    $webhooks = $values['webhooks'];
    $client_secret = $values['client_secret'];

    if (!empty($client_id) && empty($webhooks)) {
      if (!static::validateInvoice($client_id)) {
        $form_state->setError($form['client_id'], t('CoinPayments.net credentials invalid!'));
      }
    } elseif (!empty($client_id) && !empty($webhooks) && !empty($client_secret)) {
      $form['configuration']["form"]['client_secret']['#required'] = true;
      if (!static::validateWebhook($client_id, $client_secret)) {
        $form_state->setError($form['client_id'], t('CoinPayments.net credentials invalid!'));
      }
    } else {
      $form_state->setError($form['client_id'], t('CoinPayments.net credentials required!'));
    }

    if (!$form_state->getErrors() && $form_state->isSubmitted()) {
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['webhooks'] = $values['webhooks'];
      $this->configuration['client_secret'] = $values['client_secret'];
    }
  }

  /**
   *
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, OrderInterface $order = null)
  {

    $cid = 'uc_coinpayments:' . $order->id();
    if (!$invoice = \Drupal::cache()->get($cid)) {
      $invoice = $this->getInvoice($order);

      if (!empty($invoice['id'])) {
        $invoice['expire'] = \Drupal::time()->getRequestTime() + 3600;
        \Drupal::cache()->set($cid, $invoice, $invoice['expire']);
      }
    } else {
      $invoice = $invoice->data;
    }


    $data = [
      'invoice-id' => $invoice['id'],
      'cancel-url' => Url::fromRoute('uc_cart.checkout_review', [], ['absolute' => TRUE])->toString(),
      'success-url' => Url::fromRoute('uc_cart.checkout_complete', ['uc_order' => $order->id()], ['absolute' => TRUE])->toString(),
    ];


    $redirect_url = sprintf('%s/%s/', ApiController::CHECKOUT_URL, ApiController::API_CHECKOUT_ACTION);

    foreach ($data as $name => $value) {
      if (isset($value)) {
        $form[$name] = ['#type' => 'hidden', '#value' => $value];
      }
    }


    $form['#action'] = $redirect_url;
    $form['#method'] = 'GET';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit order'),
    ];

    return $form;
  }

  /**
   * @param $payment
   * @return bool|mixed
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected function getInvoice($order)
  {

    $configuration = $this->configuration;

    $client_id = $configuration['client_id'];
    $webhooks = $configuration['webhooks'];
    $client_secret = $configuration['client_secret'];

    $api = new \Drupal\uc_coinpayments\Controller\ApiController($client_id, $webhooks, $client_secret);
    $coin_currency = $api->getCoinCurrency($order->getCurrency());

    $billing_profile = $order->getAddress('billing');

    $billing_data = [
      'company' => $billing_profile->getCompany(),
      'first_name' => $billing_profile->getFirstName(),
      'last_name' => $billing_profile->getLastName(),
      'email' => $order->getEmail(),
      'street1' => $billing_profile->getStreet1(),
      'street2' => $billing_profile->getStreet2(),
      'city' => $billing_profile->getCity(),
      'country' => $billing_profile->getCountry(),
      'zone' => $billing_profile->getZone(),
      'postal_code' => $billing_profile->getPostalCode(),
    ];

    $invoice_params = array(
      'invoice_id' => $this->getInvoiceId($order),
      'currency_id' => $coin_currency['id'],
      "displayValue" => $this->getDisplayValue($order->getSubtotal()),
      'amount' => $this->getAmount($this->getDisplayValue($order->getSubtotal()), $coin_currency),
      'notes_link' => $this->getNotesLink($order),
      'billing_data' => $billing_data,
    );

    $coin_invoice = $api->createInvoice($invoice_params);
    if ($webhooks) {
      $coin_invoice = array_shift($coin_invoice['invoices']);
    }

    return $coin_invoice;
  }

  /**
   * @param $client_id
   * @param $client_secret
   */
  protected static function validateWebhook($client_id, $client_secret)
  {
    $api = new ApiController($client_id, true, $client_secret);
    return $api->checkWebhook();
  }

  /**
   * @param $client_id
   * @return bool
   */
  protected static function validateInvoice($client_id)
  {
    $api = new ApiController($client_id);
    $invoice_params = array(
      'invoice_id' => 'Validate invoice',
      'currency_id' => 5057,
      'amount' => 1,
      'display_value' => '0.01',
    );
    $invoice = $api->createInvoice($invoice_params);
    return !empty($invoice['id']);
  }

  protected function getExpressButtonInvoice($method_id, $items)
  {

    $order = Order::create([
      'uid' => \Drupal::currentUser()->id(),
      'payment_method' => $method_id,
    ]);

    $api = new ApiController($this->configuration['client_id'], $this->configuration['webhooks'], $this->configuration['client_secret']);
    $coin_currency = $api->getCoinCurrency($order->getCurrency());

    $order->products = [];
    $invoice_items = [];
    foreach ($items as $item) {
      $order->products[] = $item->toOrderProduct();
      $invoice_items[] = [
        "name" => $item->title,
        "quantity" =>
          [
            "value" => $item->qty->value,
            "type" => "1"
          ],
        'amount' => [
          'currencyId' => $coin_currency['id'],
          "displayValue" => $this->getDisplayValue($item->price->value * $item->qty->value),
          'value' => $this->getAmount($this->getDisplayValue($item->price->value * $item->qty->value), $coin_currency),
        ],
      ];
    }
    $order->save();

    return [
      'clientId' => $this->configuration['client_id'],
      'currencyId' => $coin_currency['id'],
      'items' => $invoice_items,
      'amount' => [
        'currencyId' => $coin_currency['id'],
        "displayValue" => $this->getDisplayValue($order->getSubtotal()),
        'value' => $this->getAmount($this->getDisplayValue($order->getSubtotal()), $coin_currency),
      ],
      'invoiceId' => $this->getInvoiceId($order),
      'notesToRecipient' => $this->getNotesLink($order),
      'requireBuyerNameAndEmail' => true,
      'buyerDataCollectionMessage' => "Your email and name is collected for customer service purposes such as order fulfillment.",
    ];
  }

  protected function getNotesLink($order)
  {

    return sprintf(
      "%s|Store name: %s|Order #%s",
      \Drupal::request()->getSchemeAndHttpHost() . $order->toUrl()->toString(),
      \Drupal::config('system.site')->get('name'),
      $order->id());
  }

  protected function getInvoiceId($order)
  {
    return sprintf('%s|%s', md5(\Drupal::request()->getSchemeAndHttpHost()), $order->id());
  }

  protected function getAmount($amount, $coin_currency)
  {
    return intval(number_format($amount, $coin_currency['decimalPlaces'], '', ''));
  }

  protected function getDisplayValue($amount)
  {
    return uc_currency_format($amount, FALSE, FALSE, '.');
  }

  protected function amount($order)
  {
    return sprintf(
      "%s|Store name: %s|Order #%s",
      \Drupal::request()->getSchemeAndHttpHost() . $order->toUrl()->toString(),
      \Drupal::config('system.site')->get('name'),
      $order->id());
  }
}
