<?php

namespace Drupal\commerce_escrow;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * The secret at the end of the webhook URL, shared by all Escrow gateways.
 *
 * It is the webhook_secret of commerce_escrow.settings. Sites should override
 * that in settings.php, from an environment variable: the secret is then in
 * neither the database nor exported configuration.
 */
class EscrowWebhookSecret {

  /**
   * The configuration holding the secret.
   */
  protected const CONFIG_NAME = 'commerce_escrow.settings';

  /**
   * The key of the secret in the configuration.
   */
  protected const CONFIG_KEY = 'webhook_secret';

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * Returns the secret, generating it the first time unless overridden.
   *
   * @return string
   *   The secret. Empty if settings.php overrides it with an empty value, such
   *   as an environment variable that isn't set.
   */
  public function get(): string {
    $secret = $this->current();
    return $secret !== '' || $this->isOverridden() ? $secret : $this->regenerate();
  }

  /**
   * Returns the webhook URL to register on Escrow.com.
   *
   * @return \Drupal\Core\Url|null
   *   The URL, or NULL if settings.php overrides the secret with nothing.
   */
  public function url(): ?Url {
    $secret = $this->get();
    return $secret === '' ? NULL : Url::fromRoute('commerce_escrow.webhook', ['token' => $secret], ['absolute' => TRUE]);
  }

  /**
   * Replaces the secret in configuration. The current URL stops working.
   */
  public function regenerate(): string {
    $secret = Crypt::randomBytesBase64(32);
    $this->configFactory->getEditable(self::CONFIG_NAME)->set(self::CONFIG_KEY, $secret)->save();
    return $secret;
  }

  /**
   * Determines whether settings.php or a module overrides the secret.
   */
  public function isOverridden(): bool {
    return $this->configFactory->get(self::CONFIG_NAME)->hasOverrides(self::CONFIG_KEY);
  }

  /**
   * Determines whether a token from a webhook URL is the secret.
   */
  public function isValid(string $token): bool {
    $secret = $this->current();
    return $secret !== '' && hash_equals($secret, $token);
  }

  /**
   * Returns the secret in effect.
   */
  protected function current(): string {
    return (string) $this->configFactory->get(self::CONFIG_NAME)->get(self::CONFIG_KEY);
  }

}
