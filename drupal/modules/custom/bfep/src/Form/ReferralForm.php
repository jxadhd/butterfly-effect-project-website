<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;

/**
 * Public fundraiser-referral form.
 */
final class ReferralForm extends ProtectedExternalFormBase {

  public function getFormId(): string {
    return 'bfep_referral_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Campaign recipient's full name"),
      '#required' => TRUE,
      '#maxlength' => 255,
      // The recipient's name, not the visitor's: don't autofill it.
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email'),
      '#required' => TRUE,
      '#maxlength' => 254,
      '#attributes' => ['autocomplete' => 'email'],
    ];
    $form['fundraiser_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Link to the fundraiser'),
      '#required' => TRUE,
      '#maxlength' => 2048,
      '#description' => $this->t('Provide the public fundraiser page that you want the team to review.'),
    ];
    $listed = $form_state->get('listed_campaign');
    if ($listed !== NULL) {
      $form['listed'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-form-notice']],
        'message' => [
          '#markup' => '<p>' . $this->t('<strong>This fundraiser may already be listed.</strong> <a href=":url" target="_blank" rel="noopener">@name</a> uses the same fundraiser link. The link opens in a new tab, so your answers here are kept.', [
            ':url' => Url::fromRoute('bfep.campaign_detail', ['campaign_id' => $listed['id']])->toString(),
            '@name' => $listed['label'],
          ]) . '</p>',
        ],
        'same_fundraiser' => [
          '#type' => 'radios',
          '#title' => $this->t('Is this the fundraiser you are referring?'),
          '#options' => [
            'yes' => $this->t('Yes, it is the same fundraiser'),
            'no' => $this->t('No, it is a different fundraiser'),
          ],
        ],
      ];
    }
    $form['social_media_usernames'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public social media accounts or usernames'),
      '#maxlength' => 500,
      '#description' => $this->t('Optional. Include only public accounts relevant to verification.'),
    ];
    $form['city_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient city and country'),
      '#maxlength' => 255,
      '#description' => $this->t('Do not provide a street address.'),
    ];
    $form['fundraiser_setup_by'] = [
      '#type' => 'select',
      '#title' => $this->t('Who set up the fundraiser?'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => [
        'recipient' => $this->t('The recipient'),
        'family_member' => $this->t('A family member'),
        'friend_or_supporter' => $this->t('A friend or trusted supporter'),
        'community_volunteer' => $this->t('A community volunteer or mutual-aid organiser'),
        'social_media_supporter' => $this->t('A social-media supporter'),
        'not_sure' => $this->t('I am not sure'),
        'other' => $this->t('Other'),
      ],
    ];
    $form['fundraiser_setup_by_other'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Please describe who set up the fundraiser'),
      '#maxlength' => 255,
      '#states' => [
        'visible' => [':input[name="fundraiser_setup_by"]' => ['value' => 'other']],
        'required' => [':input[name="fundraiser_setup_by"]' => ['value' => 'other']],
      ],
    ];
    $form['family_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tell us about the recipient and their current situation'),
      '#required' => TRUE,
      '#maxlength' => 5000,
      '#rows' => 8,
      '#description' => $this->t('Share only what is relevant and safe for the team to review. This is not automatically published.'),
    ];
    $form['transfer_service'] = [
      '#type' => 'select',
      '#title' => $this->t('Which service is used to receive or transfer funds?'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#options' => [
        'western_union' => $this->t('Western Union'),
        'paypal' => $this->t('PayPal'),
        'bank_transfer' => $this->t('Bank transfer'),
        'moneygram' => $this->t('MoneyGram'),
        'mobile_wallet' => $this->t('Mobile wallet'),
        'cash_pickup' => $this->t('Cash pickup service'),
        'not_sure' => $this->t('I am not sure'),
        'other' => $this->t('Other'),
      ],
    ];
    $form['transfer_service_other'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Please name the service'),
      '#maxlength' => 255,
      '#states' => [
        'visible' => [':input[name="transfer_service"]' => ['value' => 'other']],
        'required' => [':input[name="transfer_service"]' => ['value' => 'other']],
      ],
    ];
    $form['plans_to_increase_goal'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('There are current plans to increase the fundraising goal.'),
    ];
    $form['applied_previously'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('This fundraiser or recipient has been referred before.'),
    ];
    $form['whatsapp_telegram'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WhatsApp, Telegram, or Signal contact number'),
      '#maxlength' => 255,
      '#description' => $this->t('Optional. Provide this only with the contact person’s permission.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit referral'),
      '#button_type' => 'primary',
    ];
    $this->prepareForm($form);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('fundraiser_setup_by') === 'other' && $this->clean($form_state->getValue('fundraiser_setup_by_other')) === '') {
      $form_state->setErrorByName('fundraiser_setup_by_other', $this->t('Please describe who set up the fundraiser.'));
    }
    if ($form_state->getValue('transfer_service') === 'other' && $this->clean($form_state->getValue('transfer_service_other')) === '') {
      $form_state->setErrorByName('transfer_service_other', $this->t('Please name the service being used.'));
    }
    $url = $this->clean($form_state->getValue('fundraiser_url'));
    if ($url !== '' && !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], TRUE)) {
      $form_state->setErrorByName('fundraiser_url', $this->t('Enter a valid public http:// or https:// fundraiser URL.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    // A fundraiser that is already listed gets one yes or no question before
    // the referral is saved. The answer only counts for the campaign it was
    // asked about, in case the visitor changed the link in between.
    $listed = $this->listedCampaign($this->clean($values['fundraiser_url']));
    $asked = $form_state->get('listed_campaign');
    if ($listed !== NULL && ($asked === NULL || $asked['id'] !== $listed['id'] || empty($values['same_fundraiser']))) {
      $form_state->set('listed_campaign', $listed);
      $form_state->setRebuild();
      $this->messenger()->addWarning($this->t('Please answer the question under the fundraiser link, then submit again.'));
      return;
    }
    if ($listed !== NULL && ($values['same_fundraiser'] ?? '') === 'yes') {
      $this->messenger()->addStatus($this->t('Thank you for checking. That fundraiser is already listed, so it does not need a new referral. If something on its page is wrong or out of date, you can tell us below.'));
      $form_state->setRedirect('bfep.change_request', [], ['query' => ['campaign_id' => $listed['id']]]);
      return;
    }

    $saved = $this->saveSubmission($form_state, fn() => $this->database->insert('referral_submissions')->fields([
      'full_name' => $this->clean($values['full_name']),
      'email' => $this->clean($values['email']),
      'fundraiser_url' => $this->clean($values['fundraiser_url']),
      'social_media_usernames' => $this->clean($values['social_media_usernames'] ?? ''),
      'city_country' => $this->clean($values['city_country'] ?? ''),
      'fundraiser_setup_by' => $values['fundraiser_setup_by'],
      'fundraiser_setup_by_other' => $this->clean($values['fundraiser_setup_by_other'] ?? ''),
      'family_description' => $this->clean($values['family_description']),
      'transfer_service' => $values['transfer_service'],
      'transfer_service_other' => $this->clean($values['transfer_service_other'] ?? ''),
      'plans_to_increase_goal' => !empty($values['plans_to_increase_goal']) ? 'true' : 'false',
      'applied_previously' => !empty($values['applied_previously']) ? 'true' : 'false',
      'whatsapp_telegram' => $this->clean($values['whatsapp_telegram'] ?? ''),
    ])->execute());
    if (!$saved) {
      return;
    }
    $this->registerSubmission();
    $this->messenger()->addStatus($this->t('Thank you. Your referral has been submitted for review.'));
    $form_state->setRedirect('bfep.refer');
  }

  /**
   * Finds a listed campaign whose active fundraiser uses this URL.
   *
   * @return array{id: int, label: string}|null
   *   The campaign's ID and a label such as "Family 42 (line 42)", or NULL
   *   when none matches or bfdb cannot be searched.
   */
  protected function listedCampaign(string $url): ?array {
    $key = AdminFormat::urlMatchKey($url);
    if ($key === '' || $this->database === NULL) {
      return NULL;
    }
    try {
      $row = $this->database->query('
        SELECT c.id, c.line_number, c.contact_name
        FROM campaigns c
        JOIN campaign_fundraisers cf ON cf.campaign_id = c.id AND cf.is_active
        WHERE c.deleted_at IS NULL AND ' . AdminFormat::urlKeySql('cf.url') . ' = :key
        ORDER BY c.id
        LIMIT 1', [':key' => $key])->fetchObject();
    }
    catch (\Throwable $exception) {
      $this->getLogger('bfep')->warning('Could not check whether a referred fundraiser is listed: @class', ['@class' => get_class($exception)]);
      return NULL;
    }
    if (!$row) {
      return NULL;
    }
    $name = trim((string) $row->contact_name) ?: (string) $this->t('Campaign @id', ['@id' => $row->id]);
    return [
      'id' => (int) $row->id,
      'label' => $row->line_number ? (string) $this->t('@name (line @line)', ['@name' => $name, '@line' => $row->line_number]) : $name,
    ];
  }

}
