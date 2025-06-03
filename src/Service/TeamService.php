<?php

namespace Drupal\transfermarkt_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;

/**
 * Service for handling team data from Transfermarkt API.
 */
class TeamService {

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
   * Constructs a new TeamService.
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
   * Imports a team from Transfermarkt.
   *
   * @param string $team_id
   *   The Transfermarkt team ID.
   * @param bool $update_if_exists
   *   Whether to update the team if it already exists.
   * @param bool $import_squad
   *   Whether to import the team's squad.
   *
   * @return int|null
   *   The node ID of the imported team, or NULL if import failed.
   */
  public function importTeam($team_id, $update_if_exists = TRUE, $import_squad = TRUE) {
    try {
      // Check if team already exists
      $existing_team = $this->getTeamByTransfermarktId($team_id);
      if ($existing_team && !$update_if_exists) {
        $this->logger->notice('Team already exists: @name (ID: @id)', [
          '@name' => $existing_team->getTitle(),
          '@id' => $team_id,
        ]);
        return $existing_team->id();
      }
      
      // Get team data from API
      $api_response = $this->transfermarktApi->getTeamData($team_id);
      
      // The new API response format
      $team_data = $api_response;
      
      $this->logger->notice('Team data received from API: @data', ['@data' => print_r($team_data, TRUE)]);
      
      // Download team logo if available
      $logo_fid = NULL;
      if (!empty($team_data['image'])) {
        $logo_fid = $this->transfermarktApi->downloadImage(
          $team_data['image'],
          'public://transfermarkt/teams',
          'team_' . $team_id . '.jpg'
        );
      }
      
      // Create or update team node
      if ($existing_team && $update_if_exists) {
        $team_node = $existing_team;
        $this->logger->notice('Updating existing team: @name (ID: @id)', [
          '@name' => $team_data['name'],
          '@id' => $team_id,
        ]);
      }
      else {
        // Create a new team node with basic values
        $values = [
          'type' => 'team',
          'title' => $team_data['name'],
          'status' => 1, // Published
          'uid' => 1, // Admin user
        ];
        
        $team_node = Node::create($values);
        $this->logger->notice('Creating new team: @name (ID: @id)', [
          '@name' => $team_data['name'],
          '@id' => $team_id,
        ]);
      }
      
      // Always set the transfermarkt ID
      $team_node->set('field_transfermarkt_id', $team_id);
      
      // Check if fields exist before setting them
      if ($team_node->hasField('field_team_name') && isset($team_data['name'])) {
      $team_node->set('field_team_name', $team_data['name']);
      }
      
      // Handle country - API returns country in different ways depending on endpoint
      if ($team_node->hasField('field_country')) {
        $country_name = NULL;
        
        // Try different paths to find country data
        if (isset($team_data['country']) && is_array($team_data['country']) && isset($team_data['country']['name'])) {
          $country_name = $team_data['country']['name'];
        } elseif (isset($team_data['country']) && is_string($team_data['country'])) {
          $country_name = $team_data['country'];
        } elseif (isset($team_data['league']) && isset($team_data['league']['countryName'])) {
          $country_name = $team_data['league']['countryName'];
        }
        
        if ($country_name) {
        // Get or create taxonomy term for country
          $country_tid = $this->getOrCreateTaxonomyTerm('country', $country_name);
        if ($country_tid) {
          $team_node->set('field_country', $country_tid);
        }
      }
      }
      
      // Handle market value - API returns market value in different ways
      if ($team_node->hasField('field_market_value')) {
        $market_value = NULL;
        
        if (isset($team_data['marketValue'])) {
          $market_value = $team_data['marketValue'];
        } elseif (isset($team_data['currentMarketValue'])) {
          $market_value = $team_data['currentMarketValue'];
        } elseif (isset($team_data['value'])) {
          $market_value = $team_data['value'];
        }
        
        if ($market_value !== NULL) {
          // Convert string value like "€750.00m" to integer
          if (is_string($market_value)) {
            $market_value = $this->convertMarketValueToInteger($market_value);
          }
          $team_node->set('field_market_value', $market_value);
        }
      }
      
      // Handle league/competition - API returns competition in different ways
      if ($team_node->hasField('field_league')) {
        $competition_id = NULL;
        $competition_name = NULL;
        
        if (isset($team_data['competition']) && isset($team_data['competition']['id'])) {
          $competition_id = $team_data['competition']['id'];
          $competition_name = $team_data['competition']['name'] ?? 'Unknown Competition';
        } elseif (isset($team_data['league']) && isset($team_data['league']['id'])) {
          $competition_id = $team_data['league']['id'];
          $competition_name = $team_data['league']['name'] ?? 'Unknown Competition';
        }
        
        if ($competition_id) {
        // Try to find the competition node
        $competition_nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
          'type' => 'competition',
            'field_transfermarkt_id' => $competition_id,
        ]);
        
        if (!empty($competition_nodes)) {
          $competition_node = reset($competition_nodes);
          $team_node->set('field_league', $competition_node->id());
        }
          // We no longer automatically import competitions to prevent recursive imports
          // Just log that we found a competition reference
        else {
            $this->logger->notice('Team @team references competition @competition_id (@name) which is not imported', [
              '@team' => $team_node->getTitle(),
              '@competition_id' => $competition_id,
                '@name' => $competition_name,
            ]);
          }
        }
      }
      
      // Set the team logo if downloaded successfully
      if ($logo_fid && $team_node->hasField('field_logo')) {
        $team_node->set('field_logo', $logo_fid);
      }
      
      // Save the team node
      $team_node->save();
      
