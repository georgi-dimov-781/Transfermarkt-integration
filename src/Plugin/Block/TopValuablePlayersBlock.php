<?php

namespace Drupal\transfermarkt_integration\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Provides a block with top valuable players.
 *
 * This block displays a list or card view of football players with the highest
 * market values. It first attempts to find players in the local database and
 * falls back to the Transfermarkt API if needed.
 *
 * @Block(
 *   id = "transfermarkt_top_valuable_players",
 *   admin_label = @Translation("Top Valuable Players"),
 *   category = @Translation("Transfermarkt")
 * )
 */
class TopValuablePlayersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * Used to query player nodes and load related entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Transfermarkt API service.
   *
   * Used to fetch player data from the Transfermarkt API when local data
   * is insufficient.
   *
   * @var \Drupal\transfermarkt_integration\Service\TransfermarktApiService
   */
  protected $transfermarktApi;

  /**
   * The file URL generator.
   *
   * Used to generate URLs for player photos.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a new TopValuablePlayersBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    TransfermarktApiService $transfermarkt_api,
    FileUrlGeneratorInterface $file_url_generator
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->transfermarktApi = $transfermarkt_api;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   *
   * Creates an instance of the plugin with required services.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('transfermarkt_integration.api_service'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Define default configuration for the block.
   */
  public function defaultConfiguration() {
    return [
      'limit' => 5,
      'display_mode' => 'list',
      'cache_lifetime' => 86400,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Build the configuration form for the block.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of players to display'),
      '#default_value' => $this->configuration['limit'],
      '#min' => 1,
      '#max' => 50,
    ];

    $form['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Display mode'),
      '#options' => [
        'list' => $this->t('Simple list'),
        'cards' => $this->t('Player cards'),
      ],
      '#default_value' => $this->configuration['display_mode'],
    ];

    $form['cache_lifetime'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache lifetime'),
      '#options' => [
        3600 => $this->t('1 hour'),
        21600 => $this->t('6 hours'),
        43200 => $this->t('12 hours'),
        86400 => $this->t('1 day'),
        604800 => $this->t('1 week'),
      ],
      '#default_value' => $this->configuration['cache_lifetime'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Save the block configuration.
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['limit'] = $form_state->getValue('limit');
    $this->configuration['display_mode'] = $form_state->getValue('display_mode');
    $this->configuration['cache_lifetime'] = $form_state->getValue('cache_lifetime');
  }

  /**
   * {@inheritdoc}
   *
   * Build the block content.
   *
   * This method:
   * 1. Queries the database for player nodes with market values
   * 2. Falls back to the API if not enough players are found
   * 3. Formats the data for display
   * 4. Renders the content in the selected display mode (list or cards)
   */
  public function build() {
    $limit = $this->configuration['limit'];
    $display_mode = $this->configuration['display_mode'];
    
    try {
      // Try to find players in the database first
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'player')
        ->condition('field_market_value', '', '<>')
        ->sort('field_market_value', 'DESC')
        ->range(0, $limit)
        ->accessCheck(TRUE);
      
      $nids = $query->execute();
      $players = [];
      
      if (!empty($nids)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
        
        foreach ($nodes as $node) {
          $player = [
            'name' => $node->getTitle(),
            'market_value' => $node->get('field_market_value')->value,
            'node_id' => $node->id(),
          ];
          
          // Format market value
          if (function_exists('transfermarkt_integration_format_market_value')) {
            $player['market_value'] = transfermarkt_integration_format_market_value($player['market_value']);
          }
          
          // Add photo if available
          if ($node->hasField('field_photo') && !$node->get('field_photo')->isEmpty()) {
            $file_id = $node->get('field_photo')->target_id;
            $file = $this->entityTypeManager->getStorage('file')->load($file_id);
            if ($file) {
              $player['photo'] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
            }
          }
          
          // Add nationality if available
          if ($node->hasField('field_nationality') && !$node->get('field_nationality')->isEmpty()) {
            $term_id = $node->get('field_nationality')->target_id;
            $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_id);
            if ($term) {
              $player['nationality'] = $term->getName();
            }
          }
          
          // Add current club if available
          if ($node->hasField('field_current_club') && !$node->get('field_current_club')->isEmpty()) {
            $club_id = $node->get('field_current_club')->target_id;
            $club_node = $this->entityTypeManager->getStorage('node')->load($club_id);
            if ($club_node) {
              $player['current_club'] = $club_node->getTitle();
              $player['club_node_id'] = $club_node->id();
            }
          }
          
          $players[] = $player;
        }
      }
      
      // If we don't have enough players, fetch from API
      if (count($players) < $limit) {
        try {
          $api_players = $this->transfermarktApi->getTopValuablePlayers($limit - count($players));
          
          foreach ($api_players as $api_player) {
            $player = [
              'name' => $api_player['name'],
              'market_value' => $api_player['market_value'],
              'url' => '#',
            ];
            
            // Try to convert market value string to a formatted value
            if (is_string($player['market_value']) && function_exists('transfermarkt_integration_format_market_value')) {
              // Try to extract numeric value if it's a string like '€180m'
              $numeric_value = preg_replace('/[^0-9]/', '', $player['market_value']);
              $multiplier = 1;
              
              if (stripos($player['market_value'], 'm') !== FALSE) {
                $multiplier = 1000000;
              } elseif (stripos($player['market_value'], 'k') !== FALSE) {
                $multiplier = 1000;
              }
              
              if (!empty($numeric_value)) {
                $value = (int) $numeric_value * $multiplier;
                $player['market_value'] = transfermarkt_integration_format_market_value($value);
              }
            }
            
            if (isset($api_player['image_url'])) {
              $player['photo'] = $api_player['image_url'];
            }
            
            if (isset($api_player['nationality'])) {
              $player['nationality'] = $api_player['nationality'];
            }
            
            if (isset($api_player['current_club']['name'])) {
              $player['current_club'] = $api_player['current_club']['name'];
            }
            
            $players[] = $player;
          }
        }
        catch (\Exception $e) {
          // API fetch failed, continue with what we have
          \Drupal::logger('transfermarkt_integration')->error('Failed to fetch top players from API: @error', ['@error' => $e->getMessage()]);
        }
      }
      
      // If no players found, show a message
      if (empty($players)) {
        return [
          '#markup' => $this->t('No players found.'),
        ];
      }
      
      // Build the render array based on display mode
      if ($display_mode === 'cards') {
        $content = [
          '#prefix' => '<div class="transfermarkt-player-cards">',
          '#suffix' => '</div>',
        ];
        
        foreach ($players as $index => $player) {
          // Generate URL based on node_id if available
          $url = NULL;
          if (isset($player['node_id'])) {
            $url = Url::fromRoute('entity.node.canonical', ['node' => $player['node_id']])->toString();
          } elseif (isset($player['url']) && $player['url'] !== '#') {
            $url = $player['url'];
          }
          
          $content['player_' . $index] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['transfermarkt-player-card']],
            'card' => [
              '#theme' => 'transfermarkt_player_card',
              '#name' => $player['name'],
              '#photo' => isset($player['photo']) ? $player['photo'] : NULL,
              '#nationality' => isset($player['nationality']) ? $player['nationality'] : NULL,
              '#market_value' => $player['market_value'],
              '#url' => $url,
            ],
          ];
        }
      }
      else {
        // Simple list display
        $items = [];
        foreach ($players as $player) {
          // Fix URL handling - ensure it's a valid URL
          if (isset($player['node_id'])) {
            $url = Url::fromRoute('entity.node.canonical', ['node' => $player['node_id']]);
          } elseif (isset($player['url']) && $player['url'] !== '#') {
            $url = Url::fromUserInput($player['url']);
          } else {
            $url = Url::fromRoute('<none>');
          }
          
          $player_link = Link::fromTextAndUrl($player['name'], $url);
          $market_value = ' - ' . $player['market_value'];
          
          $items[] = [
            '#markup' => $player_link->toString() . $market_value,
          ];
        }
        
        $content = [
          '#theme' => 'item_list',
          '#title' => $this->t('Top Valuable Players'),
          '#items' => $items,
          '#attributes' => ['class' => ['transfermarkt-player-list']],
        ];
      }
      
      // Add library and return the content
      $content['#attached']['library'][] = 'transfermarkt_integration/transfermarkt_styles';
      return $content;
    }
    catch (\Exception $e) {
      \Drupal::logger('transfermarkt_integration')->error('Error building top players block: @error', ['@error' => $e->getMessage()]);
      return [
        '#markup' => $this->t('Unable to load top players data.'),
      ];
    }
  }

  /**
   * {@inheritdoc}
   *
   * Set the cache max age based on configuration.
   */
  public function getCacheMaxAge() {
    return $this->configuration['cache_lifetime'];
  }

} 