<?php

namespace Drupal\ntrip_config_editor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure NTRIP settings for the site.
 */
class NtripSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ntrip_config_editor_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ntrip_config_editor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ntrip_config_editor.settings');

    $form['time_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Time Rate (Baht/minute)'),
      '#default_value' => $config->get('time_rate') ?? 0.2,
      '#step' => 0.01,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $form['data_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Data Rate (Baht/MB)'),
      '#default_value' => $config->get('data_rate') ?? 1.0,
      '#step' => 0.01,
      '#min' => 0,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ntrip_config_editor.settings')
      ->set('time_rate', $form_state->getValue('time_rate'))
      ->set('data_rate', $form_state->getValue('data_rate'))
      ->save();

    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('NTRIP rates have been saved.'));
  }
}