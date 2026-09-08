<?php

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReferralReviewForm extends FormBase {

  protected int $referralId = 0;
  protected object $referral;

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.database'));
  }

  public function getFormId(): string {
    return 'bfep_referral_review_form';
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  protected function item(string $title, $value): array {
    $text = trim((string) ($value ?? ''));
    return [
      '#type' => 'item',
      '#title' => $title,
      '#markup' => $text === '' ? '<em>Not provided</em>' : nl2br(Html::escape($text)),
    ];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $referral_id = NULL): array {
    $this->referralId = (int) $referral_id;
    $this->referral = $this->bfdb()->select('referral_submissions', 'r')
      ->fields('r')
      ->condition('id', $this->referralId)
      ->execute()
      ->fetchObject();

    if (!$this->referral) {
      throw new NotFoundHttpException();
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $form['submission'] = [
      '#type' => 'details',
      '#title' => $this->t('Submitted information'),
      '#open' => TRUE,
    ];
    $form['submission']['name'] = $this->item('Campaign recipient', $this->referral->full_name ?? NULL);
    $form['submission']['email'] = $this->item('Submitter email', $this->referral->email ?? NULL);
    $form['submission']['location'] = $this->item('City / country', $this->referral->city_country ?? NULL);
    $form['submission']['social'] = $this->item('Social media', $this->referral->social_media_usernames ?? NULL);
    $form['submission']['family'] = $this->item('Family / situation', $this->referral->family_description ?? NULL);
    $form['submission']['setup'] = $this->item('Fundraiser set up by', trim((string) ($this->referral->fundraiser_setup_by ?? '')) . (!empty($this->referral->fundraiser_setup_by_other) ? ': ' . $this->referral->fundraiser_setup_by_other : ''));
    $form['submission']['transfer'] = $this->item('Transfer service', trim((string) ($this->referral->transfer_service ?? '')) . (!empty($this->referral->transfer_service_other) ? ': ' . $this->referral->transfer_service_other : ''));
    $form['submission']['contact'] = $this->item('WhatsApp / Telegram / Signal', $this->referral->whatsapp_telegram ?? NULL);
    $form['submission']['previous'] = $this->item('Referred previously', !empty($this->referral->applied_previously) ? 'Yes' : 'No');
    $form['submission']['goal'] = $this->item('Plans to increase goal', !empty($this->referral->plans_to_increase_goal) ? 'Yes' : 'No');

    if (!empty($this->referral->fundraiser_url) && filter_var($this->referral->fundraiser_url, FILTER_VALIDATE_URL)) {
      $form['submission']['fundraiser'] = [
        '#type' => 'link',
        '#title' => $this->t('Open fundraiser'),
        '#url' => Url::fromUri($this->referral->fundraiser_url),
        '#attributes' => ['class' => ['button'], 'target' => '_blank', 'rel' => 'noopener'],
      ];
    }

    $form['workflow'] = [
      '#type' => 'details',
      '#title' => $this->t('Review status'),
      '#open' => TRUE,
    ];
    $form['workflow']['verification_status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verification status'),
      '#default_value' => $this->referral->verification_status ?: 'pending',
      '#required' => TRUE,
      '#maxlength' => 100,
      '#description' => $this->t('Use the status values your BFEP workflow already uses (for example pending, verified, rejected, or needs_information).'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save review'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Back to referrals'), '#url' => Url::fromRoute('bfep.admin_referrals'), '#attributes' => ['class' => ['button']]];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->bfdb()->update('referral_submissions')
      ->fields(['verification_status' => trim((string) $form_state->getValue('verification_status'))])
      ->condition('id', $this->referralId)
      ->execute();
    $this->messenger()->addStatus($this->t('Referral review saved.'));
    $form_state->setRedirect('bfep.admin_referral_review', ['referral_id' => $this->referralId]);
  }

}
