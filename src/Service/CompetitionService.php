<?php

namespace Drupal\transfermarkt_integration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;

/**
 * Service for handling competition data from Transfermarkt API.
 */
class CompetitionService {

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
   * Constructs a new CompetitionService.
   *
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    TransfermarktApiService $transfermarkt_api,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->transfermarktApi = $transfermarkt_api;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('transfermarkt_integration');
  }

  /**
   * Imports a competition from Transfermarkt.
   *
   * @param string $competition_id
   *   The Transfermarkt competition ID.
   * @param bool $update_if_exists
   *   Whether to update the competition if it already exists.
   * @param bool $import_standings
   *   Whether to import the competition standings (clubs).
   *
   * @return int|null
   *   The node ID of the imported competition, or NULL if import failed.
   */
  public function importCompetition($competition_id, $update_if_exists = TRUE, $import_standings = FALSE) {
    try {
      // Check if competition already exists
      $existing_competition = $this->getCompetitionByTransfermarktId($competition_id);
      if ($existing_competition && !$update_if_exists) {
        $this->logger->notice('Competition already exists: @name (ID: @id)', [
          '@name' => $existing_competition->getTitle(),
          '@id' => $competition_id,
        ]);
        return $existing_competition->id();
      }
      
      // Get competition data from API
      $api_response = $this->transfermarktApi->getCompetitionData($competition_id);
      
      // The new API response format
      $competition_data = $api_response;
      
      // Ensure we have at least a name for the competition
      if (empty($competition_data['name'])) {
        if (!empty($competition_data['id'])) {
          // Use ID as name if available
          $competition_data['name'] = 'Competition ' . $competition_data['id'];
        } else {
          // Fallback name
          $competition_data['name'] = 'Competition ' . $competition_id;
        }
        $this->logger->warning('Competition name not found in API response, using fallback: @name', [
          '@name' => $competition_data['name'],
        ]);
      }
      
      $this->logger->notice('Competition data received from API: @data', ['@data' => print_r($competition_data, TRUE)]);
      
      // Create or update competition node
      if ($existing_competition && $update_if_exists) {
        $competition_node = $existing_competition;
        $this->logger->notice('Updating existing competition: @name (ID: @id)', [
          '@name' => $competition_data['name'],
          '@id' => $competition_id,
        ]);
      }
      else {
        // Create a new competition node with basic values
        $values = [
          'type' => 'competition',
          'title' => $competition_data['name'],
          'status' => 1, // Published
          'uid' => 1, // Admin user
        ];
        
        $competition_node = Node::create($values);
        $this->logger->notice('Creating new competition: @name (ID: @id)', [
          '@name' => $competition_data['name'],
          '@id' => $competition_id,
        ]);
      }
      
      // Always set the transfermarkt ID
      $competition_node->set('field_transfermarkt_id', $competition_id);
      
      // Check if fields exist before setting them
      if ($competition_node->hasField('field_competition_name') && isset($competition_data['name'])) {
      $competition_node->set('field_competition_name', $competition_data['name']);
      }
      
      // Handle country - API returns country in different ways
      if ($competition_node->hasField('field_country')) {
        $country_name = NULL;
        
        // Try different paths to find country data
        if (isset($competition_data['country']) && is_array($competition_data['country']) && isset($competition_data['country']['name'])) {
          $country_name = $competition_data['country']['name'];
        } elseif (isset($competition_data['country']) && is_string($competition_data['country'])) {
          $country_name = $competition_data['country'];
        } elseif (isset($competition_data['countryName'])) {
          $country_name = $competition_data['countryName'];
        }
        
        if ($country_name) {
        // Get or create taxonomy term for country
          $country_tid = $this->getOrCreateTaxonomyTerm('country', $country_name);
        if ($country_tid) {
          $competition_node->set('field_country', $country_tid);
          }
        }
      }
      
      // Handle season - API returns season in different ways
      if ($competition_node->hasField('field_season')) {
        $season = NULL;
      
      if (isset($competition_data['season'])) {
          $season = $competition_data['season'];
        } elseif (isset($competition_data['seasonId'])) {
          $season = $competition_data['seasonId'];
        } elseif (isset($competition_data['currentSeason'])) {
          $season = $competition_data['currentSeason'];
        }
        
        if ($season) {
          $competition_node->set('field_season', $season);
        }
      }
      
      // Handle competition type
      if ($competition_node->hasField('field_competition_type')) {
        $type = NULL;
      
      if (isset($competition_data['type'])) {
          $type = $competition_data['type'];
        } elseif (isset($competition_data['competitionType'])) {
          $type = $competition_data['competitionType'];
        } elseif (isset($competition_data['category'])) {
          $type = $competition_data['category'];
        } else {
          // Default to "League" if type is not provided
          $type = "League";
        }
        
        // Get or create taxonomy term for competition type
        $type_tid = $this->getOrCreateTaxonomyTerm('competition_type', $type);
        if ($type_tid) {
          $competition_node->set('field_competition_type', $type_tid);
        }
      }
      
      // Save the competition node
      $competition_node->save();
      
      // Verify the node was saved correctly
      $saved_nid = $competition_node->id();
      if ($saved_nid) {
        $this->logger->notice('Competition saved successfully with node ID: @nid', ['@nid' => $saved_nid]);
        
        // Double check that the node exists in the database
        $node_storage = $this->entityTypeManager->getStorage('node');
        $loaded_node = $node_storage->load($saved_nid);
        if ($loaded_node) {
          $this->logger->notice('Competition node verified in database: @title (NID: @nid)', [
            '@title' => $loaded_node->getTitle(),
            '@nid' => $loaded_node->id(),
          ]);
      }
        else {
          $this->logger->error('Competition node could not be verified in database after save: @nid', ['@nid' => $saved_nid]);
        }
        
        // Import standings (clubs) if requested
        if ($import_standings) {
          $this->logger->notice('Importing standings for competition: @title (ID: @id)', [
            '@title' => $loaded_node->getTitle(),
            '@id' => $competition_id,
          ]);
          
          try {
            // Fetch clubs data from the competition clubs endpoint
            $clubs_response = $this->transfermarktApi->getCompetitionClubs($competition_id);
            
            if (!empty($clubs_response) && isset($clubs_response['clubs']) && is_array($clubs_response['clubs'])) {
              $clubs_data = $clubs_response['clubs'];
              
              // Store clubs data in a field for use in the template
              if ($loaded_node->hasField('field_standings_data')) {
                $loaded_node->set('field_standings_data', json_encode($clubs_data));
                $loaded_node->save();
                $this->logger->notice('Saved standings data for @count clubs', ['@count' => count($clubs_data)]);
              }
              
              // Also update the number of clubs if available
              if ($loaded_node->hasField('field_clubs') && isset($clubs_response['clubs']) && is_array($clubs_response['clubs'])) {
                $loaded_node->set('field_clubs', count($clubs_response['clubs']));
                $loaded_node->save();
              }
              
              // We no longer import teams to avoid creating too many nodes
              // $this->importCompetitionStandings($competition_id, $saved_nid);
              $this->logger->notice('Standings data saved to competition node without importing individual teams');
            }
            else {
              $this->logger->warning('No clubs data found for competition ID: @id', ['@id' => $competition_id]);
            }
          }
          catch (\Exception $e) {
            $this->logger->error('Error importing competition standings: @error', ['@error' => $e->getMessage()]);
            // Continue with the import process even if standings import fails
          }
        }
      }
      else {
        $this->logger->error('Competition node ID is empty after save');
      }
      
      return $saved_nid;
    }
    catch (\Exception $e) {
      $this->logger->error('Error importing competition: @error', ['@error' => $e->getMessage()]);
      $this->logger->error('Error trace: @trace', ['@trace' => $e->getTraceAsString()]);
      return NULL;
    }
  }

  /**
   * Gets a competition node by Transfermarkt ID.
   *
   * @param string $competition_id
   *   The Transfermarkt competition ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The competition node or NULL if not found.
   */
  public function getCompetitionByTransfermarktId($competition_id) {
    // Use entity query with access check disabled
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'competition')
      ->condition('field_transfermarkt_id', $competition_id)
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
   * Imports competition standings data.
   *
   * @param string $competition_id
   *   The Transfermarkt competition ID.
   * @param int $competition_nid
   *   The competition node ID.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function importCompetitionStandings($competition_id, $competition_nid) {
    try {
      // Use the dedicated clubs endpoint instead of the competition data endpoint
      $api_response = $this->transfermarktApi->getCompetitionClubs($competition_id);
      
      if (empty($api_response) || !isset($api_response['clubs']) || !is_array($api_response['clubs'])) {
        $this->logger->error('Failed to fetch clubs data for competition ID: @id', ['@id' => $competition_id]);
        return FALSE;
      }
      
      $clubs_data = $api_response['clubs'];
      
      // Get the competition node
      $competition_node = $this->entityTypeManager->getStorage('node')->load($competition_nid);
      if (!$competition_node) {
        $this->logger->error('Competition node not found: @nid', ['@nid' => $competition_nid]);
        return FALSE;
      }
      
      // Store clubs data in the competition node
      if ($competition_node->hasField('field_standings_data')) {
        $competition_node->set('field_standings_data', json_encode($clubs_data));
        $competition_node->save();
        $this->logger->notice('Saved standings data for @count clubs', ['@count' => count($clubs_data)]);
      }
      
      // Update the number of clubs
      if ($competition_node->hasField('field_clubs')) {
        $competition_node->set('field_clubs', count($clubs_data));
        $competition_node->save();
      }
      
      $this->logger->notice('Imported standings data for competition ID @competition_id', [
        '@competition_id' => $competition_id,
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error importing competition standings: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Updates all competition data.
   *
   * @param bool $import_standings
   *   Whether to import competition standings.
   *
   * @return int
   *   The number of competitions updated.
   */
  public function updateAllCompetitions($import_standings = FALSE) {
    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'competition')
        ->condition('field_transfermarkt_id', '', '<>')
        ->accessCheck(FALSE);
      $nids = $query->execute();
      
      $count = 0;
      foreach ($nids as $nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node && $node->hasField('field_transfermarkt_id') && !$node->get('field_transfermarkt_id')->isEmpty()) {
          $competition_id = $node->get('field_transfermarkt_id')->value;
          $this->importCompetition($competition_id, TRUE, $import_standings);
          $count++;
        }
      }
      
      $this->logger->notice('Updated @count competitions', ['@count' => $count]);
      return $count;
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating competitions: @error', ['@error' => $e->getMessage()]);
      return 0;
    }
  }
} 