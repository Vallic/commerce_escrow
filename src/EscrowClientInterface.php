<?php

namespace Drupal\commerce_escrow;

interface EscrowClientInterface {

  public const string ESCROW_SANDBOX_URL = 'https://api.escrow-sandbox.com/';
  public const string ESCROW_PRODUCTION_URL = 'https://api.escrow.com/';

  public const array ESCROW_TRANSACTION_ITEMS_ACTIONS = [
    'receive',
    'accept',
    'reject',
    'ship_return',
    'receive_return',
    'accept_return',
    'reject_return',
  ];

  /**
   * Create a transaction on Escrow.com with hosted page for placing a payment.
   *
   * @see https://www.escrow.com/pay/docs
   */
  public function createEscrowPay(array $payload): array;

  /**
   * Create new customer on Escrow.com.
   *
   * @see https://www.escrow.com/api/docs/create-customer
   */
  public function createCustomer(array $payload): array;

  /**
   * Retrieve existing customer. The string 'me' for your self,
   * profile id for customers.
   *
   * @see https://www.escrow.com/api/docs/create-customer
   */
  public function getCustomer(string|int $id): array;

  /**
   * Update existing customer. The string 'me' for your self,
   * profile id for customers.
   *
   * @see https://www.escrow.com/api/docs/update-customer
   */
  public function updateCustomer(string|int $id, array $payload): array;

  /**
   * Create new transaction on Escrow.com.
   *
   * @see https://www.escrow.com/api/docs/create-transaction
   */
  public function createTransaction(array $payload): array;

  /**
   * Retrieving transaction details from Escrow.com by transaction id.
   *
   * @see https://www.escrow.com/api/docs/fetch-transaction
   */
  public function getTransaction(int $transaction_id): array;

  /**
   * Retrieving transaction details from Escrow.com by reference.
   *
   * @see https://www.escrow.com/api/docs/fetch-transaction
   */
  public function getTransactionByReference(int|string $reference): array;

  /**
   * Cancel a transaction on Escrow.com.
   *
   * @see https://www.escrow.com/api/docs/cancel-transaction
   */
  public function cancelTransaction(int $transaction_id, $message = NULL): array;

  /**
   * Agreeing to a transaction on Escrow.com.
   *
   * @see https://www.escrow.com/api/docs/agree-transaction
   */
  public function confirmTransaction(int $transaction_id): array;

  /**
   * Transactions must be funded before the seller can ship the items.
   *
   * @see https://www.escrow.com/api/docs/fund-transaction
   */
  public function fundTransaction(int $transaction_id, $payload): array;

  /**
   * Once a transaction has been funded, Escrow.com will instruct
   * the seller to ship the goods.
   *
   * @see https://www.escrow.com/api/docs/ship-transaction
   */
  public function shipTransaction(int $transaction_id, $shipping_information): array;

  /**
   * Covers all scenarios around handling transaction items which are shipped,
   * returned or need any kind of other authorization that
   * buyer did receive them.
   *
   * @see https://www.escrow.com/api/docs/receive-transaction-items
   * @see https://www.escrow.com/api/docs/accept-transaction-items
   * @see https://www.escrow.com/api/docs/reject-transaction-items
   * @see https://www.escrow.com/api/docs/return-transaction-items
   * @see https://www.escrow.com/api/docs/receive-returned-transaction-items
   * @see https://www.escrow.com/api/docs/accepting-returned-transaction-items
   * @see https://www.escrow.com/api/docs/rejecting-returned-transaction-items
   */
  public function handleTransactionItems(int $transaction_id, string $action, $item_id = NULL, array $extra = []): array;

  /**
   * Create initial auction.
   *
   * @see https://www.escrow.com/offer/docs
   */
  public function createEscrowAuction($payload): array;

  /**
   * Retrieve auction by id.
   *
   * @see https://www.escrow.com/offer/docs/fetch-auction
   */
  public function getEscrowAuction(string $auction_id): array;

  /**
   * Cancel the auction. Performed by the seller.
   *
   * @see https://www.escrow.com/offer/docs/cancel-auction
   */
  public function cancelEscrowAuction(string $auction_id): array;

  /**
   * Send offers and counteroffers. Performed as a buyer if the mail is set,
   * and as a seller by using last transaction id.
   * Buyer required fields - auction id, amount, buyer_mail.
   * Seller required fields - auction id, amount, transaction id.
   *
   * @see https://www.escrow.com/offer/docs/create-offer
   */
  public function createEscrowOffer(string $auction_id, float $amount, ?string $note, ?string $buyer_mail, ?int $transaction_id): array;

  /**
   * Cancel offer sent. Performed by the buyer.
   *
   * @see https://www.escrow.com/offer/docs/cancel-offer
   */
  public function cancelEscrowOfferAsBuyer(string $auction_id, int $transaction_id): array;

  /**
   * Handle cancel, reject and approve of offer by the seller.
   *
   * @see https://www.escrow.com/offer/docs/offer-action
   */
  public function handleEscrowOfferAsSeller(string $auction_id, int $transaction_id, string $action): array;

}
