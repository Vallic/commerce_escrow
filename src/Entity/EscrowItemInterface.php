<?php

namespace Drupal\commerce_escrow\Entity;

use Drupal\commerce_price\Price;

interface EscrowItemInterface {

  public const string ESCROW_ITEM_TYPE_DOMAIN_NAME = 'domain_name';
  public const string ESCROW_ITEM_TYPE_DOMAIN_NAME_HOLDING = 'domain_name_holding';
  public const string ESCROW_ITEM_TYPE_GENERAL_MERCHANDISE = 'general_merchandise';
  public const string ESCROW_ITEM_TYPE_SERVICES = 'milestone';
  public const string ESCROW_TYPE_MOTOR_VEHICLE = 'motor_vehicle';

  public const string ESCROW_BUYER = 'buyer';
  public const string ESCROW_PARTNER = 'partner';
  public const string ESCROW_BROKER = 'broker';
  public const string ESCROW_SELLER = 'seller';
  public const string ESCROW_OWNER = 'me';

  public const string ESCROW_FEE_PAYS_BUYER = '0';
  public const string ESCROW_FEE_PAYS_SPLIT = '0.5';
  public const string ESCROW_FEE_PAYS_SELLER = '1';

  public const string ESCROW_CONCIERGE_SERVICE_FEE = 'concierge';
  public const string ESCROW_LIEN_HOLDER_PAYOFF_FEE = 'lien_holder_payoff';
  public const string ESCROW_TITLE_COLLECTION_FEE = 'title_collection';

  public const string ESCROW_LIEN_HOLDER_TITLE_FEE_AMOUNT = '60';

  public const array ESCROW_FEE_RANGES = [
    '2.6' => [
      'start' => 0,
      'end' => 50000.00,
      'minimum' => 50,
      'concierge' => [
        'percentage' => 5.2,
        'minimum' => 100,
      ],
    ],
    '2.4' => [
      'start' => 5000.01,
      'end' => 50000.00,
      'minimum' => 130,
      'concierge' => [
        'percentage' => 4.8,
      ],
    ],
    '1.9' => [
      'start' => 50000.01,
      'end' => 200000.00,
      'minimum' => 1200,
      'concierge' => [
        'percentage' => 3.8,
      ],
    ],
    '1.5' => [
      'start' => 200000.01,
      'end' => 500000.00,
      'minimum' => 3800,
      'concierge' => [
        'percentage' => 3.0,
      ],
    ],
    '1.2' => [
      'start' => 500000.01,
      'end' => 1000000.00,
      'minimum' => 7500,
      'concierge' => [
        'percentage' => 2.4,
      ],
    ],
    '1' => [
      'start' => 1000000.01,
      'end' => 3000000.00,
      'minimum' => 12000,
      'concierge' => [
        'percentage' => 2.0,
      ],
    ],
    '0.95' => [
      'start' => 3000000.01,
      'end' => 5000000.00,
      'minimum' => 30000,
      'concierge' => [
        'percentage' => 1.9,
      ],
    ],
    '0.9' => [
      'start' => 5000000.01,
      'end' => 10000000.00,
      'minimum' => 47500,
      'concierge' => [
        'percentage' => 1.8,
      ],
    ],
    '0.7' => [
      'start' => 10000000.01,
      'end' => 0,
      'minimum' => 0,
      'concierge' => [
        'percentage' => 1.4,
      ],
    ],
  ];

  public const array ESCROW_TRANSACTION_EVENTS = [
    'create' => 'A new transaction has been created.',
    'agree' => 'All parties have agreed to the transaction.',
    'party_verification_submitted' => 'A party has submitted their verification for review.',
    'party_verification_approved' => 'A party has had their verification reviewed and approved.',
    'party_verification_rejected' => 'A party has had their verification reviewed and rejected.',
    'payment_approved' => 'Escrow.com has approved the payment for the transaction and the goods may now be shipped by the seller.',
    'payment_rejected' => 'Escrow.com has rejected the payment for the transaction.',
    'payment_sent' => 'The buyer has sent payment to Escrow.com.',
    'payment_received' => 'Escrow.com has received payment from the buyer.',
    'payment_refunded' => "Escrow.com has refunded a buyer's payment.",
    'payment_disbursed' => 'Escrow.com has disbursed payment to the seller.',
    'ship' => 'The seller has indicated that the goods have been shipped.',
    'receive' => 'The buyer has indicated that the goods have been received.',
    'accept' => 'The buyer has indicated that the goods have been accepted.',
    'reject' => 'The buyer has indicated that the goods have been rejected.',
    'ship_return' => 'The buyer has indicated that the goods to be returned following rejection have been shipped.',
    'receive_return' => 'The seller has indicated that the goods to be returned following rejection have been received.',
    'accept_return' => 'The seller has indicated that the goods to be returned following rejection have been accepted.',
    'reject_return' => 'The seller has indicated that the goods to be returned following rejection have been rejected.',
    'complete' => 'All disbursements have been made to the seller, closing statements have been sent. Escrow.com marked the transaction as complete.',
    'cancel' => 'Escrow.com has marked the payment as cancelled.',
    'offer_accepted' => 'This is sent for Escrow Offer transactions, when an offer has been accepted.',
    'refund_resolved' => 'This is sent when Escrow.com has approved to process a refund for a transaction.',
    'refund_rejected' => 'This is sent when Escrow.com has rejected to process a refund for the transaction.',
  ];

  /**
   * Get inspection periods in seconds.
   */
  public function getInspectionPeriod(): int;

  /**
   * Get the escrow type of the item.
   */
  public function getEscrowType(): string;

  /**
   * Determine if we will show information about potential fees.
   */
  public function displayFee(): bool;

  /**
   * Get the amount for split on an escrow fee type.
   */
  public function getEscrowFeeSplit(): string;

  /**
   * Mark that domain sold is being brokered for another seller
   * (owner of product variation) and buyer. To add broker fee,
   * please use fee modules and provided Broker Fee type.
   */
  public function isBrokered(): bool;

  /**
   * Determine if variation is available for sale.
   */
  public function inStock(): bool;

  /**
   * Determine if we have variation of quantity 1.
   */
  public function isSingleItem(): bool;

  /**
   * Get the percentage for the broker fee.
   */
  public function getBrokerFeePercentage(): int;

  /**
   * Get the amount for split on a broker fee type.
   */
  public function getBrokerFeeSplit(): ?string;

  /**
   * Dynamically calculate broker fee cost.
   */
  public function getBrokerFee(Price $amount): Price;

  /**
   * Extra attributes.
   */
  public function getExtraAttribute(): ?string;

  /**
   * Calculate estimated fee cost on Escrow.com.
   */
  public function getEscrowFeeEstimate(Price $price): ?Price;

  /**
   * Collect all estimated fees.
   */
  public function getFeeEstimates(?Price $price, string $role = self::ESCROW_BUYER): ?Price;

}
