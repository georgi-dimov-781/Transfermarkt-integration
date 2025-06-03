<?php

namespace Drupal\transfermarkt_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Batch\BatchBuilder;

/**
 * Form for manually updating Transfermarkt data.
 */
class TransfermarktUpdateForm extends FormBase {

  /**
   * The player service.
   *
   * @var \Drupal\transfermarkt_integration\Service\PlayerService
   */
  protected $playerService;

  /**
   * The team service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TeamService
   */
  protected $teamService;

  /**
   * The competition service.
   *
   * @var \Drupal\transfermarkt_integration\Service\CompetitionService
   */
  protected $competitionService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->playerService = $container->get('transfermarkt_integration.player_service');
    $instance->teamService = $container->get('transfermarkt_integration.team_service');
    $instance->competitionService = $container->get('transfermarkt_integration.competition_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'transfermarkt_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('<p>Use this form to manually update data from Transfermarkt API.</p>'),
    ];

    $form['update_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Update Options'),
      '#open' => TRUE,
    ];

    $form['update_options']['update_players'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Players'),
      '#description' => $this->t('Update all player data.'),
      '#default_value' => TRUE,
    ];

    $form['update_options']['update_teams'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Teams'),
      '#description' => $this->t('Update all team data.'),
      '#default_value' => TRUE,
    ];

    $form['update_options']['update_competitions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update Competitions'),
      '#description' => $this->t('Update all competition data.'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update Data'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Create a batch operation to handle potentially large updates
    $batch_builder = new BatchBuilder();
    $batch_builder->setTitle($this->t('Updating Transfermarkt data'));
    $batch_builder->setInitMessage($this->t('Preparing to update data...'));
    $batch_builder->setProgressMessage($this->t('Processed @current out of @total.'));
    $batch_builder->setErrorMessage($this->t('An error occurred during processing'));
    $batch_builder->setFinishCallback([$this, 'batchFinished']);

    // Add operations based on form selections
    if ($form_state->getValue('update_players')) {
      $batch_builder->addOperation([$this, 'updatePlayers'], []);
    }

    if ($form_state->getValue('update_teams')) {
      $batch_builder->addOperation(
        [$this, 'updateTeams'],
        [FALSE] // Never update team squads
      );
    }

    if ($form_state->getValue('update_competitions')) {
      $batch_builder->addOperation(
        [$this, 'updateCompetitions'],
        [FALSE] // Never update competition standings
      );
    }

    batch_set($batch_builder->toArray());
  }

  /**
   * Batch operation to update players.
   *
   * @param array $context
   *   The batch context.
   */
  public function updatePlayers(array &$context) {
    $count = $this->playerService->updateAllPlayers();
    $context['results']['players_updated'] = $count;
    $context['message'] = $this->t('Updated @count players', ['@count' => $count]);
  }

  /**
   * Batch operation to update teams.
   *
   * @param bool $update_squads
   *   Whether to update team squads.
   * @param array $context
   *   The batch context.
   */
  public function updateTeams($update_squads, array &$context) {
    $count = $this->teamService->updateAllTeams($update_squads);
    $context['results']['teams_updated'] = $count;
    $context['message'] = $this->t('Updated @count teams', ['@count' => $count]);
  }

  /**
   * Batch operation to update competitions.
   *
   * @param bool $update_standings
   *   Whether to update competition standings.
   * @param array $context
   *   The batch context.
   */
  public function updateCompetitions($update_standings, array &$context) {
    $count = $this->competitionService->updateAllCompetitions($update_standings);
    $context['results']['competitions_updated'] = $count;
    $context['message'] = $this->t('Updated @count competitions', ['@count' => $count]);
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch succeeded.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The batch operations.
   */
  public function batchFinished($success, array $results, array $operations) {
    if ($success) {
      $message = $this->t('Data update completed successfully.');
      
      // Add details about what was updated
      if (!empty($results['players_updated'])) {
        $message .= ' ' . $this->t('@count players updated.', ['@count' => $results['players_updated']]);
      }
      if (!empty($results['teams_updated'])) {
        $message .= ' ' . $this->t('@count teams updated.', ['@count' => $results['teams_updated']]);
      }
      if (!empty($results['competitions_updated'])) {
        $message .= ' ' . $this->t('@count competitions updated.', ['@count' => $results['competitions_updated']]);
      }
      
      $this->messenger()->addStatus($message);
    }
    else {
      $this->messenger()->addError($this->t('An error occurred while updating data.'));
    }
  }

} 