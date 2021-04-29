<?php

namespace Drupal\uc_coinpayments\Controller;

use Drupal\uc_coinpayments\Plugin\Ubercart\PaymentMethod\Coinpayments;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_cart\CartManagerInterface;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller routines for uc_Coinpayments.
 */
class CoinpaymentsController extends ControllerBase
{

  /** @var \Drupal\uc_cart\CartManagerInterface The cart manager */
  protected $cartManager;

  /** @var Store Session */
  protected $session;

  /** @var array Variable for store configuration */
  protected $configuration = array();

  /**
   * Constructs a CoinpaymentsController.
   *
   * @param \Drupal\uc_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   */
  public function __construct(CartManagerInterface $cart_manager)
  {
    $this->cartManager = $cart_manager;
  }

  /**
   * Create method
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @return \Drupal\uc_coinpayments\Controller\CoinpaymentsController
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('uc_cart.manager')
    );
  }

  /**
   * Notification callback function
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  public function notification(Request $request)
  {

    $content = $request->getContent();

    $webhook_data = json_decode($content, true);

    if (empty($signature = $_SERVER['HTTP_X_COINPAYMENTS_SIGNATURE'])) {
      return false;
    }

    if (!isset($webhook_data['invoice']['invoiceId'])) {
      return false;
    }

    $invoice_str = $webhook_data['invoice']['invoiceId'];
    $invoice_str = explode('|', $invoice_str);
    $host_hash = array_shift($invoice_str);
    $order_id = array_shift($invoice_str);

    if ($host_hash != md5(\Drupal::request()->getSchemeAndHttpHost())) {
      return false;
    }

    $order = Order::load($order_id);

    // Load configuration
    $plugin = \Drupal::service('plugin.manager.uc_payment.method')
      ->createFromOrder($order);
    $this->configuration = $plugin->getConfiguration();

    if (empty($this->configuration['webhooks'])) {
      return false;
    }

    $api = new ApiController($this->configuration['client_id'], $this->configuration['webhooks'], $this->configuration['client_secret']);
    if (!$api->checkDataSignature($signature, $content, $webhook_data['invoice']['status'])) {
      return false;
    }

    if ($webhook_data['invoice']['status'] == ApiController::PAID_EVENT) {
      $comment = $this->t('Paid by Coinpayments method');
      uc_payment_enter($order->id(), 'coinpayments', $webhook_data['invoice']['amount']['displayValue'], $order->getOwnerId(), NULL, $comment);
      $order->setStatusId('completed')->save();
      $this->cartManager->completeSale($order);
      die('success');
    } elseif ($webhook_data['invoice']['status'] == ApiController::CANCELLED_EVENT) {
      $order->setStatusId('canceled')->save();
      uc_order_comment_save($order->id(), 0, $this->t('You have canceled checkout at Coinpayments'));
      return;
    }

  }


}
