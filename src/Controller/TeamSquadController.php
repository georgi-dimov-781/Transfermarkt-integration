<?php

namespace Drupal\transfermarkt_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\transfermarkt_integration\Service\TeamService;
use Drupal\transfermarkt_integration\Service\PlayerService;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;
use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Link;

/**
 * Controller for team squad operations.
 *
 * This controller handles:
 * - Displaying the Transfermarkt squad for a team
 * - Importing individual players from the squad
 * - Access control for these operations
 *
 * This functionality remains available even though the bulk squad import
 * checkbox has been removed from the team import form.
 */
class TeamSquadController extends ControllerBase {

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
   * Constructs a new TeamSquadController.
   *
   * @param \Drupal\transfermarkt_integration\Service\TeamService $team_service
   *   The team service.
   * @param \Drupal\transfermarkt_integration\Service\PlayerService $player_service
   *   The player service.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   */
  public function __construct(
    TeamService $team_service,
    PlayerService $player_service,
    TransfermarktApiService $transfermarkt_api
  ) {
    $this->teamService = $team_service;
    $this->playerService = $player_service;
    $this->transfermarktApi = $transfermarkt_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('transfermarkt_integration.team_service'),
      $container->get('transfermarkt_integration.player_service'),
      $container->get('transfermarkt_integration.api_service')
    );
  }

  /**
   * Displays the squad for a team.
   *
   * This page shows all players from the Transfermarkt API for this team,
   * indicating which ones have already been imported. Administrators can
   * import individual players from this view.
   *
   * Route: /node/{node}/squad
   *
   * @param \Drupal\node\NodeInterface $node
   *   The team node.
   *
   * @return array|RedirectResponse
   *   A render array for the squad page or a redirect response if an error occurs.
   */
  public function showSquad(NodeInterface $node) {
    // Check if this is a team node.
    if ($node->getType() != 'team') {
      $this->messenger()->addError($this->t('This is not a team node.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    // Check if we have a Transfermarkt ID.
    if (!$node->hasField('field_transfermarkt_id') || $node->get('field_transfermarkt_id')->isEmpty()) {
      $this->messenger()->addError($this->t('This team does not have a Transfermarkt ID.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $team_id = $node->get('field_transfermarkt_id')->value;
    $team_nid = $node->id();

    try {
      // Get squad data from API.
      $api_response = $this->transfermarktApi->getTeamSquad($team_id);
      
      if (empty($api_response) || !isset($api_response['players']) || !is_array($api_response['players'])) {
        $this->messenger()->addError($this->t('Failed to fetch squad data for this team.'));
        return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
      }

      $squad_data = $api_response['players'];
      
      // Check which players are already imported by comparing Transfermarkt IDs
      // Create a mapping of player_id => node_id for highlighting in the UI
      $imported_players = [];
      foreach ($squad_data as $player_data) {
        if (isset($player_data['id'])) {
          $player_node = $this->playerService->getPlayerByTransfermarktId($player_data['id']);
          if ($player_node) {
            $imported_players[$player_data['id']] = $player_node->id();
          }
        }
      }

      // Build the render array using the transfermarkt-team-squad-list.html.twig template
      $build = [
        '#theme' => 'transfermarkt_team_squad_list',
        '#team_name' => $node->getTitle(),
        '#team_id' => $team_id,
        '#team_nid' => $team_nid,
        '#squad_players' => $squad_data,
        '#imported_players' => $imported_players,
        '#attached' => [
          'library' => [
            'transfermarkt_integration/transfermarkt_styles',
          ],
        ],
      ];

      return $build;
    }
    catch (\Exception $e) {
      $error_message = $e->getMessage();
      
      // Check if this is the API unavailability error (rate limiting)
      if (strpos($error_message, 'API is currently unavailable') !== FALSE) {
        $this->messenger()->addError($this->t('The Transfermarkt API is currently unavailable. This can happen when too many requests are made. Please try again in a few moments.'));
      } else {
        $this->messenger()->addError($this->t('Error fetching squad data: @error', ['@error' => $error_message]));
      }
      
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }
  }

  /**
   * Imports a single player from a team squad.
   *
   * This method allows administrators to import individual players one by one
   * from the squad view, which is more efficient than importing the entire squad.
   *
   * Route: /node/{node}/squad/import/{player_id}
   *
   * @param \Drupal\node\NodeInterface $node
   *   The team node.
   * @param string $player_id
   *   The Transfermarkt player ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to either the player page or team page.
   */
  public function importPlayer(NodeInterface $node, $player_id) {
    // Check if this is a team node.
    if ($node->getType() != 'team') {
      $this->messenger()->addError($this->t('This is not a team node.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $team_nid = $node->id();

    try {
      // Import the player using the PlayerService
      $player_nid = $this->playerService->importPlayer($player_id);
      
      if ($player_nid) {
        // Update the player's current club to link them to this team
        $player_node = $this->entityTypeManager()->getStorage('node')->load($player_nid);
        if ($player_node) {
          $player_node->set('field_current_club', $team_nid);
          $player_node->save();
          
          $this->messenger()->addStatus($this->t('Player @name imported successfully and added to team.', [
            '@name' => $player_node->getTitle(),
          ]));
          
          // Redirect directly to the player page instead of back to the squad list
          return $this->redirect('entity.node.canonical', ['node' => $player_nid]);
        }
        else {
          $this->messenger()->addWarning($this->t('Player imported but could not be updated with team information.'));
        }
      }
      else {
        $this->messenger()->addError($this->t('Failed to import player with ID: @id', ['@id' => $player_id]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error importing player: @error', ['@error' => $e->getMessage()]));
    }

    // If we reach here, something went wrong. Redirect to the team page instead of the squad list
    // to avoid additional API calls that might fail
    return $this->redirect('entity.node.canonical', ['node' => $team_nid]);
  }

  /**
   * Gets the title for the team squad page.
   *
   * Used as the page title callback in the route definition.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The team node.
   *
   * @return string
   *   The page title.
   */
  public function getTitle(NodeInterface $node) {
    return $this->t('Squad Players for @team', ['@team' => $node->getTitle()]);
  }

  /**
   * Checks access for the team squad page.
   *
   * Only administrators with the 'administer transfermarkt integration' permission
   * can access the squad view and import players.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The team node.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node) {
    // Check if user has permission and if this is a team node with a Transfermarkt ID.
    $has_permission = $this->currentUser()->hasPermission('administer transfermarkt integration');
    $is_team = $node->getType() == 'team';
    $has_transfermarkt_id = $node->hasField('field_transfermarkt_id') && !$node->get('field_transfermarkt_id')->isEmpty();
    
    return AccessResult::allowedIf($has_permission && $is_team && $has_transfermarkt_id);
  }
} 