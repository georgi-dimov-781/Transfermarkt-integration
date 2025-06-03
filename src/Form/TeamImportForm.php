<?php

namespace Drupal\transfermarkt_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\transfermarkt_integration\Service\TeamService;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;

/**
 * Form for importing teams from Transfermarkt.
 *
 * This form provides two methods to import teams:
 * 1. Search for teams by name and select from results
 * 2. Direct import using a known Transfermarkt team ID
 *
 * Note: The squad import checkbox functionality has been intentionally removed
 * to prevent resource-intensive bulk imports. Individual players can still be
 * imported from the team squad view page.
 */
class TeamImportForm extends FormBase {

  /**
   * The team service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TeamService
   */
  protected $teamService;

  /**
   * The Transfermarkt API service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TransfermarktApiService
   */
  protected $transfermarktApi;

  /**
   * Constructs a new TeamImportForm.
   *
   * @param \Drupal\transfermarkt_integration\Service\TeamService $team_service
   *   The team service.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   */
  public function __construct(
    TeamService $team_service,
    TransfermarktApiService $transfermarkt_api
  ) {
    $this->teamService = $team_service;
    $this->transfermarktApi = $transfermarkt_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('transfermarkt_integration.team_service'),
      $container->get('transfermarkt_integration.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'transfermarkt_team_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Add descriptive text at the top of the form
    $form['description'] = [
      '#markup' => $this->t('<p>Use this form to import a team from Transfermarkt.</p>'),
    ];

    // Search container for team name search functionality
    $form['search_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['container-inline'],
      ],
    ];

    // Team name search field
    $form['search_container']['search_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for team'),
      '#description' => $this->t('Enter team name to search for.'),
      '#required' => TRUE,
    ];

    // Search submit button
    $form['search_container']['search_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#submit' => ['::searchSubmit'],
    ];

    // Display search results section if we have results
    $search_results = $form_state->get('search_results');
    if (!empty($search_results)) {
      $form['search_results'] = [
        '#type' => 'details',
        '#title' => $this->t('Search Results'),
        '#open' => TRUE,
      ];

      // Create radio options for each team result
      $options = [];
      foreach ($search_results as $team) {
        $options[$team['id']] = $this->t('@name (@country)', [
          '@name' => $team['name'],
          '@country' => isset($team['country']) ? $team['country'] : $this->t('Unknown country'),
        ]);
      }

      // Radio buttons to select a team
      $form['search_results']['team_id'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select team to import'),
        '#options' => $options,
        '#required' => TRUE,
      ];

      // Import button for search results
      // Note: The 'import_squad' checkbox was removed to prevent resource-intensive batch imports
      $form['search_results']['import_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import Team'),
        '#submit' => ['::importSubmit'],
      ];
    }

    // Direct ID import section
    $form['direct_import'] = [
      '#type' => 'details',
      '#title' => $this->t('Import by ID'),
      '#open' => !empty($search_results) ? FALSE : TRUE,
    ];

    // Transfermarkt team ID field
    $form['direct_import']['team_id_direct'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transfermarkt Team ID'),
      '#description' => $this->t('Enter the Transfermarkt ID of the team to import.'),
    ];

    // Import by ID button
    // Note: The 'import_squad_direct' checkbox was removed to prevent resource-intensive batch imports
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
   *
   * Makes an API call to search for teams by name and 
   * stores the results in the form state for display.
   */
  public function searchSubmit(array &$form, FormStateInterface $form_state) {
    $search_term = $form_state->getValue('search_term');
    
    try {
      // Call the Transfermarkt API to search for teams
      $response = $this->transfermarktApi->searchTeams($search_term);
      
      // Extract results from the response
      $results = [];
      if (isset($response['results']) && is_array($response['results'])) {
        $results = $response['results'];
      }
      
      // Store results in form state and rebuild the form
      $form_state->set('search_results', $results);
      $form_state->setRebuild(TRUE);
      
      // Display appropriate message based on search results
      if (empty($results)) {
        $this->messenger()->addWarning($this->t('No teams found matching "@term".', ['@term' => $search_term]));
      }
      else {
        $this->messenger()->addStatus($this->t('Found @count teams matching "@term".', [
          '@count' => count($results),
          '@term' => $search_term,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error searching for teams: @error', ['@error' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Submit handler for the import button in search results.
   *
   * Imports the selected team without its squad.
   * The squad import checkbox was intentionally removed to prevent resource-intensive imports.
   */
  public function importSubmit(array &$form, FormStateInterface $form_state) {
    $team_id = $form_state->getValue('team_id');
    // Always set import_squad to FALSE (squad checkbox was removed)
    $import_squad = FALSE;
    $this->importTeam($team_id, $import_squad);
  }

  /**
   * Submit handler for the direct import button.
   *
   * Imports a team by ID without its squad.
   * The squad import checkbox was intentionally removed to prevent resource-intensive imports.
   */
  public function importDirectSubmit(array &$form, FormStateInterface $form_state) {
    $team_id = $form_state->getValue('team_id_direct');
    // Always set import_squad to FALSE (squad checkbox was removed)
    $import_squad = FALSE;
    
    if (empty($team_id)) {
      $this->messenger()->addError($this->t('Please enter a team ID.'));
      return;
    }
    
    $this->importTeam($team_id, $import_squad);
  }

  /**
   * Imports a team by ID.
   *
   * This method calls the TeamService to handle the actual import process
   * and displays appropriate messages to the user.
   *
   * @param string $team_id
   *   The Transfermarkt team ID.
   * @param bool $import_squad
   *   Whether to import the team squad (always FALSE now).
   */
  protected function importTeam($team_id, $import_squad) {
    try {
      // Call the team service to handle the import
      $nid = $this->teamService->importTeam($team_id, TRUE, $import_squad);
      
      if ($nid) {
        $this->messenger()->addStatus($this->t('Team imported successfully (Node ID: @nid).', ['@nid' => $nid]));
        
        // Get the node URL and display a link
        $url = \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
        $this->messenger()->addStatus($this->t('View the team: <a href="@url">@url</a>', ['@url' => $url]));
        
        if ($import_squad) {
          $this->messenger()->addStatus($this->t('Team squad has been imported.'));
        }
        else {
          // Add a link to the squad page so users can import individual players if needed
          $squad_url = \Drupal\Core\Url::fromRoute('transfermarkt_integration.team_squad', ['node' => $nid])->toString();
          $this->messenger()->addStatus($this->t('View Transfermarkt squad: <a href="@url">@url</a>', ['@url' => $squad_url]));
        }
      }
      else {
        $this->messenger()->addError($this->t('Failed to import team.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error importing team: @error', ['@error' => $e->getMessage()]));
    }
  }

} 