      // Verify the node was saved correctly
      $saved_nid = $team_node->id();
      if ($saved_nid) {
        $this->logger->notice('Team saved successfully with node ID: @nid', ['@nid' => $saved_nid]);
        
        // Double check that the node exists in the database
        $node_storage = $this->entityTypeManager->getStorage('node');
        $loaded_node = $node_storage->load($saved_nid);
        if ($loaded_node) {
          // Import squad if requested
          if ($import_squad) {
            $this->logger->notice('Importing squad for team: @name (ID: @id)', [
              '@name' => $team_data['name'],
              '@id' => $team_id,
            ]);
            $this->importTeamSquad($team_id, $saved_nid);
          }
        }
        else {
          $this->logger->error('Team node could not be verified in database after save: @nid', ['@nid' => $saved_nid]);
        }
      }
      else {
        $this->logger->error('Team node ID is empty after save');
      }
      
      return $saved_nid;
    }
    catch (\Exception $e) {
      $this->logger->error('Error importing team: @error', ['@error' => $e->getMessage()]);
      $this->logger->error('Error trace: @trace', ['@trace' => $e->getTraceAsString()]);
      return NULL;
    }
  }

  /**
   * Converts a market value string to an integer.
   *
   * @param string $value
   *   The market value string (e.g. "€750.00m").
   *
   * @return int
   *   The market value as an integer.
   */
  protected function convertMarketValueToInteger($value) {
    // Remove currency symbol and spaces
    $value = trim(preg_replace('/[^\d.,kmb]/i', '', $value));
    
    // Convert to a standard format
    $multiplier = 1;
    if (stripos($value, 'k') !== FALSE) {
      $multiplier = 1000;
      $value = str_ireplace('k', '', $value);
    } elseif (stripos($value, 'm') !== FALSE) {
      $multiplier = 1000000;
      $value = str_ireplace('m', '', $value);
    } elseif (stripos($value, 'b') !== FALSE) {
      $multiplier = 1000000000;
      $value = str_ireplace('b', '', $value);
    }
    
    // Convert decimal separator
    $value = str_replace(',', '.', $value);
    
    // Convert to float and then to integer
    return (int) (floatval($value) * $multiplier);
  }

  /**
   * Gets a team node by Transfermarkt ID.
   *
   * @param string $team_id
   *   The Transfermarkt team ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The team node or NULL if not found.
   */
  public function getTeamByTransfermarktId($team_id) {
    // Use entity query with access check disabled
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'team')
      ->condition('field_transfermarkt_id', $team_id)
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
   * Imports a team's squad.
   *
   * @param string $team_id
   *   The Transfermarkt team ID.
   * @param int $team_nid
   *   The team node ID.
   *
   * @return array
   *   Array of imported player node IDs.
   */
  public function importTeamSquad($team_id, $team_nid) {
    try {
      $api_response = $this->transfermarktApi->getTeamSquad($team_id);
      $imported_ids = [];
      
      // The new API response format includes players under 'players' key
      if (empty($api_response) || !isset($api_response['players']) || !is_array($api_response['players'])) {
        $this->logger->error('Failed to fetch squad data for team ID: @id', ['@id' => $team_id]);
        return [];
      }
      
      $squad_data = $api_response['players'];
      $player_service = \Drupal::service('transfermarkt_integration.player_service');
      
      foreach ($squad_data as $player_data) {
        try {
          if (isset($player_data['id'])) {
            // Import the player
            $player_nid = $player_service->importPlayer($player_data['id']);
            
            if ($player_nid) {
              $imported_ids[] = $player_nid;
              
              // Load the player node and set the current club
              $player_node = Node::load($player_nid);
              if ($player_node) {
                $player_node->set('field_current_club', $team_nid);
                $player_node->save();
                $this->logger->notice('Added player @name (ID: @id) to team squad', [
                  '@name' => $player_node->getTitle(),
                  '@id' => $player_nid,
                ]);
              }
              else {
                $this->logger->error('Failed to load player node with ID: @id', ['@id' => $player_nid]);
              }
            }
            else {
              $this->logger->error('Failed to import player with Transfermarkt ID: @id', ['@id' => $player_data['id']]);
            }
          }
          else {
            $this->logger->warning('Player data missing ID in squad data for team ID: @id', ['@id' => $team_id]);
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Error processing player in squad: @error', ['@error' => $e->getMessage()]);
          // Continue with next player
          continue;
        }
      }
      
      $this->logger->notice('Imported @count players for team ID: @id', [
        '@count' => count($imported_ids),
        '@id' => $team_id,
      ]);
      
      return $imported_ids;
    }
    catch (\Exception $e) {
      $this->logger->error('Error importing team squad: @error', ['@error' => $e->getMessage()]);
      $this->logger->error('Error trace: @trace', ['@trace' => $e->getTraceAsString()]);
      return [];
    }
  }

  /**
   * Updates all team data.
   *
   * @param bool $import_squads
   *   Whether to import team squads.
   *
   * @return int
   *   The number of teams updated.
   */
  public function updateAllTeams($import_squads = FALSE) {
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'team')
        ->condition('field_transfermarkt_id', '', '<>')
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      $count = 0;
      foreach ($nids as $nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node && $node->hasField('field_transfermarkt_id') && !$node->get('field_transfermarkt_id')->isEmpty()) {
          $team_id = $node->get('field_transfermarkt_id')->value;
          $this->importTeam($team_id, TRUE, $import_squads);
          $count++;
        }
      }
      
      $this->logger->notice('Updated @count teams', ['@count' => $count]);
      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating teams: @error', ['@error' => $e->getMessage()]);
      return 0;
    }
  }
} 