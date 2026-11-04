<?php

namespace Drupal\commerce_escrow;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\State\StateInterface;

/**
 * The secret at the end of the webhook URL, shared by all Escrow gateways.
 *
 * It is kept in State, like the cron key: each environment has its own, and it
 * stays out of exported configuration.
 */
class EscrowWebhookSecret {

  /**
   * The State key of the secret.
   */
  protected const STATE_KEY = 'commerce_escrow.webhook_secret';

  public function __construct(protected StateInterface $state) {}

  /**
   * Returns the secret, generating it the first time.
   */
  public function get(): string {
    return (string) $this->state->get(self::STATE_KEY) ?: $this->regenerate();
  }

  /**
   * Replaces the secret. The current webhook URL stops working.
   */
  public function regenerate(): string {
    $secret = Crypt::randomBytesBase64(32);
    $this->state->set(self::STATE_KEY, $secret);
    return $secret;
  }

  /**
   * Determines whether a token from a webhook URL is the secret.
   */
  public function isValid(string $token): bool {
    $secret = (string) $this->state->get(self::STATE_KEY);
    return $secret !== '' && hash_equals($secret, $token);
  }

}
