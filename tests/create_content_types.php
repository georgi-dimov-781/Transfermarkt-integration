<?php

/**
 * @file
 * Script to create content types and fields for Transfermarkt integration.
 *
 * This script can be run via Drush to create the necessary content types and fields.
 * Example: drush scr modules/custom/transfermarkt_integration/tests/create_content_types.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

// Create the player content type if it doesn't exist.
$node_type = NodeType::load('player');
if (!$node_type) {
  $node_type = NodeType::create([
    'type' => 'player',
    'name' => 'Player',
    'description' => 'Football player profile imported from Transfermarkt.',
    'help' => '',
    'new_revision' => TRUE,
    'preview_mode' => 1,
    'display_submitted' => FALSE,
  ]);
  $node_type->save();
  
  // Set the node options.
  \Drupal::configFactory()->getEditable('node.type.player')
    ->set('display_submitted', FALSE)
    ->save();
  
  // Add the node to the menu.
  \Drupal::configFactory()->getEditable('menu_ui.settings.node__player')
    ->set('available_menus', ['main'])
    ->set('parent', 'main:')
    ->save();
  
  echo "Created player content type.\n";
}
else {
  echo "Player content type already exists.\n";
}

// Create the team content type if it doesn't exist.
$node_type = NodeType::load('team');
if (!$node_type) {
  $node_type = NodeType::create([
    'type' => 'team',
    'name' => 'Team',
    'description' => 'Football team profile imported from Transfermarkt.',
    'help' => '',
    'new_revision' => TRUE,
    'preview_mode' => 1,
    'display_submitted' => FALSE,
  ]);
  $node_type->save();
  
  // Set the node options.
  \Drupal::configFactory()->getEditable('node.type.team')
    ->set('display_submitted', FALSE)
    ->save();
  
  // Add the node to the menu.
  \Drupal::configFactory()->getEditable('menu_ui.settings.node__team')
    ->set('available_menus', ['main'])
    ->set('parent', 'main:')
    ->save();
  
  echo "Created team content type.\n";
}
else {
  echo "Team content type already exists.\n";
}

// Create the competition content type if it doesn't exist.
$node_type = NodeType::load('competition');
if (!$node_type) {
  $node_type = NodeType::create([
    'type' => 'competition',
    'name' => 'Competition',
    'description' => 'Football competition profile imported from Transfermarkt.',
    'help' => '',
    'new_revision' => TRUE,
    'preview_mode' => 1,
    'display_submitted' => FALSE,
  ]);
  $node_type->save();
  
  // Set the node options.
  \Drupal::configFactory()->getEditable('node.type.competition')
    ->set('display_submitted', FALSE)
    ->save();
  
  // Add the node to the menu.
  \Drupal::configFactory()->getEditable('menu_ui.settings.node__competition')
    ->set('available_menus', ['main'])
    ->set('parent', 'main:')
    ->save();
  
  echo "Created competition content type.\n";
}
else {
  echo "Competition content type already exists.\n";
}

// Create the vocabularies if they don't exist.
$vocabularies = [
  'nationality' => 'Nationality',
  'position' => 'Position',
  'country' => 'Country',
  'competition_type' => 'Competition Type',
];

foreach ($vocabularies as $vid => $name) {
  $vocabulary = Vocabulary::load($vid);
  if (!$vocabulary) {
    $vocabulary = Vocabulary::create([
      'vid' => $vid,
      'name' => $name,
    ]);
    $vocabulary->save();
    echo "Created $name vocabulary.\n";
  }
  else {
    echo "$name vocabulary already exists.\n";
  }
}

// Define the fields to create.
$fields = [
  'field_transfermarkt_id' => [
    'type' => 'string',
    'label' => 'Transfermarkt ID',
    'bundles' => ['player', 'team', 'competition'],
  ],
  'field_age' => [
    'type' => 'integer',
    'label' => 'Age',
    'bundles' => ['player'],
  ],
  'field_date_of_birth' => [
    'type' => 'datetime',
    'label' => 'Date of Birth',
    'bundles' => ['player'],
    'settings' => ['datetime_type' => 'date'],
  ],
  'field_nationality' => [
    'type' => 'entity_reference',
    'label' => 'Nationality',
    'bundles' => ['player'],
    'settings' => ['target_type' => 'taxonomy_term', 'handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['nationality' => 'nationality']]],
  ],
  'field_position' => [
    'type' => 'entity_reference',
    'label' => 'Position',
    'bundles' => ['player'],
    'settings' => ['target_type' => 'taxonomy_term', 'handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['position' => 'position']]],
  ],
  'field_market_value' => [
    'type' => 'integer',
    'label' => 'Market Value',
    'bundles' => ['player', 'team'],
  ],
  'field_current_club' => [
    'type' => 'entity_reference',
    'label' => 'Current Club',
    'bundles' => ['player'],
    'settings' => ['target_type' => 'node', 'handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['team' => 'team']]],
  ],
  'field_photo' => [
    'type' => 'entity_reference',
    'label' => 'Photo',
    'bundles' => ['player'],
    'settings' => ['target_type' => 'file', 'handler' => 'default:file'],
  ],
  'field_team_name' => [
    'type' => 'string',
    'label' => 'Team Name',
    'bundles' => ['team'],
  ],
  'field_country' => [
    'type' => 'entity_reference',
    'label' => 'Country',
    'bundles' => ['team', 'competition'],
    'settings' => ['target_type' => 'taxonomy_term', 'handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['country' => 'country']]],
  ],
  'field_league' => [
    'type' => 'entity_reference',
    'label' => 'League',
    'bundles' => ['team'],
    'settings' => ['target_type' => 'node', 'handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['competition' => 'competition']]],
  ],
  'field_logo' => [
    'type' => 'entity_reference',
    'label' => 'Logo',
    'bundles' => ['team'],
    'settings' => ['target_type' => 'file', 'handler' => 'default:file'],
  ],
  'field_competition_name' => [
    'type' => 'string',
    'label' => 'Competition Name',
    'bundles' => ['competition'],
  ],
  'field_season' => [
    'type' => 'string',
    'label' => 'Season',
    'bundles' => ['competition'],
  ],
  'field_competition_type' => [
    'type' => 'entity_reference',
    'label' => 'Competition Type',
    'bundles' => ['competition'],
    'settings' => ['target_type' => 'taxonomy_term', 'handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['competition_type' => 'competition_type']]],
  ],
];

// Create the fields.
foreach ($fields as $field_name => $field_info) {
  // Check if the field storage exists.
  $field_storage = FieldStorageConfig::loadByName('node', $field_name);
  if (!$field_storage) {
    // Create the field storage.
    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => $field_info['type'],
      'settings' => $field_info['settings'] ?? [],
      'cardinality' => 1,
    ]);
    $field_storage->save();
    echo "Created field storage for $field_name.\n";
  }
  else {
    echo "Field storage for $field_name already exists.\n";
  }
  
  // Create the field instance for each bundle.
  foreach ($field_info['bundles'] as $bundle) {
    $field = FieldConfig::loadByName('node', $bundle, $field_name);
    if (!$field) {
      $field = FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => $bundle,
        'label' => $field_info['label'],
        'required' => FALSE,
        'settings' => $field_info['settings'] ?? [],
      ]);
      $field->save();
      echo "Created field instance for $field_name on $bundle.\n";
      
      // Add the field to the form display.
      \Drupal::service('entity_display.repository')
        ->getFormDisplay('node', $bundle, 'default')
        ->setComponent($field_name, [
          'type' => $field_info['type'] === 'entity_reference' ? 'entity_reference_autocomplete' : 'string_textfield',
          'weight' => 10,
        ])
        ->save();
      
      // Add the field to the view display.
      \Drupal::service('entity_display.repository')
        ->getViewDisplay('node', $bundle, 'default')
        ->setComponent($field_name, [
          'type' => $field_info['type'] === 'entity_reference' ? 'entity_reference_label' : 'string',
          'weight' => 10,
        ])
        ->save();
    }
    else {
      echo "Field instance for $field_name on $bundle already exists.\n";
    }
  }
}

echo "\nDone creating content types and fields.\n"; 