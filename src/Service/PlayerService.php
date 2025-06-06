<?php

namespace Drupal\transfermarkt_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;

/**
 * Service for handling player data from Transfermarkt API.
 */
class PlayerService {

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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new PlayerService.
   *
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   */
  public function __construct(
    TransfermarktApiService $transfermarkt_api,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system
  ) {
    $this->transfermarktApi = $transfermarkt_api;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('transfermarkt_integration');
    $this->fileSystem = $file_system;
  }

  /**
   * Imports a player from Transfermarkt.
   *
   * @param string $player_id
   *   The Transfermarkt player ID.
   * @param bool $update_if_exists
   *   Whether to update the player if it already exists.
   *
   * @return int|null
   *   The node ID of the imported player, or NULL if import failed.
   */
  public function importPlayer($player_id, $update_if_exists = TRUE) {
    try {
      // Start a database transaction to ensure consistency
      $transaction = \Drupal::database()->startTransaction();

      // Check if player already exists
      $existing_player = $this->getPlayerByTransfermarktId($player_id);
      if ($existing_player && !$update_if_exists) {
        $this->logger->notice('Player already exists: @name (ID: @id)', [
          '@name' => $existing_player->getTitle(),
          '@id' => $player_id,
        ]);
        return $existing_player->id();
      }
      
      // Clear entity cache to ensure we're working with fresh data
      \Drupal::entityTypeManager()->getStorage('node')->resetCache();
      
      // Get player data from API
      $api_response = $this->transfermarktApi->getPlayerData($player_id);
      
      // The new API response format includes player data under a profile structure
      $player_data = $api_response;
      
      $this->logger->notice('Player data received from API: @data', ['@data' => print_r($player_data, TRUE)]);
      
      // Download player photo if available
      $photo_fid = NULL;
      if (!empty($player_data['imageUrl'])) {
        $this->logger->notice('Found player image URL: @url', ['@url' => $player_data['imageUrl']]);
        $photo_fid = $this->transfermarktApi->downloadImage(
          $player_data['imageUrl'],
          'public://transfermarkt/players',
          'player_' . $player_id . '.jpg'
        );
        
        if ($photo_fid) {
          $this->logger->notice('Successfully downloaded player photo with file ID: @fid', ['@fid' => $photo_fid]);
        } else {
          $this->logger->warning('Failed to download player photo from URL: @url', ['@url' => $player_data['imageUrl']]);
        }
      } else {
        $this->logger->warning('No image URL found for player ID: @id', ['@id' => $player_id]);
      }
      
      // Create or update player node
      if ($existing_player && $update_if_exists) {
        $player_node = $existing_player;
        $this->logger->notice('Updating existing player: @name (ID: @id)', [
          '@name' => $player_data['name'],
          '@id' => $player_id,
        ]);
      }
      else {
        // Create a new player node with basic values
        $values = [
          'type' => 'player',
          'title' => $player_data['name'],
          'status' => 1, // Published
          'uid' => 1, // Admin user
        ];
        
        $player_node = Node::create($values);
        $this->logger->notice('Creating new player: @name (ID: @id)', [
          '@name' => $player_data['name'],
          '@id' => $player_id,
        ]);
      }
      
      // Always set the transfermarkt ID
      $player_node->set('field_transfermarkt_id', $player_id);
      
      // Check if fields exist before setting them
      if ($player_node->hasField('field_age') && isset($player_data['age'])) {
        $player_node->set('field_age', $player_data['age']);
      }
      
      if ($player_node->hasField('field_date_of_birth') && isset($player_data['dateOfBirth'])) {
        $player_node->set('field_date_of_birth', $player_data['dateOfBirth']);
      }
      
      // Handle nationality - API now returns citizenship as an array
      if ($player_node->hasField('field_nationality') && isset($player_data['citizenship']) && !empty($player_data['citizenship'])) {
        // Get or create taxonomy term for primary nationality
        $nationality_tid = $this->getOrCreateTaxonomyTerm('nationality', $player_data['citizenship'][0]);
        if ($nationality_tid) {
          $player_node->set('field_nationality', $nationality_tid);
        }
      }
      
      // Handle position - API now returns position as an object with main and other
      if ($player_node->hasField('field_position') && isset($player_data['position']) && isset($player_data['position']['main'])) {
        // Get or create taxonomy term for position
        $position_tid = $this->getOrCreateTaxonomyTerm('position', $player_data['position']['main']);
        if ($position_tid) {
          $player_node->set('field_position', $position_tid);
        }
      }
      
      if ($player_node->hasField('field_market_value') && isset($player_data['marketValue'])) {
        $player_node->set('field_market_value', $player_data['marketValue']);
      }
      
      // Handle current club - API now returns club as an object
      if ($player_node->hasField('field_current_club') && isset($player_data['club']) && isset($player_data['club']['id'])) {
        try {
        // Try to find the team node
        $team_nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
          'type' => 'team',
          'field_transfermarkt_id' => $player_data['club']['id'],
        ]);
        
        if (!empty($team_nodes)) {
          $team_node = reset($team_nodes);
          $player_node->set('field_current_club', $team_node->id());
          $this->logger->notice('Found existing team for player: @team (NID: @nid)', [
            '@team' => $team_node->getTitle(),
            '@nid' => $team_node->id(),
          ]);
        }
        else {
            // Try to import the team if it doesn't exist, but don't fail if it can't be imported
            $this->logger->notice('Team does not exist, attempting to import team ID: @id', [
            '@id' => $player_data['club']['id'],
          ]);
          
          $team_service = \Drupal::service('transfermarkt_integration.team_service');
          $team_nid = $team_service->importTeam($player_data['club']['id'], TRUE, FALSE);
          
          if ($team_nid) {
            // Make sure the team node is fully saved and available
            $team_storage = $this->entityTypeManager->getStorage('node');
            $team_storage->resetCache([$team_nid]);
            $team_entity = $team_storage->load($team_nid);
            
            if ($team_entity) {
              $player_node->set('field_current_club', $team_nid);
              $this->logger->notice('Imported new team for player: @team (NID: @nid)', [
                '@team' => $team_entity->getTitle(),
                '@nid' => $team_nid,
              ]);
            }
            else {
                $this->logger->warning('Team was imported but could not be loaded: @nid. Player will be saved without club reference.', [
                '@nid' => $team_nid,
              ]);
            }
          }
          else {
              $this->logger->warning('Failed to import team with ID: @id. Player will be saved without club reference.', [
              '@id' => $player_data['club']['id'],
            ]);
          }
          }
        }
        catch (\Exception $e) {
          $this->logger->warning('Exception while handling club reference for player: @error. Player will be saved without club reference.', [
            '@error' => $e->getMessage(),
          ]);
          // Continue with player import even if team reference fails
        }
      }
      
