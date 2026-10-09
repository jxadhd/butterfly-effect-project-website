<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\bfep\Admin\AdminFormat;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff review of one public referral submission.
 */
final class ReferralReviewForm extends AdminRecordFormBase {

  protected int $referralId = 0;
  protected object $referral;

  public function getFormId(): string {
    return 'bfep_referral_review_form';
  }

  protected function nextPendingId(int $currentId): ?int {
    $id = $this->bfdb()->query("
      SELECT id FROM referral_submissions
      WHERE (verification_status IS NULL OR TRIM(verification_status) = '' OR LOWER(verification_status) = 'pending') AND id <> :id
      ORDER BY created_at ASC NULLS LAST, id ASC
      LIMIT 1
    ", [':id' => $currentId])->fetchField();
    return $id === FALSE ? NULL : (int) $id;
  }

  protected function reviewRoute(int $id): array {
    return ['bfep.admin_referral_review', ['referral_id' => $id]];
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
    $form['submission']['created'] = $this->dateItem('Submitted', $this->referral->created_at ?? NULL);
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

    $form['submission']['fundraiser_url'] = $this->item('Fundraiser URL', $this->referral->fundraiser_url ?? NULL);
    if ($link = $this->externalLink($this->referral->fundraiser_url ?? NULL, (string) $this->t('Open fundraiser'))) {
      $form['submission']['fundraiser'] = $link;
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

    $this->addActions($form, (string) $this->t('Save review'), (string) $this->t('Back to referrals'), 'bfep.admin_referrals');
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fields = ['verification_status' => trim((string) $form_state->getValue('verification_status'))];
    $this->bfdb()->update('referral_submissions')
      ->fields($fields)
      ->condition('id', $this->referralId)
      ->execute();
    $this->auditLogger->record('updated', 'referral', $this->referralId, AdminFormat::changedKeys((array) $this->referral, $fields));
    $this->messenger()->addStatus($this->t('Referral review saved.'));
    $this->redirectAfterSave($form_state, $this->referralId, 'bfep.admin_referrals');
  }

}
