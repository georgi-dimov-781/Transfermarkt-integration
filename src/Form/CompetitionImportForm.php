<?php

namespace Drupal\transfermarkt_integration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\transfermarkt_integration\Service\CompetitionService;
use Drupal\transfermarkt_integration\Service\TransfermarktApiService;

/**
 * Form for importing competitions from Transfermarkt.
 */
class CompetitionImportForm extends FormBase {

  /**
   * The competition service.
   *
   * @var \Drupal\transfermarkt_integration\Service\CompetitionService
   */
  protected $competitionService;

  /**
   * The Transfermarkt API service.
   *
   * @var \Drupal\transfermarkt_integration\Service\TransfermarktApiService
   */
  protected $transfermarktApi;

  /**
   * Constructs a new CompetitionImportForm.
   *
   * @param \Drupal\transfermarkt_integration\Service\CompetitionService $competition_service
   *   The competition service.
   * @param \Drupal\transfermarkt_integration\Service\TransfermarktApiService $transfermarkt_api
   *   The Transfermarkt API service.
   */
  public function __construct(
    CompetitionService $competition_service,
    TransfermarktApiService $transfermarkt_api
  ) {
    $this->competitionService = $competition_service;
    $this->transfermarktApi = $transfermarkt_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('transfermarkt_integration.competition_service'),
      $container->get('transfermarkt_integration.api_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'transfermarkt_competition_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('<p>Use this form to import a competition from Transfermarkt.</p>'),
    ];

    $form['search_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['container-inline'],
      ],
    ];

    $form['search_container']['search_term'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for competition'),
      '#description' => $this->t('Enter competition name to search for.'),
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
      foreach ($search_results as $competition) {
        $options[$competition['id']] = $this->t('@name (@country)', [
          '@name' => $competition['name'],
          '@country' => isset($competition['country']) ? $competition['country'] : $this->t('Unknown country'),
        ]);
      }

      $form['search_results']['competition_id'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select competition to import'),
        '#options' => $options,
        '#required' => TRUE,
      ];

      $form['search_results']['import_standings'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Import standings'),
        '#description' => $this->t('Also import the competition standings and teams. This can be resource intensive.'),
        '#default_value' => FALSE,
      ];

      $form['search_results']['import_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import Competition'),
        '#submit' => ['::importSubmit'],
      ];
    }

    // Direct ID import
    $form['direct_import'] = [
      '#type' => 'details',
      '#title' => $this->t('Import by ID'),
      '#open' => !empty($search_results) ? FALSE : TRUE,
    ];

    $form['direct_import']['competition_id_direct'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transfermarkt Competition ID'),
      '#description' => $this->t('Enter the Transfermarkt ID of the competition to import.'),
    ];

    $form['direct_import']['import_standings_direct'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Import standings'),
      '#description' => $this->t('Also import the competition standings and teams. This can be resource intensive.'),
      '#default_value' => FALSE,
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
      $response = $this->transfermarktApi->searchCompetitions($search_term);
      
      // Extract results from the response
      $results = [];
      if (isset($response['results']) && is_array($response['results'])) {
        $results = $response['results'];
      }
      
      $form_state->set('search_results', $results);
      $form_state->setRebuild(TRUE);
      
      if (empty($results)) {
        $this->messenger()->addWarning($this->t('No competitions found matching "@term".', ['@term' => $search_term]));
      }
      else {
        $this->messenger()->addStatus($this->t('Found @count competitions matching "@term".', [
          '@count' => count($results),
          '@term' => $search_term,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error searching for competitions: @error', ['@error' => $e->getMessage()]));
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Submit handler for the import button.
   */
  public function importSubmit(array &$form, FormStateInterface $form_state) {
    $competition_id = $form_state->getValue('competition_id');
    $import_standings = $form_state->getValue('import_standings');
    $this->importCompetition($competition_id, $import_standings);
  }

  /**
   * Submit handler for the direct import button.
   */
  public function importDirectSubmit(array &$form, FormStateInterface $form_state) {
    $competition_id = $form_state->getValue('competition_id_direct');
    $import_standings = $form_state->getValue('import_standings_direct');
    
    if (empty($competition_id)) {
      $this->messenger()->addError($this->t('Please enter a competition ID.'));
      return;
    }
    
    $this->importCompetition($competition_id, $import_standings);
  }

  /**
   * Imports a competition by ID.
   *
   * @param string $competition_id
   *   The competition ID.
   * @param bool $import_standings
   *   Whether to import the competition standings.
   */
  protected function importCompetition($competition_id, $import_standings = FALSE) {
    try {
      $nid = $this->competitionService->importCompetition($competition_id, TRUE, $import_standings);
      
      if ($nid) {
        $this->messenger()->addStatus($this->t('Competition imported successfully (Node ID: @nid).', ['@nid' => $nid]));
        
        // Get the node URL
        $url = \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
        $this->messenger()->addStatus($this->t('View the competition: <a href="@url">@url</a>', ['@url' => $url]));
        
        if ($import_standings) {
          $this->messenger()->addStatus($this->t('Competition standings have been imported.'));
        }
      }
      else {
        $this->messenger()->addError($this->t('Failed to import competition.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error importing competition: @error', ['@error' => $e->getMessage()]));
    }
  }

} 