<?php

namespace Drupal\commerce_escrow\Hook;

use Drupal\commerce_escrow\Entity\EscrowItem;
use Drupal\commerce_escrow\Entity\EscrowItemInterface;
use Drupal\commerce_escrow\EscrowOfferOrders;
use Drupal\commerce_order\Adjustment;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for Commerce Escrow.
 */
class EscrowHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'commerce_escrow.offer_orders')]
    protected EscrowOfferOrders $offerOrders,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->offerOrders->deleteAbandoned();
  }

  /**
   * Implements hook_entity_bundle_info_alter().
   */
  #[Hook('entity_bundle_info_alter')]
  public function entityBundleInfoAlter(array &$bundles): void {
    if (empty($bundles['commerce_product_variation'])) {
      return;
    }
    /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface $type */
    foreach ($this->entityTypeManager->getStorage('commerce_product_variation_type')->loadMultiple() as $type) {
      if ($type->hasTrait('purchasable_entity_escrow_item') && isset($bundles['commerce_product_variation'][$type->id()])) {
        $bundles['commerce_product_variation'][$type->id()]['class'] = EscrowItem::class;
      }
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for commerce_order_total_summary.
   */
  #[Hook('preprocess_commerce_order_total_summary')]
  public function preprocessOrderTotalSummary(array &$variables): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $variables['order_entity'];

    foreach ($order->getItems() as $item) {
      $purchased_entity = $item->getPurchasedEntity();
      if ($purchased_entity instanceof EscrowItemInterface && $purchased_entity->displayFee()) {
        $fee = $purchased_entity->getFeeEstimates($item->getTotalPrice());
        $adjustment_fee = new Adjustment([
          'type' => 'custom',
          'label' => $this->t('Estimated fees to be paid on Escrow.com'),
          'amount' => $fee,
          'total' => $fee,
        ]);
        $variables['totals']['adjustments'][] = $adjustment_fee->toArray();
      }
    }
  }

}
