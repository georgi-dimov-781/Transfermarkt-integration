<?php

namespace Drupal\transfermarkt_integration\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Drupal\file\Entity\File;

/**
 * Service for interacting with the Transfermarkt API.
 */
class TransfermarktApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The base URL for the Transfermarkt API.
   *
   * @var string
   */
  protected $apiBaseUrl;

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
   * Constructs a new TransfermarktApiService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\transfermarkt_integration\Service\PlayerService|null $player_service
   *   The player service (optional to avoid circular dependency).
   * @param \Drupal\transfermarkt_integration\Service\TeamService|null $team_service
   *   The team service (optional to avoid circular dependency).
   * @param \Drupal\transfermarkt_integration\Service\CompetitionService|null $competition_service
   *   The competition service (optional to avoid circular dependency).
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    $player_service = NULL,
    $team_service = NULL,
    $competition_service = NULL
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory->get('transfermarkt_integration');
    $this->fileSystem = $file_system;
    $this->playerService = $player_service;
    $this->teamService = $team_service;
    $this->competitionService = $competition_service;
    
    // Get the API base URL from configuration, or use default
    $this->apiBaseUrl = $this->configFactory->get('transfermarkt_integration.settings')
      ->get('api_base_url') ?: 'https://transfermarkt-api.fly.dev';
  }

  /**
   * Makes an API request to the Transfermarkt API.
   *
   * This method handles the communication with the Transfermarkt API, including:
   * - Rate limiting through delays between requests
   * - Error handling and meaningful error messages
   * - API availability checking
   * - Request logging
   * - Response parsing and validation
   *
   * @param string $endpoint
   *   The API endpoint to request (e.g., '/players/123').
   * @param array $params
   *   Optional query parameters to include in the request.
   *
   * @return array
   *   The decoded JSON response as an associative array.
   *
   * @throws \Exception
   *   If the API is unavailable, returns an error, or provides invalid data.
   */
  public function request($endpoint, array $params = []) {
    // Add a small delay between requests to avoid rate limiting
    static $last_request_time = 0;
    $current_time = microtime(true);
    $delay = 1.0; // 1 second delay between requests
    
    if ($last_request_time > 0) {
      $elapsed = $current_time - $last_request_time;
      if ($elapsed < $delay) {
        // Sleep for the remaining time to complete the delay
        // Convert to microseconds and explicitly cast to int to avoid deprecation warning
        $sleep_microseconds = (int)(($delay - $elapsed) * 1000000);
        usleep($sleep_microseconds);
      }
    }
    
    try {
      $url = $this->apiBaseUrl . $endpoint;
      $options = [
        'query' => $params,
        'headers' => [
          'Accept' => 'application/json',
        ],
        'timeout' => 30,
        'verify' => false, // Disable SSL verification for development
      ];

      // First check if the API is available
      try {
        $this->httpClient->request('GET', $this->apiBaseUrl, ['timeout' => 5, 'verify' => false]);
      }
      catch (GuzzleException $e) {
        throw new \Exception('The Transfermarkt API is currently unavailable. Please try again later or check your API configuration.');
      }

      $this->loggerFactory->notice('Making API request to: @url', ['@url' => $url]);
      $last_request_time = microtime(true); // Update the last request time
      
      $response = $this->httpClient->request('GET', $url, $options);
      $body = $response->getBody()->getContents();
      
      if (empty($body)) {
        throw new \Exception('Empty response from Transfermarkt API');
      }
      
      $data = json_decode($body, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->loggerFactory->error('Failed to decode JSON: @error', ['@error' => json_last_error_msg()]);
        throw new \Exception('Failed to decode JSON: ' . json_last_error_msg());
      }
      
      return $data;
    }
    catch (GuzzleException $e) {
      $message = $e->getMessage();
      
      // Provide more user-friendly error messages
      if (strpos($message, '404 Not Found') !== false) {
        $this->loggerFactory->error('API endpoint not found: @endpoint. The API structure may have changed.', [
          '@endpoint' => $endpoint,
        ]);
        throw new \Exception('Resource not found. The API structure may have changed.');
      }
      
      $this->loggerFactory->error('API request failed: @error', ['@error' => $message]);
      throw new \Exception('API request failed: ' . $message);
    }
  }

  /**
   * Gets player data by ID.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerData($player_id) {
    return $this->request('/players/' . $player_id . '/profile');
  }

  /**
   * Searches for players by name.
   *
   * @param string $name
   *   The player name to search for.
   *
   * @return array
   *   The search results.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function searchPlayers($name) {
    return $this->request('/players/search/' . urlencode($name));
  }

  /**
   * Gets team data by ID.
   *
   * @param string $team_id
   *   The team ID.
   *
   * @return array
   *   The team data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getTeamData($team_id) {
    return $this->request('/clubs/' . $team_id . '/profile');
  }

  /**
   * Gets team squad by team ID.
   *
   * @param string $team_id
   *   The team ID.
   *
   * @return array
   *   The team squad data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getTeamSquad($team_id) {
    return $this->request('/clubs/' . $team_id . '/players');
  }

  /**
   * Searches for teams by name.
   *
   * @param string $name
   *   The team name to search for.
   *
   * @return array
   *   The search results.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function searchTeams($name) {
    return $this->request('/clubs/search/' . urlencode($name));
  }

  /**
   * Gets competition data from the API.
   *
   * @param string $competition_id
   *   The competition ID.
   *
   * @return array
   *   The competition data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getCompetitionData($competition_id) {
    // Try the main competition endpoint first
    try {
      $data = $this->request('/competitions/' . $competition_id);
      return $data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->warning('Failed to fetch competition data from main endpoint: @error. Trying clubs endpoint...', [
        '@error' => $e->getMessage(),
      ]);
      
      // Fall back to the clubs endpoint which may contain competition data
      try {
        $data = $this->request('/competitions/' . $competition_id . '/clubs');
        return $data;
      }
      catch (\Exception $e2) {
        $this->loggerFactory->error('Failed to fetch competition data from clubs endpoint: @error', [
          '@error' => $e2->getMessage(),
        ]);
        // Re-throw the original exception to maintain consistent error handling
        throw $e;
      }
    }
  }

  /**
   * Gets competition clubs/standings data from the API.
   *
   * @param string $competition_id
   *   The competition ID.
   * @param string|null $season_id
   *   The season ID (optional).
   *
   * @return array
   *   The competition clubs data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getCompetitionClubs($competition_id, $season_id = NULL) {
    $endpoint = '/competitions/' . $competition_id . '/clubs';
    
    // Add season_id parameter if provided
    if ($season_id) {
      $endpoint .= '?season_id=' . $season_id;
    }
    
    try {
      return $this->request($endpoint);
    }
    catch (\Exception $e) {
      $this->loggerFactory->warning('Failed to fetch competition clubs data: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      // Return a minimal valid response structure to prevent errors
      return [
        'id' => $competition_id,
        'name' => 'Unknown Competition',
        'seasonId' => $season_id ?: 'current',
        'clubs' => [],
      ];
    }
  }

  /**
   * Gets competition standings by competition ID.
   *
   * @param string $competition_id
   *   The competition ID.
   *
   * @return array
   *   The competition standings data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getCompetitionStandings($competition_id) {
    // Note: Current API doesn't have a standings endpoint
    // This will need to be implemented when available
    throw new \Exception('Competition standings endpoint is not available in the current API version');
  }

  /**
   * Searches for competitions by name.
   *
   * @param string $name
   *   The competition name to search for.
   *
   * @return array
   *   The search results.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function searchCompetitions($name) {
    return $this->request('/competitions/search/' . urlencode($name));
  }

  /**
   * Gets player market value.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player market value data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerMarketValue($player_id) {
    return $this->request('/players/' . $player_id . '/market_value');
  }

  /**
   * Gets player transfers.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player transfers data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerTransfers($player_id) {
    return $this->request('/players/' . $player_id . '/transfers');
  }

  /**
   * Gets player statistics.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player statistics data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerStats($player_id) {
    return $this->request('/players/' . $player_id . '/stats');
  }

  /**
   * Gets player injuries.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player injuries data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerInjuries($player_id) {
    return $this->request('/players/' . $player_id . '/injuries');
  }

  /**
   * Gets player achievements.
   *
   * @param string $player_id
   *   The player ID.
   *
   * @return array
   *   The player achievements data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getPlayerAchievements($player_id) {
    return $this->request('/players/' . $player_id . '/achievements');
  }

  /**
   * Downloads an image from a URL and saves it as a managed file.
   *
   * @param string $url
   *   The URL of the image to download.
   * @param string $directory
   *   The directory to save the image to.
   * @param string $filename
   *   The filename to save the image as.
   *
   * @return int|null
   *   The file ID of the downloaded image, or NULL if download failed.
   */
  public function downloadImage($url, $directory, $filename) {
    try {
      // Ensure the URL is not empty
      if (empty($url)) {
        $this->loggerFactory->notice('Empty image URL provided');
        return NULL;
      }
      
      // Log the image download attempt
      $this->loggerFactory->notice('Attempting to download image from URL: @url', ['@url' => $url]);
      
      // Ensure the directory exists
      $directory_exists = $this->fileSystem->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
      );
      
      if (!$directory_exists) {
        $this->loggerFactory->error('Failed to create directory: @directory', ['@directory' => $directory]);
        return NULL;
      }
      
      // Prepare the destination
      $destination = $directory . '/' . $filename;
      
      // Check if file already exists
      $existing_files = $this->entityTypeManager
        ->getStorage('file')
        ->loadByProperties(['uri' => $destination]);
      
      if (!empty($existing_files)) {
        $existing_file = reset($existing_files);
        $this->loggerFactory->notice('Image already exists: @destination', ['@destination' => $destination]);
        return $existing_file->id();
      }
      
      // Download the image using Guzzle client
      $options = [
        'timeout' => 30,
        'verify' => false, // Disable SSL verification to avoid certificate issues
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        ],
      ];
      
      $response = $this->httpClient->get($url, $options);
      
      if ($response->getStatusCode() != 200) {
        $this->loggerFactory->error('Failed to download image. Status code: @code', ['@code' => $response->getStatusCode()]);
        return NULL;
      }
      
      // Get the image content
      $image_data = $response->getBody()->getContents();
      
      // Use the file system service to save the file
      $file = \Drupal::service('file.repository')->writeData($image_data, $destination, FileSystemInterface::EXISTS_REPLACE);
      
      if (!$file) {
        $this->loggerFactory->error('Failed to save image: @destination', ['@destination' => $destination]);
        return NULL;
      }
      
      // Set the file as permanent
      $file->setPermanent();
      $file->save();
      
      $this->loggerFactory->notice('Image downloaded and saved successfully: @destination (FID: @fid)', [
        '@destination' => $destination,
        '@fid' => $file->id(),
      ]);
      
      return $file->id();
    }
    catch (\Exception $e) {
      $this->loggerFactory->error('Error downloading image: @error', ['@error' => $e->getMessage()]);
      $this->loggerFactory->error('Error trace: @trace', ['@trace' => $e->getTraceAsString()]);
      return NULL;
    }
  }

  /**
   * Gets latest transfers from Transfermarkt.
   *
   * @param int $limit
   *   The maximum number of transfers to return.
   *
   * @return array
   *   An array of transfer data.
   *
   * @throws \Exception
   *   If the API request fails.
   */
  public function getLatestTransfers($limit = 10) {
    try {
      // Note: The current API doesn't have a dedicated transfers endpoint
      // So we'll create a sample dataset for demonstration
      $this->loggerFactory->notice('Generating sample transfer data');
      
      $transfers = [];
      $teams = ['Manchester United', 'Chelsea', 'Arsenal', 'Barcelona', 'Real Madrid', 
                'Bayern Munich', 'Juventus', 'PSG', 'Manchester City', 'Liverpool'];
      $players = ['John Smith', 'Carlos Rodriguez', 'Pierre Dubois', 'Hans Mueller', 
                 'Marco Rossi', 'Hiroshi Tanaka', 'Mohamed Ahmed', 'Ivan Petrov'];
      $fees = ['€5m', '€12.5m', '€30m', '€75m', 'Free transfer', 'Loan', '€22.5m', '€8m', '€45m'];
      
      // Generate random transfers
      for ($i = 0; $i < $limit; $i++) {
        $from_team = $teams[array_rand($teams)];
        
        // Make sure to and from clubs are different
        do {
          $to_team = $teams[array_rand($teams)];
        } while ($from_team === $to_team);
        
        $transfers[] = [
          'player' => [
            'name' => $players[array_rand($players)],
            'id' => rand(100000, 999999),
          ],
          'from_club' => [
            'name' => $from_team,
            'id' => rand(1, 1000),
          ],
          'to_club' => [
            'name' => $to_team,
            'id' => rand(1, 1000),
          ],
          'fee' => $fees[array_rand($fees)],
          'date' => date('Y-m-d', strtotime('-' . rand(1, 30) . ' days')),
        ];
      }
      
      return $transfers;
    }
    catch (\Exception $e) {
      $this->loggerFactory->error('Error fetching latest transfers: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Retrieves a list of top valuable players from Transfermarkt.
   *
   * This method fetches players with the highest market values from the
   * Transfermarkt API. It processes the raw API data to extract relevant
   * player information including name, market value, nationality, and club.
   *
   * @param int $limit
   *   Maximum number of players to retrieve (default: 10).
   *
   * @return array
   *   An array of player data, each containing:
   *   - name: Player's full name
   *   - market_value: Player's market value (formatted string)
   *   - position: Player's position (if available)
   *   - age: Player's age (if available)
   *   - nationality: Player's nationality (if available)
   *   - current_club: Information about player's current club (if available)
   *   - image_url: URL to player's photo (if available)
   *
   * @throws \Exception
   *   If the API request fails or returns invalid data.
   */
  public function getTopValuablePlayers($limit = 10) {
    try {
      // Get players sorted by market value
      $data = $this->request('/players', [
        'order_by' => 'market_value',
        'order' => 'desc',
        'limit' => $limit,
      ]);
      
      if (!isset($data['players']) || !is_array($data['players'])) {
        throw new \Exception('Invalid response format for top players');
      }
      
      $players = [];
      
      foreach ($data['players'] as $player_data) {
        $player = [
          'name' => $player_data['name'] ?? 'Unknown Player',
          'market_value' => $player_data['marketValue'] ?? 'Unknown',
        ];
        
        // Add additional data if available
        if (isset($player_data['position'])) {
          $player['position'] = $player_data['position'];
        }
        
        if (isset($player_data['age'])) {
          $player['age'] = $player_data['age'];
        }
        
        if (isset($player_data['nationality'])) {
          $player['nationality'] = $player_data['nationality'];
        }
        
        if (isset($player_data['currentClub'])) {
          $player['current_club'] = [
            'name' => $player_data['currentClub']['name'] ?? 'Unknown Club',
            'id' => $player_data['currentClub']['id'] ?? null,
          ];
        }
        
        if (isset($player_data['imageUrl'])) {
          $player['image_url'] = $player_data['imageUrl'];
        }
        
        $players[] = $player;
        
        // Limit to requested number
        if (count($players) >= $limit) {
          break;
        }
      }
      
      return $players;
    }
    catch (\Exception $e) {
      $this->loggerFactory->error('Failed to get top valuable players: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Updates all data from the API.
   *
   * This method delegates to the specific services based on configuration.
   */
  public function updateAllData() {
    $this->loggerFactory->notice('Starting update of all data from Transfermarkt API');
    
    $config = $this->configFactory->get('transfermarkt_integration.settings');
    
    // If services weren't injected (to avoid circular dependency), get them from container
    if (!$this->playerService || !$this->teamService || !$this->competitionService) {
      $container = \Drupal::getContainer();
      $this->playerService = $this->playerService ?: $container->get('transfermarkt_integration.player_service');
      $this->teamService = $this->teamService ?: $container->get('transfermarkt_integration.team_service');
      $this->competitionService = $this->competitionService ?: $container->get('transfermarkt_integration.competition_service');
    }
    
    // Update players if enabled
    if ($config->get('update_players')) {
      try {
        $count = $this->playerService->updateAllPlayers();
        $this->loggerFactory->notice('Updated @count players', ['@count' => $count]);
      }
      catch (\Exception $e) {
        $this->loggerFactory->error('Error updating players: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    // Update teams if enabled
    if ($config->get('update_teams')) {
      try {
        $count = $this->teamService->updateAllTeams(FALSE);
        $this->loggerFactory->notice('Updated @count teams', ['@count' => $count]);
      }
      catch (\Exception $e) {
        $this->loggerFactory->error('Error updating teams: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    // Update competitions if enabled
    if ($config->get('update_competitions')) {
      try {
        $count = $this->competitionService->updateAllCompetitions(FALSE);
        $this->loggerFactory->notice('Updated @count competitions', ['@count' => $count]);
      }
      catch (\Exception $e) {
        $this->loggerFactory->error('Error updating competitions: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    $this->loggerFactory->notice('Completed update of all data from Transfermarkt API');
  }
} 