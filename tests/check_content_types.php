<?php

/**
 * @file
 * Test script to check content types and fields for Transfermarkt integration.
 *
 * This script can be run via Drush to check content types and fields.
 * Example: drush scr modules/custom/transfermarkt_integration/tests/check_content_types.php
 */

// Get the entity type manager.
$entity_type_manager = \Drupal::entityTypeManager();
$entity_field_manager = \Drupal::service('entity_field.manager');
$bundle_info = \Drupal::service('entity_type.bundle.info');

// Check if the player content type exists.
$node_types = $bundle_info->getBundleInfo('node');
echo "Available content types:\n";
foreach ($node_types as $type => $info) {
  echo "- $type: {$info['label']}\n";
}

// Check if the player content type exists.
$player_exists = isset($node_types['player']);
echo "\nPlayer content type exists: " . ($player_exists ? 'Yes' : 'No') . "\n";

if ($player_exists) {
  // Get the field definitions for the player content type.
  $field_definitions = $entity_field_manager->getFieldDefinitions('node', 'player');
  echo "\nFields for player content type:\n";
  foreach ($field_definitions as $field_name => $field_definition) {
    $type = $field_definition->getType();
    $label = $field_definition->getLabel();
    echo "- $field_name ($type): $label\n";
  }
  
  // Check if the required fields exist.
  $required_fields = [
    'field_transfermarkt_id',
    'field_age',
    'field_date_of_birth',
    'field_nationality',
    'field_position',
    'field_market_value',
    'field_current_club',
    'field_photo',
  ];
  
  echo "\nRequired fields:\n";
  foreach ($required_fields as $field_name) {
    $exists = isset($field_definitions[$field_name]);
    echo "- $field_name: " . ($exists ? 'Exists' : 'Missing') . "\n";
  }
  
  // Check if there are any player nodes.
  $query = $entity_type_manager->getStorage('node')->getQuery()
    ->condition('type', 'player')
    ->accessCheck(FALSE);
  $result = $query->execute();
  
  echo "\nPlayer nodes: " . count($result) . "\n";
  if (!empty($result)) {
    echo "Player node IDs: " . implode(', ', $result) . "\n";
    
    // Load the first player node to check its fields.
    $player_id = reset($result);
    $player = $entity_type_manager->getStorage('node')->load($player_id);
    
    if ($player) {
      echo "\nFirst player node:\n";
      echo "- Title: " . $player->getTitle() . "\n";
      echo "- ID: " . $player->id() . "\n";
      echo "- Created: " . date('Y-m-d H:i:s', $player->getCreatedTime()) . "\n";
      echo "- Status: " . ($player->isPublished() ? 'Published' : 'Unpublished') . "\n";
      
      // Check the field values.
      echo "\nField values:\n";
      foreach ($required_fields as $field_name) {
        if ($player->hasField($field_name)) {
          $field = $player->get($field_name);
          $value = $field->isEmpty() ? 'Empty' : print_r($field->getValue(), true);
          echo "- $field_name: $value\n";
        }
      }
    }
  }
}

// Check if the team content type exists.
$team_exists = isset($node_types['team']);
echo "\nTeam content type exists: " . ($team_exists ? 'Yes' : 'No') . "\n";

// Check if the competition content type exists.
$competition_exists = isset($node_types['competition']);
echo "\nCompetition content type exists: " . ($competition_exists ? 'Yes' : 'No') . "\n";

echo "\nDone.\n"; 