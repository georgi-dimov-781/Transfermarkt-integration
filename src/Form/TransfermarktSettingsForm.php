<?php

namespace Drupal\transfermarkt_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Transfermarkt Integration settings.
 */
class TransfermarktSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'transfermarkt_integration.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'transfermarkt_integration_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('transfermarkt_integration.settings');

    $form['api_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API Settings'),
      '#open' => TRUE,
    ];

    $form['api_settings']['api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API Base URL'),
      '#description' => $this->t('The base URL of the Transfermarkt API. Default: https://transfermarkt-api.fly.dev'),
      '#default_value' => $config->get('api_base_url') ?: 'https://transfermarkt-api.fly.dev',
      '#required' => TRUE,
    ];

    $form['update_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Update Settings'),
      '#open' => TRUE,
    ];

    $form['update_settings']['update_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Update Interval'),
      '#description' => $this->t('How often to update data from Transfermarkt API.'),
      '#options' => [
        3600 => $this->t('Hourly'),
        21600 => $this->t('Every 6 hours'),
        43200 => $this->t('Every 12 hours'),
        86400 => $this->t('Daily'),
        604800 => $this->t('Weekly'),
      ],
      '#default_value' => $config->get('update_interval') ?: 86400,
    ];

    $form['update_settings']['update_players'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Players'),
      '#description' => $this->t('Update player data during cron runs.'),
      '#default_value' => $config->get('update_players') ?: TRUE,
    ];

    $form['update_settings']['update_teams'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Teams'),
      '#description' => $this->t('Update team data during cron runs.'),
      '#default_value' => $config->get('update_teams') ?: TRUE,
    ];

    $form['update_settings']['update_competitions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Competitions'),
      '#description' => $this->t('Update competition data during cron runs.'),
      '#default_value' => $config->get('update_competitions') ?: TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('transfermarkt_integration.settings')
      ->set('api_base_url', $form_state->getValue('api_base_url'))
      ->set('update_interval', $form_state->getValue('update_interval'))
      ->set('update_players', $form_state->getValue('update_players'))
      ->set('update_teams', $form_state->getValue('update_teams'))
      ->set('update_competitions', $form_state->getValue('update_competitions'))
      ->save();

    parent::submitForm($form, $form_state);
  }

} 