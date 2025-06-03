<?php

namespace Drupal\transfermarkt_integration\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\transfermarkt_integration\Service\CompetitionService;
use Drupal\transfermarkt_integration\Service\PlayerService;
use Drupal\transfermarkt_integration\Service\TeamService;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Transfermarkt Integration module.
 */
class TransfermarktDrushCommands extends DrushCommands {

  /**
   * The competition service.
   *
   * @var \Drupal\transfermarkt_integration\Service\CompetitionService
   */
  protected $competitionService;

  /**
   * The team service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TeamService
   */
  protected $teamService;

  /**
   * The player service.
   *
   * @var \Drupal\transfermarkt_integration\Service\PlayerService
   */
  protected $playerService;

  /**
   * The Transfermarkt API service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TransfermarktApiService
   */
  protected $transfermarktApi;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new TransfermarktDrushCommands object.
   *
   * @param \Drupal\transfermarkt_integration\Service\CompetitionService $competition_service
   *   The competition service.
   * @param \Drupal\transfermarkt_integration\Service\TeamService $team_service
   *   The team service.
   * @param \Drupal\transfermarkt_integration\Service\PlayerService $player_service
   *   The player service.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    CompetitionService $competition_service,
    TeamService $team_service,
    PlayerService $player_service,
    TransfermarktApiService $transfermarkt_api,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct();
    $this->competitionService = $competition_service;
    $this->teamService = $team_service;
    $this->playerService = $player_service;
    $this->transfermarktApi = $transfermarkt_api;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Import a team from Transfermarkt.
   *
   * @param string $id
   *   The Transfermarkt team ID.
   * @param array $options
   *   An associative array of options.
   *
   * @option update
   *   Update if the team already exists.
   * @usage tm-team 631
   *   Import Chelsea FC.
   * @usage tm-team 11 --update
   *   Import or update Arsenal FC.
   *
   * @command tm:team
   * @aliases tm-team
   */
  public function importTeam($id, array $options = ['update' => TRUE]) {
    try {
      $team_id = $this->teamService->importTeam($id, $options['update']);
      
      if ($team_id) {
        $this->logger()->success(dt('Successfully imported team with ID @id (Node ID: @nid)', [
          '@id' => $id,
          '@nid' => $team_id,
        ]));
      }
      else {
        $this->logger()->error(dt('Failed to import team with ID @id.', ['@id' => $id]));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Error importing team: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Import a player from Transfermarkt.
   *
   * @param string $id
   *   The Transfermarkt player ID.
   * @param array $options
   *   An associative array of options.
   *
   * @option update
   *   Update if the player already exists.
   * @usage tm-player 182877
   *   Import player with ID 182877.
   *
   * @command tm:player
   * @aliases tm-player
   */
  public function importPlayer($id, array $options = ['update' => TRUE]) {
    try {
      $player_id = $this->playerService->importPlayer($id, $options['update']);
      
      if ($player_id) {
        $this->logger()->success(dt('Successfully imported player with ID @id (Node ID: @nid)', [
          '@id' => $id,
          '@nid' => $player_id,
        ]));
      }
      else {
        $this->logger()->error(dt('Failed to import player with ID @id.', ['@id' => $id]));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Error importing player: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Import a competition from Transfermarkt.
   *
   * @param string $id
   *   The Transfermarkt competition ID.
   * @param array $options
   *   An associative array of options.
   *
   * @option update
   *   Update if the competition already exists.
   * @usage tm-competition GB1
   *   Import Premier League.
   * @usage tm-competition ES1 --update
   *   Import or update La Liga.
   *
   * @command tm:competition
   * @aliases tm-competition
   */
  public function importCompetition($id, array $options = ['update' => TRUE]) {
    try {
      $competition_id = $this->competitionService->importCompetition($id, $options['update']);
      
      if ($competition_id) {
        $this->logger()->success(dt('Successfully imported competition with ID @id (Node ID: @nid)', [
          '@id' => $id,
          '@nid' => $competition_id,
        ]));
      }
      else {
        $this->logger()->error(dt('Failed to import competition with ID @id.', ['@id' => $id]));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Error importing competition: @error', ['@error' => $e->getMessage()]));
    }
  }
} 