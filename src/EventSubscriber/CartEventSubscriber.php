<?php

namespace Drupal\commerce_escrow\EventSubscriber;

use Drupal\commerce_cart\Event\CartEntityAddEvent;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\commerce_cart\Event\CartOrderItemUpdateEvent;
use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CartEvents::CART_ENTITY_ADD => [['addToCart', -100]],
      CartEvents::CART_ORDER_ITEM_UPDATE => [['updateCart', -100]],
    ];
  }

  /**
   * Prevent adding of the same item if is marked to be a single item.
   */
  public function addToCart(CartEntityAddEvent $event): void {
    $purchasable_entity = $event->getEntity();
    if ($purchasable_entity instanceof EscrowItemInterface && $purchasable_entity->isSingleItem()) {
      $cart = $event->getCart();
      foreach ($cart->getItems() as $order_item) {
        $cart_item_entity = $order_item->getPurchasedEntity();
        if ($cart_item_entity instanceof EscrowItemInterface && $cart_item_entity->id() === $purchasable_entity->id()) {
          $order_item->setQuantity(1);
          $order_item->save();
        }
      }
    }
  }

  /**
   * Prevent updating of the same item if is marked to be a single item.
   */
  public function updateCart(CartOrderItemUpdateEvent $event): void {
    $order_item = $event->getOrderItem();
    $purchasable_entity = $order_item->getPurchasedEntity();

    if ($purchasable_entity instanceof EscrowItemInterface && $purchasable_entity->isSingleItem()) {
      $order_item->setQuantity(1);
      $order_item->save();
    }
  }

}