      // Set the player photo if downloaded successfully
      if ($photo_fid && $player_node->hasField('field_photo')) {
        $player_node->set('field_photo', $photo_fid);
        $this->logger->notice('Set player photo field with file ID: @fid', ['@fid' => $photo_fid]);
      }
      
      // Save the player node
      $player_node->save();
      
      // Verify the node was saved correctly
      $saved_nid = $player_node->id();
      if ($saved_nid) {
        $this->logger->notice('Player saved successfully with node ID: @nid', ['@nid' => $saved_nid]);
        
        // Double check that the node exists in the database
        $node_storage = $this->entityTypeManager->getStorage('node');
        // Clear the static cache to ensure we're getting the latest version from the database
        $node_storage->resetCache([$saved_nid]);
        $loaded_node = $node_storage->load($saved_nid);
        
        if ($loaded_node) {
          $this->logger->notice('Player node verified in database: @title (NID: @nid)', [
            '@title' => $loaded_node->getTitle(),
            '@nid' => $loaded_node->id(),
          ]);
          
          // Verify that the current club reference was saved correctly
          if ($loaded_node->hasField('field_current_club') && !$loaded_node->get('field_current_club')->isEmpty()) {
            $club_id = $loaded_node->get('field_current_club')->target_id;
            $this->logger->notice('Player has club reference to node ID: @club_id', [
              '@club_id' => $club_id,
            ]);
          }
          else {
            $this->logger->warning('Player was saved but club reference is missing.');
          }
        }
        else {
          $this->logger->error('Player node could not be verified in database after save: @nid', ['@nid' => $saved_nid]);
        }
      }
      else {
        $this->logger->error('Player node ID is empty after save');
      }
      
