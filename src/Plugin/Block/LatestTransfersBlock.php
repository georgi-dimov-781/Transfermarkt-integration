<?php

namespace Drupal\transfermarkt_integration\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;

/**
 * Provides a block with latest transfers.
 *
 * @Block(
 *   id = "transfermarkt_latest_transfers",
 *   admin_label = @Translation("Latest Transfers"),
 *   category = @Translation("Transfermarkt")
 * )
 */
class LatestTransfersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Transfermarkt API service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TransfermarktApiService
   */
  protected $transfermarktApi;

  /**
   * Constructs a new LatestTransfersBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransfermarktApiService $transfermarkt_api
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->transfermarktApi = $transfermarkt_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transfermarkt_integration.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'limit' => 5,
      'cache_lifetime' => 43200, // 12 hours
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of transfers to display'),
      '#default_value' => $this->configuration['limit'],
      '#min' => 1,
      '#max' => 50,
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
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['limit'] = $form_state->getValue('limit');
    $this->configuration['cache_lifetime'] = $form_state->getValue('cache_lifetime');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $limit = $this->configuration['limit'];
    
    try {
      $transfers = $this->transfermarktApi->getLatestTransfers($limit);
      
      if (empty($transfers)) {
        return [
          '#markup' => $this->t('No recent transfers found.'),
        ];
      }
      
      $items = [];
      foreach ($transfers as $transfer) {
        $player_name = $transfer['player']['name'] ?? $this->t('Unknown player');
        $from_club = $transfer['from_club']['name'] ?? $this->t('Unknown club');
        $to_club = $transfer['to_club']['name'] ?? $this->t('Unknown club');
        $fee = $transfer['fee'] ?? $this->t('Undisclosed fee');
        $date = isset($transfer['date']) ? date('j M Y', strtotime($transfer['date'])) : '';
        
        $transfer_info = $this->t('@player: @from → @to (@fee)', [
          '@player' => $player_name,
          '@from' => $from_club,
          '@to' => $to_club,
          '@fee' => $fee,
        ]);
        
        if ($date) {
          $transfer_info .= ' - ' . $date;
        }
        
        $items[] = [
          '#markup' => $transfer_info,
        ];
      }
      
      // Create a render array with a proper wrapper
      $content = [
        '#type' => 'container',
        '#attributes' => ['class' => ['transfermarkt-latest-transfers']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Latest Transfers'),
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
          '#attributes' => ['class' => ['transfers-list']],
        ],
        '#attached' => [
          'library' => [
            'transfermarkt_integration/transfermarkt_styles',
          ],
        ],
      ];
      
      return $content;
    }
    catch (\Exception $e) {
      \Drupal::logger('transfermarkt_integration')->error('Error fetching latest transfers: @error', ['@error' => $e->getMessage()]);
      return [
        '#markup' => $this->t('Unable to load latest transfers data.'),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->configuration['cache_lifetime'];
  }

} 