<?php

/**
 * @file
 * Test script for Transfermarkt API integration.
 *
 * This script can be run via Drush to test the API integration.
 * Example: drush scr transfermarkt_api_test.php
 */

use Drupal\Core\Url;

// Get the API service.
$api_service = \Drupal::service('transfermarkt_integration.api_service');

// Test API base URL.
$api_base_url = \Drupal::config('transfermarkt_integration.settings')->get('api_base_url');
echo "API Base URL: $api_base_url\n";

try {
  // Test player search.
  echo "\nTesting Player Search API...\n";
  $player_search_results = $api_service->searchPlayers('Messi');
  echo "Player Search Results: " . count($player_search_results['results']) . " players found\n";
  if (!empty($player_search_results['results'])) {
    $player = reset($player_search_results['results']);
    echo "First Player: {$player['name']} (ID: {$player['id']})\n";
    
    // Test player profile.
    echo "\nTesting Player Profile API...\n";
    $player_id = $player['id'];
    $player_data = $api_service->getPlayerData($player_id);
    echo "Player Profile: {$player_data['name']} (Age: {$player_data['age']})\n";
    
    // Test player market value.
    echo "\nTesting Player Market Value API...\n";
    $market_value_data = $api_service->getPlayerMarketValue($player_id);
    echo "Player Market Value: " . ($market_value_data['marketValue'] ?? 'N/A') . "\n";
  }
  
  // Test team search.
  echo "\nTesting Team Search API...\n";
  $team_search_results = $api_service->searchTeams('Barcelona');
  echo "Team Search Results: " . count($team_search_results['results']) . " teams found\n";
  if (!empty($team_search_results['results'])) {
    $team = reset($team_search_results['results']);
    echo "First Team: {$team['name']} (ID: {$team['id']})\n";
    
    // Test team profile.
    echo "\nTesting Team Profile API...\n";
    $team_id = $team['id'];
    $team_data = $api_service->getTeamData($team_id);
    echo "Team Profile: {$team_data['name']} (Stadium: {$team_data['stadiumName']})\n";
    
    // Test team squad.
    echo "\nTesting Team Squad API...\n";
    $squad_data = $api_service->getTeamSquad($team_id);
    echo "Team Squad: " . count($squad_data['players']) . " players found\n";
  }
  
  // Test competition search.
  echo "\nTesting Competition Search API...\n";
  $competition_search_results = $api_service->searchCompetitions('Premier League');
  echo "Competition Search Results: " . count($competition_search_results['results']) . " competitions found\n";
  if (!empty($competition_search_results['results'])) {
    $competition = reset($competition_search_results['results']);
    echo "First Competition: {$competition['name']} (ID: {$competition['id']})\n";
    
    // Test competition data.
    echo "\nTesting Competition Data API...\n";
    $competition_id = $competition['id'];
    $competition_data = $api_service->getCompetitionData($competition_id);
    echo "Competition Data: {$competition_data['name']} (Season: {$competition_data['seasonId']})\n";
    echo "Competition Clubs: " . count($competition_data['clubs']) . " clubs found\n";
  }
  
  echo "\nAll API tests completed successfully!\n";
} 
catch (\Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
} 