      // Commit the transaction
      if (isset($transaction)) {
        unset($transaction);
      }
      
      return $saved_nid;
    }
    catch (\Exception $e) {
      // Rollback the transaction on error
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      
      $this->logger->error('Error importing player: @error', ['@error' => $e->getMessage()]);
      $this->logger->error('Error trace: @trace', ['@trace' => $e->getTraceAsString()]);
      return NULL;
    }
  }

  /**
   * Gets a player node by Transfermarkt ID.
   *
   * @param string $player_id
   *   The Transfermarkt player ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The player node or NULL if not found.
   */
  public function getPlayerByTransfermarktId($player_id) {
    // Use entity query with access check disabled
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'player')
      ->condition('field_transfermarkt_id', $player_id)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $nids = $query->execute();
    
    if (!empty($nids)) {
      $nid = reset($nids);
      return $this->entityTypeManager->getStorage('node')->load($nid);
    }
    
    return NULL;
  }

  /**
   * Gets or creates a taxonomy term.
   *
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @param string $name
   *   The term name.
   *
   * @return int|null
   *   The term ID or NULL if creation failed.
   */
  protected function getOrCreateTaxonomyTerm($vocabulary, $name) {
    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      
      // Try to find existing term using entity query
      $query = $term_storage->getQuery()
        ->condition('vid', $vocabulary)
        ->condition('name', $name)
        ->accessCheck(FALSE)
        ->range(0, 1);
      $tids = $query->execute();
      
      if (!empty($tids)) {
        $tid = reset($tids);
        return $tid;
      }
      
      // Create new term
      $term = $term_storage->create([
        'vid' => $vocabulary,
        'name' => $name,
      ]);
      $term->save();
      
      return $term->id();
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating taxonomy term: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Imports top valuable players.
   *
   * @param int $limit
   *   The number of players to import.
   *
   * @return array
   *   Array of imported player node IDs.
   */
  public function importTopValuablePlayers($limit = 10) {
    try {
      $players = $this->transfermarktApi->getTopValuablePlayers($limit);
      $imported_ids = [];
      
      foreach ($players as $player) {
        if (isset($player['id'])) {
          $nid = $this->importPlayer($player['id']);
          if ($nid) {
            $imported_ids[] = $nid;
          }
        }
      }
      
      $this->logger->notice('Imported @count top valuable players', ['@count' => count($imported_ids)]);
      return $imported_ids;
    }
    catch (\Exception $e) {
      $this->logger->error('Error importing top valuable players: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Updates all player data.
   *
   * @return int
   *   The number of players updated.
   */
  public function updateAllPlayers() {
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'player')
        ->condition('field_transfermarkt_id', '', '<>')
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      $count = 0;
      foreach ($nids as $nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node && $node->hasField('field_transfermarkt_id') && !$node->get('field_transfermarkt_id')->isEmpty()) {
          $player_id = $node->get('field_transfermarkt_id')->value;
          $this->importPlayer($player_id, TRUE);
          $count++;
        }
      }
      
      $this->logger->notice('Updated @count players', ['@count' => $count]);
      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating players: @error', ['@error' => $e->getMessage()]);
      return 0;
    }
  }
} 