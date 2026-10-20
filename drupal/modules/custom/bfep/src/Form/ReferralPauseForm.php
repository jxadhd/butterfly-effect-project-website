<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\bfep\Service\ReferralIntake;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets staff close the public referral form while the team is short-handed.
 */
final class ReferralPauseForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected ReferralIntake $intake,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('bfep.referral_intake'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  public function getFormId(): string {
    return 'bfep_referral_pause_form';
  }

  protected function getEditableConfigNames(): array {
    return [ReferralIntake::CONFIG_NAME];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ReferralIntake::CONFIG_NAME);
    $form['#attached']['library'][] = 'bfep/admin';

    if ($this->intake->isPaused()) {
      $since = $this->intake->pausedAt();
      $status = '<strong>' . $this->t('Referrals are paused.') . '</strong> '
        . ($since ? $this->t('Since @date.', ['@date' => $this->dateFormatter->format($since, 'medium')]) . ' ' : '')
        . $this->t('The public referral form is closed and visitors see the notice below.');
      $form['status'] = ['#markup' => '<div class="bfep-admin-notice bfep-admin-notice--warning" role="status">' . $status . '</div>'];
    }
    else {
      $form['status'] = ['#markup' => '<p>' . $this->t('<strong>Referrals are open.</strong> Anyone can send one from the referral page.') . '</p>'];
    }

    $form['paused'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pause new referrals'),
      '#default_value' => (bool) $config->get('paused'),
      '#description' => $this->t('While this is ticked, the referral page (English and Arabic) shows a notice instead of the form. Referrals already received stay in the list and can be reviewed as normal. The volunteer and update forms stay open. Untick it to reopen referrals.'),
    ];

    $form['notice'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notice in English'),
      '#default_value' => (string) $config->get('notice'),
      '#rows' => 5,
      '#maxlength' => ReferralIntake::NOTICE_MAX,
      '#description' => $this->t('Optional. Leave blank to use the standard notice shown below. Plain text; leave an empty line between paragraphs.'),
    ];
    $form['notice_ar'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notice in Arabic'),
      '#default_value' => (string) $config->get('notice_ar'),
      '#rows' => 5,
      '#maxlength' => ReferralIntake::NOTICE_MAX,
      '#attributes' => ['lang' => 'ar', 'dir' => 'rtl'],
      '#description' => $this->t('Optional. Leave blank to use the standard Arabic notice. If you write your own English notice but leave this blank, Arabic visitors see your English notice, so add the Arabic too.'),
    ];

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('What visitors see while referrals are paused'),
      '#open' => TRUE,
      'en' => [
        '#type' => 'container',
        '#attributes' => ['lang' => 'en', 'dir' => 'ltr'],
        'title' => ['#markup' => '<h3>' . $this->t('English page') . '</h3>'],
        'notice' => $this->intake->notice('en'),
      ],
      'ar' => [
        '#type' => 'container',
        '#attributes' => ['lang' => 'ar', 'dir' => 'rtl'],
        'title' => ['#markup' => '<h3 lang="en" dir="ltr">' . $this->t('Arabic page') . '</h3>'],
        'notice' => $this->intake->notice('ar'),
      ],
      'note' => [
        '#markup' => '<p>' . $this->t('The preview shows the saved notices. Save to update it. The standard Arabic was written with AI and should be checked by a native speaker.') . '</p>',
      ],
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Save');
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable(ReferralIntake::CONFIG_NAME);
    $was_paused = (bool) $config->get('paused');
    $paused = (bool) $form_state->getValue('paused');
    $notice = trim((string) $form_state->getValue('notice'));
    $notice_ar = trim((string) $form_state->getValue('notice_ar'));

    $config
      ->set('paused', $paused)
      ->set('notice', $notice)
      ->set('notice_ar', $notice_ar);
    if ($paused && !$was_paused) {
      $config->set('paused_at', $this->time->getRequestTime());
    }
    // Saving invalidates the config's cache tag, which the referral page
    // carries, so cached copies of it are refreshed straight away.
    $config->save();

    if ($paused !== $was_paused) {
      $this->logger('bfep')->notice('@user @action referrals.', [
        '@user' => $this->currentUser()->getAccountName() ?: 'uid ' . $this->currentUser()->id(),
        '@action' => $paused ? 'paused' : 'reopened',
      ]);
      $this->messenger()->addStatus($paused
        ? $this->t('Referrals are paused. The referral page now shows the notice.')
        : $this->t('Referrals are open again. The referral page shows the form.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved.'));
    }
    if ($notice !== '' && $notice_ar === '') {
      $this->messenger()->addWarning($this->t('There is no Arabic notice, so Arabic visitors will see your English notice.'));
    }
  }

}
