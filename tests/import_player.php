<?php

/**
 * @file
 * Script to import a player from Transfermarkt.
 *
 * This script can be run via Drush to import a player.
 * Example: drush scr modules/custom/transfermarkt_integration/tests/import_player.php
 */

// Get the player service.
$player_service = \Drupal::service('transfermarkt_integration.player_service');

// Import player with ID 39983 (Lionel Messi).
$player_id = '39983';
echo "Importing player with ID: $player_id\n";
$nid = $player_service->importPlayer($player_id);

if ($nid) {
  echo "Player imported successfully with node ID: $nid\n";
  
  // Load the player node to check its fields.
  $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
  if ($node) {
    echo "Player node details:\n";
    echo "- Title: " . $node->getTitle() . "\n";
    echo "- ID: " . $node->id() . "\n";
    echo "- Created: " . date('Y-m-d H:i:s', $node->getCreatedTime()) . "\n";
    echo "- Status: " . ($node->isPublished() ? 'Published' : 'Unpublished') . "\n";
    
    // Check the field values.
    $fields = [
      'field_transfermarkt_id',
      'field_age',
      'field_date_of_birth',
      'field_nationality',
      'field_position',
      'field_market_value',
      'field_current_club',
      'field_photo',
    ];
    
    echo "\nField values:\n";
    foreach ($fields as $field_name) {
      if ($node->hasField($field_name)) {
        $field = $node->get($field_name);
        $value = $field->isEmpty() ? 'Empty' : print_r($field->getValue(), true);
        echo "- $field_name: $value\n";
      }
    }
  }
}
else {
  echo "Failed to import player.\n";
}

echo "\nDone.\n"; 