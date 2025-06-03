<?php

namespace Drupal\transfermarkt_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\transfermarkt_integration\Service\PlayerService;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;

/**
 * Form for importing players from Transfermarkt.
 */
class PlayerImportForm extends FormBase {

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
   * Constructs a new PlayerImportForm.
   *
   * @param \Drupal\transfermarkt_integration\Service\PlayerService $player_service
   *   The player service.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   */
  public function __construct(
    PlayerService $player_service,
    TransfermarktApiService $transfermarkt_api
  ) {
    $this->playerService = $player_service;
    $this->transfermarktApi = $transfermarkt_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('transfermarkt_integration.player_service'),
      $container->get('transfermarkt_integration.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'transfermarkt_player_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('<p>Use this form to import a player from Transfermarkt.</p>'),
    ];

    $form['search_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['container-inline'],
      ],
    ];

    $form['search_container']['search_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for player'),
      '#description' => $this->t('Enter player name to search for.'),
      '#required' => TRUE,
    ];

    $form['search_container']['search_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#submit' => ['::searchSubmit'],
    ];

    // If we have search results, display them
    $search_results = $form_state->get('search_results');
    if (!empty($search_results)) {
      $form['search_results'] = [
        '#type' => 'details',
        '#title' => $this->t('Search Results'),
        '#open' => TRUE,
      ];

      $options = [];
      foreach ($search_results as $player) {
        // Format nationalities as a comma-separated list
        $nationalities = isset($player['nationalities']) ? implode(', ', $player['nationalities']) : $this->t('Unknown');
        
        $options[$player['id']] = $this->t('@name (@age, @nationality, @club)', [
          '@name' => $player['name'],
          '@age' => isset($player['age']) ? $player['age'] : $this->t('Unknown age'),
          '@nationality' => $nationalities,
          '@club' => isset($player['club']['name']) ? $player['club']['name'] : $this->t('Unknown club'),
        ]);
      }

      $form['search_results']['player_id'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select player to import'),
        '#options' => $options,
        '#required' => TRUE,
      ];

      $form['search_results']['import_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import Player'),
        '#submit' => ['::importSubmit'],
      ];
    }

    // Direct ID import
    $form['direct_import'] = [
      '#type' => 'details',
      '#title' => $this->t('Import by ID'),
      '#open' => !empty($search_results) ? FALSE : TRUE,
    ];

    $form['direct_import']['player_id_direct'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transfermarkt Player ID'),
      '#description' => $this->t('Enter the Transfermarkt ID of the player to import.'),
    ];

    $form['direct_import']['import_direct_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import by ID'),
      '#submit' => ['::importDirectSubmit'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This is handled by the specific submit handlers.
  }

  /**
   * Submit handler for the search button.
   */
  public function searchSubmit(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    
    try {
      $response = $this->transfermarktApi->searchPlayers($search_term);
      
      // Extract results from the response
      $results = [];
      if (isset($response['results']) && is_array($response['results'])) {
        $results = $response['results'];
      }
      
      $form_state->set('search_results', $results);
      $form_state->setRebuild(TRUE);
      
      if (empty($results)) {
        $this->messenger()->addWarning($this->t('No players found matching "@term".', ['@term' => $search_term]));
      }
      else {
        $this->messenger()->addStatus($this->t('Found @count players matching "@term".', [
          '@count' => count($results),
          '@term' => $search_term,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error searching for players: @error', ['@error' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Submit handler for the import button.
   */
  public function importSubmit(array &$form, FormStateInterface $form_state) {
    $player_id = $form_state->getValue('player_id');
    $this->importPlayer($player_id);
  }

  /**
   * Submit handler for the direct import button.
   */
  public function importDirectSubmit(array &$form, FormStateInterface $form_state) {
    $player_id = $form_state->getValue('player_id_direct');
    if (empty($player_id)) {
      $this->messenger()->addError($this->t('Please enter a player ID.'));
      return;
    }
    
    $this->importPlayer($player_id);
  }

  /**
   * Imports a player by ID.
   *
   * @param string $player_id
   *   The player ID.
   */
  protected function importPlayer($player_id) {
    try {
      $nid = $this->playerService->importPlayer($player_id);
      
      if ($nid) {
        $this->messenger()->addStatus($this->t('Player imported successfully (Node ID: @nid).', ['@nid' => $nid]));
        
        // Get the node URL
        $url = \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
        $this->messenger()->addStatus($this->t('View the player: <a href="@url">@url</a>', ['@url' => $url]));
      }
      else {
        $this->messenger()->addError($this->t('Failed to import player.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error importing player: @error', ['@error' => $e->getMessage()]));
    }
  }

} 