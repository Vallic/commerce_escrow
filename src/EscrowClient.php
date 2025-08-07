<?php

namespace Drupal\commerce_escrow;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelTrait;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class EscrowClient implements EscrowClientInterface {

  use LoggerChannelTrait;

  protected ClientInterface $client;

  public function __construct(protected string $apiKey, protected string $email, protected string $mode = 'live', protected bool $logging = FALSE) {
    $this->client = new Client();
  }

  /**
   * {@inheritdoc}
   */
  public function createEscrowPay(array $payload): array {
    return $this->request('integration/pay/2018-03-31', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getPendingEscrowPay(string $reference): array {
    return $this->request('integration/pay/2018-03-31??reference=' . $reference);
  }

  /**
   * {@inheritdoc}
   */
  public function createCustomer(array $payload): array {
    return $this->request('2017-09-01/customer', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getCustomer(string|int $id): array {
    return $this->request('2017-09-01/customer/' . $id);
  }

  /**
   * {@inheritdoc}
   */
  public function updateCustomer(string|int $id, array $payload): array {
    return $this->request('2017-09-01/customer/' . $id, 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function createTransaction(array $payload): array {
    return $this->request('2017-09-01/transaction', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getTransaction(int $transaction_id): array {
    return $this->request('2017-09-01/transaction/' . $transaction_id);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelTransaction(int $transaction_id, $message = NULL): array {
    $payload = [
      'action' => 'cancel',
      'cancel_information' => [
        'cancellation_reason' => $message ?? 'Customer cancelled the transaction.',
      ],
    ];
    return $this->request('2017-09-01/transaction/' . $transaction_id, 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getTransactionByReference(int|string $reference): array {
    return $this->request('2017-09-01/transaction/reference/' . $reference);
  }

  /**
   * {@inheritdoc}
   */
  public function confirmTransaction(int $transaction_id): array {
    return $this->request('2017-09-01/transaction/reference/' . $transaction_id, 'PATCH', ['action' => 'agree']);
  }

  /**
   * {@inheritdoc}
   */
  public function fundTransaction(int $transaction_id, $payload): array {
    return $this->request('2017-09-01/transaction/reference/' . $transaction_id . '/payment_methods', 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function shipTransaction(int $transaction_id, $shipping_information): array {
    $payload = [
      'action' => 'ship',
      'shipping_information' => $shipping_information,
    ];
    return $this->request('2017-09-01/transaction/reference/' . $transaction_id, 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function handleTransactionItems(int $transaction_id, string $action, $item_id = NULL, array $extra = []): array {
    if (!in_array($action, self::ESCROW_TRANSACTION_ITEMS_ACTIONS)) {
      throw new BadRequestHttpException('Invalid transaction action: ' . $action);
    }
    $url = '2017-09-01/transaction/' . $transaction_id;

    if ($item_id) {
      $url .= 'item/' . $item_id;
    }

    $payload = [
      'action' => $action,
    ];

    if ($action === 'ship_return') {
      $payload['shipping_information'] = $extra;
    }

    return $this->request($url, 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function createEscrowAuction($payload): array {
    return $this->request('integration/2018-08-01/auction', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getEscrowAuction(string $auction_id): array {
    return $this->request('integration/2018-08-01/auction/' . $auction_id);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelEscrowAuction(string $auction_id): array {
    return $this->request('integration/2018-08-01/auction/' . $auction_id, 'DELETE');
  }

  /**
   * {@inheritdoc}
   */
  public function createEscrowOffer(string $auction_id, float $amount, ?string $note, ?string $buyer_mail, ?int $transaction_id): array {
    $payload = [
      'no_fee_amount' => $amount,
    ];

    if ($buyer_mail) {
      $payload['buyer_mail'] = $buyer_mail;
    }

    if ($transaction_id) {
      $payload['transaction_id'] = $transaction_id;
    }

    if ($note) {
      $payload['message'] = $note;
    }
    return $this->request('integration/2018-08-01/auction/' . $auction_id . '/offer', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelEscrowOfferAsBuyer(string $auction_id, int $transaction_id): array {
    return $this->request('integration/2018-08-01/auction/' . $auction_id . '/offer/' . $transaction_id, 'PATCH', ['action' => 'cancel']);
  }

  /**
   * {@inheritdoc}
   */
  public function handleEscrowOfferAsSeller(string $auction_id, int $transaction_id, string $action): array {
    if (!in_array($action, ['accept', 'reject', 'cancel'])) {
      throw new BadRequestHttpException('Invalid action: ' . $action);
    }
    return $this->request('integration/2018-08-01/auction/' . $auction_id . '/offer/' . $transaction_id, 'PATCH', ['action' => $action]);
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveAuctionHistory(string $token): array {
    return $this->request('integration/2018-08-01/auction/' . $token . '/event');
  }

  /**
   * Method to process requests towards Escrow API.
   */
  protected function request(string $endpoint, string $method = 'GET', array $payload = []): array {
    $endpoint_url = sprintf('%s%s', $this->mode === 'test' ? self::ESCROW_SANDBOX_URL : self::ESCROW_PRODUCTION_URL, $endpoint);

    try {
      $request_data = [
        'headers' => [
          'Authorization' => sprintf('Basic %s', base64_encode(sprintf('%s:%s', $this->email, $this->apiKey))),
          'Content-Type' => 'application/json',
          'User-Agent' => sprintf('Commerce Core - Drupal 11 - https://www.drupal.org/project/commerce_escrow (Language=PHP/%s;)', PHP_VERSION),
        ],
      ];

      if ($payload) {
        $request_data['body'] = Json::encode($payload);
        if ($this->logging) {
          $this->getLogger('commerce_escrow')->info(json_encode($payload, JSON_THROW_ON_ERROR));
        }
      }

      $response = $this->client->request($method, $endpoint_url, $request_data);
    }
    catch (\Exception $exception) {
      $error = $exception->getMessage();
      $data = Json::decode($exception->getResponse()->getBody()) ?? [];

      if (isset($data['error'])) {
        $error = $data['error'];
      }

      if (isset($data['errors'])) {
        $error = Json::encode($data['errors']);
      }

      $this->getLogger('commerce_escrow')->error($error);
      throw new BadRequestHttpException($exception->getMessage());
    }

    $contents = $response->getBody()->getContents();

    if ($this->logging) {
      $this->getLogger('commerce_escrow')->info($contents);
    }

    return Json::decode($contents);
  }

}
