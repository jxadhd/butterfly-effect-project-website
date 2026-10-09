<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Keeps a staff form and its input when a database save fails.
 */
trait SaveFailureTrait {

  /**
   * Logs a failed save and returns the user to the form with their input.
   *
   * Only the exception type and SQLSTATE are logged, because database
   * exception messages can include the submitted values (internal notes,
   * contact details).
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state, rebuilt so the input is kept.
   * @param \Throwable $exception
   *   What went wrong.
   * @param string $what
   *   A short description of the record, such as "campaign 12".
   */
  protected function reportSaveFailure(FormStateInterface $form_state, \Throwable $exception, string $what): void {
    $code = (string) ($exception->getPrevious()?->getCode() ?: $exception->getCode());
    $this->logger('bfep')->error('Could not save @what: @class (code @code).', [
      '@what' => $what,
      '@class' => get_class($exception),
      '@code' => $code,
    ]);
    $this->messenger()->addError($this->t('Nothing was saved because the database rejected the change (code @code). Your input is still below. Try again, and if it keeps failing, check Reports > Recent log messages.', [
      '@code' => $code,
    ]));
    $form_state->setRebuild();
  }

}
