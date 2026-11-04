<?php

namespace Drupal\commerce_escrow;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Orders for Escrow Offer negotiations started outside of checkout.
 *
 * Escrow.com copies the order's id from the auction to the transaction, and
 * the payment keeps the auction's token, so both must exist before the buyer
 * is sent to Escrow.com. Until the buyer really makes an offer they are only a
 * locked draft: out of checkout, not refreshed, and deleted if nothing follows.
 */
class EscrowOfferOrders {

  /**
   * The order data key marking such an order, and holding the caller's data.
   */
  public const DATA_KEY = 'commerce_escrow_offer';

  /**
   * Seconds a draft waits for the buyer to make an offer before it is deleted.
   */
  protected const DRAFT_LIFETIME = 30 * 86400;

  /**
   * The drafts deleted in one go.
   */
  protected const DELETE_LIMIT = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {}

  /**
   * Creates the locked draft order and its auction on Escrow.com.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   An Escrow gateway.
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   What is being negotiated.
   * @param \Drupal\commerce_price\Price $price
   *   The asking price.
   * @param string $mail
   *   The buyer's email.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The buyer's account; anonymous for buyers without one.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param array $data
   *   Anything the caller needs once the buyer makes the offer.
   *
   * @return array
   *   The order under "order", and Escrow.com's answer under "response".
   *
   * @throws \Exception
   *   When Escrow.com refuses; nothing is kept then.
   */
  public function start(PaymentGatewayInterface $gateway, PurchasableEntityInterface $entity, Price $price, string $mail, AccountInterface $account, StoreInterface $store, array $data = []): array {
    /** @var \Drupal\commerce_escrow\Plugin\Commerce\PaymentGateway\EscrowGatewayInterface $plugin */
    $plugin = $gateway->getPlugin();
    $order = $this->createOrder($gateway, $entity, $price, $mail, $account, $store, $data);
    try {
      $response = $plugin->initiateEscrow($order);
    }
    catch (\Exception $e) {
      $order->delete();
      throw $e;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'type' => 'escrow_payment',
      'payment_gateway' => $gateway->id(),
      'state' => 'new',
      'order_id' => $order->id(),
      'amount' => $order->getTotalPrice(),
    ]);
    $payment->save();
    $plugin->createPayment($payment, $response);

    return ['order' => $order, 'response' => $response];
  }

  /**
   * Determines whether an order was started here.
   */
  public function isOffer(OrderInterface $order): bool {
    return $order->getData(self::DATA_KEY) !== NULL;
  }

  /**
   * Places the order, once the buyer has made an offer.
   *
   * Not with the "place" transition: an offer isn't a sale, so receipts,
   * invoices and taking the item off sale must not follow from it.
   */
  public function place(OrderInterface $order): void {
    $order->getState()->applyTransitionById('offer_made');
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entityTypeManager->getStorage('commerce_order_type')->load($order->bundle());
    $order->setOrderNumber($order_type->getNumberPattern()?->getPlugin()->generate($order) ?? (string) $order->id());
    $order->setPlacedTime($this->time->getRequestTime());
    $order->unlock();
    $order->save();
  }

  /**
   * Deletes the drafts of buyers who never made their offer.
   *
   * @return int
   *   The number of orders deleted.
   */
  public function deleteAbandoned(): int {
    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'draft')
      ->condition('locked', TRUE)
      ->condition('changed', $this->time->getRequestTime() - self::DRAFT_LIFETIME, '<')
      ->range(0, self::DELETE_LIMIT)
      ->execute();

    $deleted = 0;
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    foreach ($storage->loadMultiple($ids) as $order) {
      if ($this->isOffer($order)) {
        $payment_storage->delete($payment_storage->loadMultipleByOrder($order));
        $order->delete();
        $deleted++;
      }
    }
    return $deleted;
  }

  /**
   * Creates the locked draft order, at the price the buyer sees.
   */
  protected function createOrder(PaymentGatewayInterface $gateway, PurchasableEntityInterface $entity, Price $price, string $mail, AccountInterface $account, StoreInterface $store, array $data): OrderInterface {
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $item_storage */
    $item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $item_storage->createFromPurchasableEntity($entity, ['quantity' => 1]);
    $order_item->setUnitPrice($price, TRUE);
    $order_item->save();

    /** @var \Drupal\commerce_order\Entity\OrderItemTypeInterface $item_type */
    $item_type = $this->entityTypeManager->getStorage('commerce_order_item_type')->load($order_item->bundle());
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->create([
      'type' => $item_type->getOrderTypeId(),
      'store_id' => $store->id(),
      'uid' => $account->id(),
      'mail' => $mail,
      'payment_gateway' => $gateway->id(),
      'state' => 'draft',
      'cart' => FALSE,
    ]);
    $order->setData(self::DATA_KEY, $data);
    $order->lock();

    // A buyer with an account brings their billing details along.
    if ($account->isAuthenticated()) {
      /** @var \Drupal\profile\ProfileStorageInterface $profile_storage */
      $profile_storage = $this->entityTypeManager->getStorage('profile');
      /** @var \Drupal\profile\Entity\ProfileInterface|null $profile */
      $profile = $profile_storage->loadByUser($this->entityTypeManager->getStorage('user')->load($account->id()), 'customer');
      if ($profile) {
        /** @var \Drupal\profile\Entity\ProfileInterface $billing */
        $billing = $profile_storage->create(['type' => 'customer', 'uid' => 0]);
        $billing->populateFromProfile($profile);
        $billing->save();
        $order->setBillingProfile($billing);
      }
    }

    $order->addItem($order_item);
    $order->recalculateTotalPrice();
    $order->save();
    return $order;
  }

}
