<?php

namespace Drupal\commerce_escrow\Entity;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;

/**
 * {@inheritdoc}
 */
class EscrowItem extends ProductVariation implements EscrowItemInterface {

  /**
   * {@inheritdoc}
   */
  public function getInspectionPeriod(): int {
    return (int) $this->get('inspection_period')->value * 86400;
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowType(): string {
    return $this->get('escrow_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowFeeSplit(): string {
    return $this->get('escrow_fee')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isBrokered(): bool {
    return (bool) $this->get('brokered')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function inStock(): bool {
    return (bool) $this->get('in_stock')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isSingleItem(): bool {
    return (bool) $this->get('single_item')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBrokerFeePercentage(): int {
    return (int) $this->get('broker_fee')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBrokerFee(Price $amount): Price {
    if ($this->isBrokered() && $this->getBrokerFeePercentage() > 0) {
      return $amount->multiply($this->getBrokerFeePercentage() / 100);
    }

    return new Price('0', $amount->getCurrencyCode());
  }

  /**
   * {@inheritdoc}
   */
  public function displayFee(): bool {
    return (bool) $this->get('display_fee')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getBrokerFeeSplit(): ?string {
    return $this->get('broker_fee_split')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtraAttribute(): ?string {
    return $this->get('extra_attributes')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowFeeEstimate(Price $price): ?Price {
    $amount = (float) $price->getNumber();
    foreach (EscrowItemInterface::ESCROW_FEE_RANGES as $percentage => $range) {
      $start = $range['start'];
      $end = $range['end'];

      // Treat 0 "end" as infinity
      if ($end === 0) {
        $end = INF;
      }

      if ($amount >= $start && $amount <= $end) {
        $rate = (float) $percentage;
        $minimum = $range['minimum'];
        if ($this->getExtraAttribute() === self::ESCROW_CONCIERGE_SERVICE_FEE) {
          $rate = (float) $range['concierge']['percentage'];
          $minimum = $range['concierge']['minimum'] ?? 0;
        }

        $calculated = $amount * ($rate / 100);
        $final = max($calculated, $minimum);

        return new Price($final, $price->getCurrencyCode());
      }
    }

    // No range matched
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFeeEstimates(?Price $price, string $role = self::ESCROW_BUYER): ?Price {
    if (!$price) {
      $price = $this->getPrice();
    }
    $fees = new Price(0, $price->getCurrencyCode());
    if ($this->isBrokered()) {
      $broker_fee_split = $this->getBrokerFeeSplit();
      $broker_fee_amount = $this->getBrokerFee($price);

      switch ($broker_fee_split) {
        case '0':
          if ($role === self::ESCROW_BUYER) {
            $fees = $fees->add($broker_fee_amount);
          }
          break;

        case '0.5':
          $fees = $fees->add($broker_fee_amount->multiply($broker_fee_split));
          break;

        default:
          if ($role === self::ESCROW_SELLER) {
            $fees = $fees->add($broker_fee_amount);
          }

      }
    }

    $escrow_fee_split = $this->getEscrowFeeSplit();

    $escrow_amount = $this->getEscrowFeeEstimate($price);

    switch ($escrow_fee_split) {
      case '0.5':
        $fees = $fees->add($escrow_amount->multiply($escrow_fee_split));
        break;

      case '1':
        if ($role === self::ESCROW_SELLER) {
          $fees = $fees->add($escrow_amount);
        }
        break;

      default:
        if ($role === self::ESCROW_BUYER) {
          $fees = $fees->add($escrow_amount);
        }
    }

    $extra_fee = $this->getExtraAttribute();

    if ($extra_fee && $extra_fee !== self::ESCROW_CONCIERGE_SERVICE_FEE) {
      $fees = $fees->add(new Price(self::ESCROW_LIEN_HOLDER_TITLE_FEE_AMOUNT, $price->getCurrencyCode()));
    }

    return $fees;
  }

  /**
   * Gets the allowed values for the 'escrow_type' field.
   */
  public static function getEscrowTypePurchase(): array {
    return [
      self::ESCROW_ITEM_TYPE_DOMAIN_NAME => t('Domain names'),
      self::ESCROW_ITEM_TYPE_DOMAIN_NAME_HOLDING => t('Domain names (lease)'),
      self::ESCROW_ITEM_TYPE_GENERAL_MERCHANDISE => t('General Merchandise'),
      self::ESCROW_ITEM_TYPE_SERVICES => t('Services'),
      self::ESCROW_TYPE_MOTOR_VEHICLE => t('Motor Vehicles'),
    ];
  }

  /**
   * Gets the allowed values for the 'split' field.
   */
  public static function getEscrowSplitAmounts(): array {
    return [
      self::ESCROW_FEE_PAYS_BUYER => t('Buyer'),
      self::ESCROW_FEE_PAYS_SPLIT => t('Buyer and seller equally'),
      self::ESCROW_FEE_PAYS_SELLER => t('Seller'),
    ];
  }

  /**
   * Define additional fees which can be preselected.
   */
  public static function getEscrowExtraFees(): array {
    return [
      self::ESCROW_CONCIERGE_SERVICE_FEE => t('Concierge service'),
      self::ESCROW_TITLE_COLLECTION_FEE => t('Title collection service'),
      self::ESCROW_LIEN_HOLDER_PAYOFF_FEE => t('Lien holder payoff service'),
    ];
  }

}
