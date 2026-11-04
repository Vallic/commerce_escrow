<?php

namespace Drupal\commerce_escrow\Form;

use Drupal\commerce_escrow\EscrowWebhookSecret;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the webhook URL all Escrow payment gateways share.
 */
final class EscrowSettingsForm extends FormBase {

  public function __construct(protected EscrowWebhookSecret $webhookSecret) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_escrow.webhook_secret'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_escrow_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The URL changes when the secret does.
    $form['#cache']['max-age'] = 0;

    $url = $this->webhookSecret->url();
    $form['webhook_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook URL'),
      '#plain_text' => $url ? $url->toString() : $this->t('Not available: the webhook secret is overridden with an empty value in settings.php.'),
      '#description' => $this->t('Register this URL once on each Escrow.com account, as all Escrow payment gateways share it. It contains a secret, keep it private like the API key.'),
    ];

    if ($this->webhookSecret->isOverridden()) {
      $form['secret'] = [
        '#type' => 'item',
        '#markup' => $this->t('The secret is set in settings.php, so it can only be changed there.'),
      ];
      return $form;
    }

    $form['secret'] = [
      '#type' => 'item',
      '#markup' => $this->t('The secret is stored in configuration, and a configuration export includes it. Override it in settings.php from an environment variable to keep it out of the database and exported configuration. Regenerating it stops the current URL working immediately.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate the webhook URL'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->webhookSecret->regenerate();
    $this->messenger()->addStatus($this->t('The webhook URL has changed, and the old one no longer works. Update the webhook on Escrow.com.'));
  }

}
