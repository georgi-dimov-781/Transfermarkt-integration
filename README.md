# Transfermarkt Integration for Drupal

A comprehensive Drupal module that integrates football data from Transfermarkt into your Drupal website via open source transfermarkt-api https://transfermarkt-api.fly.dev/. Display up-to-date information about football players, teams, and competitions with customizable templates and data management tools.

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Requirements](#requirements)
4. [Installation](#installation)
5. [Configuration](#configuration)
6. [Content Types](#content-types)
7. [Data Importing](#data-importing)
8. [Drush Commands](#drush-commands)
9. [Blocks](#blocks)
10. [Theming](#theming)
11. [Permissions](#permissions)
12. [API Usage](#api-usage)
13. [Cron Integration](#cron-integration)
14. [Database Structure](#database-structure)
15. [Performance Considerations](#performance-considerations)
16. [Troubleshooting](#troubleshooting)
17. [Uninstallation](#uninstallation)
18. [About Transfermarkt](#about-transfermarkt)
19. [Credits](#credits)
20. [License](#license)

## Overview

The Transfermarkt Integration module connects your Drupal site to Transfermarkt, one of the world's leading football databases. It allows you to import and display comprehensive information about players, teams, and competitions directly on your Drupal website. The module handles all aspects of the integration from API communication to content creation, storage, and display.

## Features

- **Comprehensive Data Import**: Import players, teams, and competitions with all their associated data.
- **Automated Updates**: Keep your football data current with configurable cron updates.
- **Multiple Content Types**: Pre-configured content types for players, teams, and competitions.
- **Custom Taxonomies**: Vocabularies for positions, nationalities, countries, and competition types.
- **Image Handling**: Automatic import of player photos and team logos.
- **Custom Display Blocks**: Ready-to-use blocks for top valuable players and latest transfers. (Latest transfer block is filled with random data just for presentation)
- **Team Squad Management**: View and import entire team squads with a dedicated interface.
- **Flexible Templating**: Customizable templates for all football-related content.
- **Drush Commands**: CLI commands for importing and managing data.
- **Search Integration**: Built-in search functionality for finding players and teams.
- **Market Value Tracking**: Track and display player and team market values.


## Requirements

- Drupal 9.x or 10.x
- PHP 7.4 or higher
- The following Drupal core modules:
  - Node
  - Field
  - Image
  - Views
  - Taxonomy
  - Automated Cron
- Write access to the public files directory

## Installation

1. Download and place the module in your Drupal installation under `/modules/custom/`
2. Enable the module using Drush:
   ```
   drush en transfermarkt_integration
   ```
3. Navigate to `/admin/config/services/transfermarkt` to configure the module
4. Import your first data sets using the import forms or Drush commands

## Content Types

The module creates three custom content types with appropriate fields:

### Player Content Type
- **Basic Information**: Name, age, date of birth
- **Football Details**: Position, nationality, market value
- **Team Affiliation**: Current club with reference to team content
- **Media**: Player photo
- **Identification**: Transfermarkt ID for automated updates

### Team Content Type
- **Basic Information**: Team name, country
- **Football Details**: League/competition, market value
- **Media**: Team logo
- **Squad Management**: Links to view and import team squad
- **Identification**: Transfermarkt ID for automated updates

### Competition Content Type
- **Basic Information**: Competition name, country, season
- **Classification**: Competition type (league, cup, etc.)
- **Data**: Standings and other competition-specific information
- **Identification**: Transfermarkt ID for automated updates

## Data Importing

### Import Forms

The module provides user-friendly forms for importing data:

1. **Player Import** (`/admin/config/services/transfermarkt/import-player`):
   - Search for players by name
   - Select from search results
   - Import directly by Transfermarkt ID

2. **Team Import** (`/admin/config/services/transfermarkt/import-team`):
   - Search for teams by name
   - Select from search results
   - Import directly by Transfermarkt ID
   - Option to import team squad along with the team

3. **Competition Import** (`/admin/config/services/transfermarkt/import-competition`):
   - Search for competitions by name
   - Select from search results
   - Import directly by Transfermarkt ID
   - Option to import teams participating in the competition

### Batch Importing

For importing large datasets, the module provides a batch processing system:

1. **Update Form** (`/admin/config/services/transfermarkt/update`):
   - Update all existing players
   - Update all existing teams
   - Update all existing competitions

## Drush Commands

The module provides several Drush commands for command-line operations:

```
drush tm:team TEAM_ID                # Import a team (alias: tm-team)
drush tm:player PLAYER_ID            # Import a player (alias: tm-player)
drush tm:competition COMPETITION_ID  # Import a competition (alias: tm-competition)
```

Examples:
```
# Import Chelsea FC
drush tm:team 631

# Import a player with ID 182877
drush tm:player 182877

# Import Premier League
drush tm:competition GB1

# Import with update option
drush tm:team 11 --update
```

## Blocks

The module provides custom blocks that can be placed in any region of your theme:
### Top Valuable Players Block
### Latest Transfers Block

## Theming

### Custom Templates
The module includes customizable Twig templates for all content:

- **Player Display**: `node--player--full.html.twig`
- **Team Display**: `node--team--full.html.twig`
- **Competition Display**: `node--competition--full.html.twig`
- **Player Card**: `transfermarkt-player-card.html.twig`
- **Team Card**: `transfermarkt-team-card.html.twig`
- **Team Squad List**: `transfermarkt-team-squad-list.html.twig`
- **View Templates**:
  - `views-view--players.html.twig`
  - `views-view--teams.html.twig`
  - `views-view--competitions.html.twig`
  - Various view field and table templates

### CSS Styling
The module includes basic CSS that can be extended:

- Base CSS file: `css/transfermarkt_integration.css`
- Library definition: `transfermarkt_styles` in `transfermarkt_integration.libraries.yml`

To override the default styles, you can:
1. Override the CSS file in your theme
2. Add custom CSS in your theme that targets the module's elements
3. Create a custom library that replaces the module's library

## Permissions

The module defines two permissions:

1. **Administer Transfermarkt Integration**:
   - Access to all configuration forms
   - Import and update operations
   - Full administrative control

2. **View Transfermarkt Data**:
   - Access to view player, team, and competition data
   - Suitable for regular site users

Configure these permissions at `/admin/people/permissions`.

## API Usage

You can use the module's services in your custom code:

```php
// Get the API service
$api_service = \Drupal::service('transfermarkt_integration.api_service');

// Get the player service
$player_service = \Drupal::service('transfermarkt_integration.player_service');

// Get the team service
$team_service = \Drupal::service('transfermarkt_integration.team_service');

// Get the competition service
$competition_service = \Drupal::service('transfermarkt_integration.competition_service');

// Import a player
$player_id = $player_service->importPlayer('182877');

// Import a team
$team_id = $team_service->importTeam('631');

// Import a competition
$competition_id = $competition_service->importCompetition('GB1');

// Get top valuable players
$top_players = $api_service->getTopValuablePlayers(10);

// Search for players
$search_results = $api_service->searchPlayers('Messi');
```

## Cron Integration

The module uses Drupal's cron system to update data at configured intervals:

- Updates are triggered via `hook_cron()` in `transfermarkt_integration.module`
- The update interval is configurable in the module settings
- The module tracks the last update time using Drupal's state API
- During updates, the module refreshes market values and other volatile data

To trigger an immediate update, you can run:
```
drush cron
```

## Database Structure

The module uses Drupal's entity system to store data:

- **Players, Teams, Competitions**: Stored as Drupal nodes with custom fields
- **Positions, Nationalities, Countries**: Stored as taxonomy terms
- **Photos and Logos**: Stored as managed files in the Drupal file system
- **Transfermarkt IDs**: Stored in dedicated fields for reference and updates
- **Relationships**: Entity references connect related content (e.g., player to team)

## Performance Considerations

- **Caching**: API responses are cached to reduce external requests
- **Batch Processing**: Large imports use Drupal's batch API to prevent timeouts
- **Selective Updates**: Only changed data is updated during cron runs
- **Image Optimization**: Player photos and team logos are stored locally
- **Database Indexes**: Custom indexes on Transfermarkt ID fields for faster lookups

## Troubleshooting

### Common Issues

1. **API Connection Errors**:
   - Verify your API settings in the module configuration
   - Check your server's outbound connection to Transfermarkt

2. **Image Import Problems**:
   - Verify that the `public://transfermarkt` directory is writable
   - Check PHP's memory limit if importing many images

3. **Missing Player or Team Data**:
   - Verify the Transfermarkt ID is correct
   - Some players or teams may have limited data available

4. **Module Uninstallation Issues**:
   - Use Drush to uninstall: `drush pmu transfermarkt_integration`
   - The module properly cleans up all content and configuration on uninstall

### Debugging

- Enable Drupal's logging: `$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';`
- Check Drupal logs: `/admin/reports/dblog`
- Use the test commands: `drush transfermarkt:test-image URL FILENAME`

## Uninstallation

The module provides a complete uninstallation process that:

1. Removes all players, teams, and competitions created by the module
2. Deletes all taxonomy terms in module-created vocabularies
3. Removes all uploaded files (player photos, team logos)
4. Cleans up all configuration
5. Removes any database tables and fields

To uninstall:
```
drush pmu transfermarkt_integration
```

## About Transfermarkt

[Transfermarkt](https://www.transfermarkt.com/) is one of the world's leading football (soccer) databases, providing comprehensive information about players, teams, competitions, transfers, and market values. The website was founded in 2000 and has become a reference for football data worldwide.
