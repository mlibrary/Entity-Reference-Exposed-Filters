<?php

namespace Drupal\entity_reference_exposed_filters\Plugin\views\filter;

use Drupal\field\Entity\FieldConfig;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\ViewExecutable;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Database\Connection;

/**
 * Filters by given list of related content title options.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("eref_node_titles")
 */
/**
 * TODO this doesnt work for tax terms or users. separate filter.
 */
class EREFNodeTitles extends ManyToOne implements PluginInspectionInterface, ContainerFactoryPluginInterface {
  /**
   * {@inheritdoc}
   */
  private $sort_by_options;
  private $sort_order_options;
  private $get_unpublished_options;
  private $get_filter_no_results_options;
  private $get_relationships;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $Connection;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, QueryFactory $entity_query, EntityTypeManagerInterface $entity_type_manager, Connection $connection, EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityQuery = $entity_query;
    $this->entityTypeManager = $entity_type_manager;
    $this->Connection = $connection;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.query'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   *
   */
  public function validate() {
    if (empty($this->get_relationships)) {
      $this->broken();
    }
  }

  /**
   *
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
    $this->get_relationships = $this->view->getHandlers('relationship');
    if ($this->get_relationships === NULL) {
      $this->get_relationships = [];
    }
    // Check for existence of relationship and remove non-standard and non-node relationships
    // TODO this seems horrible. How can I get the relationship type from the handler?
    $invalid_relationships = ['cid', 'comment_cid', 'last_comment_uid', 'uid', 'vid', 'nid'];
    foreach ($this->get_relationships as $key => $relationship) {
      // $is_node = strpos($relationship['table'], 'ode__');.
      $is_target = strpos($relationship['id'], 'target_id');
      if ($relationship['plugin_id'] != 'standard' ||
          in_array($key, $invalid_relationships) ||
          // $is_node === false ||.
          $is_target !== FALSE) {
        unset($this->get_relationships[$key]);
      }
    }
    /*
    //TODO the init gets initialized 2x in preview causing the error message in first pass!
    if (empty($this->get_relationships)) {
    $message = 'You must setup a relationship to an entity_reference field referencing a node to use this filter (i.e. "Content referenced from..")';
    drupal_set_message(t($message), 'error');
    }
     */
    // set the sort options.
    $this->sort_by_options = ['nid', 'title'];
    $this->sort_order_options = ['DESC', 'ASC'];
    $this->get_unpublished_options = ['Unpublished', 'Published', 'All'];
    $this->get_filter_no_results_options = ['Yes', "No"];
  }

  /**
   *
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    if (!isset($this->options['expose']['identifier'])) {
      $this->options['expose']['identifier'] = $form_state->get('id');
    }
    parent::buildOptionsForm($form, $form_state);
    // TODO this probably should be done in an options function but didn't seem to work...this does.
    $form['relationship']['#options'] = array_intersect_key($form['relationship']['#options'], $this->get_relationships);
  }

  /**
   * Define the sort params as extra options.
   */
  public function hasExtraOptions() {
    return TRUE;
  }

  /**
   *
   */
  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    $form['sort_by'] = [
      '#type' => 'radios',
      '#title' => t('Sort by'),
      '#default_value' => $this->options['sort_by'],
      '#options' => $this->sort_by_options,
      '#description' => t('On what attribute do you want to sort the node titles? '),
      '#required' => TRUE,
    ];
    $form['sort_order'] = [
      '#type' => 'radios',
      '#title' => t('Sort by'),
      '#default_value' => $this->options['sort_order'],
      '#options' => $this->sort_order_options,
      '#description' => t('In what order do you want to sort the node titles?'),
      '#required' => TRUE,
    ];
    $form['get_unpublished'] = [
      '#type' => 'radios',
      '#title' => t('Published Status'),
      '#default_value' => $this->options['get_unpublished'],
      '#options' => $this->get_unpublished_options,
      '#description' => t('Do you want Published, Unpublished or All?'),
      '#required' => TRUE,
    ];
    $form['get_filter_no_results'] = [
      '#type' => 'radios',
      '#title' => t('Filter Out Options With No Results'),
      '#default_value' => $this->options['get_filter_no_results'],
      '#options' => $this->get_filter_no_results_options,
      '#description' => t('Do you want to filter out options that will give no results?'),
      '#required' => TRUE,
    ];
  }

  /**
   *
   */
  public function submitExtraOptionsForm($form, FormStateInterface $form_state) {
    // Define and regenerate the options if we change the sort.
    $this->defineOptions();
    $this->generateOptions();
  }

  /**
   *
   */
  public function valueForm(&$form, FormStateInterface $form_state) {
    parent::valueForm($form, $form_state);
    // Disable the none option. we have to have a relationship.
    unset($form['relationship']['#options']['none']);
    // Disable the expose button. this should be an exposed filter.
    $form['expose_button'] = ['#disabled' => TRUE];
  }

  /**
   *
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    // Always exposed.
    $options['exposed'] = ['default' => 1];

    // Get the relationships. set the first as the default.
    if (isset($this->get_relationships)) {
      $relationship_field_names = array_keys($this->get_relationships);
      $options['relationship'] = ['default' => $relationship_field_names[0], $this->get_relationships];

      // Set the sort defaults. always numeric. compare with sort options private arrays to get value for sort.
      $options['sort_order'] = ['default' => 0];
      $options['sort_by'] = ['default' => 1];
      $options['get_unpublished'] = ['default' => 1];
      $options['get_filter_no_results'] = ['default' => 1];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    // Generate the values from the helper function.
    // TODO? - regenerate the list everytime the relationship field is changed.
    $this->valueOptions = $this->generateOptions();
    return $this->valueOptions;
  }

  /**
   * Helper function that generates the options.
   *
   * @return array
   */
  public function generateOptions() {
    $res = [];
    $relationship_fields = array_keys($this->get_relationships);

    if (!empty($this->get_relationships) && isset($relationship_fields[0])) {
      // Get the base view. we need it for bundle info and field defs.
      $base_table = array_keys($this->view->getBaseTables());
      $entity_type_db = reset($base_table);
      switch ($entity_type_db) {
        case 'users_field_data':
          $entity_type_id = 'user';
          break;

        case 'node_field_data':
          $entity_type_id = 'node';
          break;

        case 'taxonomy_term_field_data':
          $entity_type_id = 'taxonomy_term';
          break;

        default:
          return;
        break;
      }

      // Get bundles from a field name.
      $all_bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

      $relationship = $this->view->getHandler($this->view->current_display, 'filter', $this->options['id']);
      if (isset($relationship['relationship']) && $relationship['relationship'] != 'none') {
        $relationship_field_name = $relationship['relationship'];
      }
      else {
        // We need this as a default.
        $relationship_field_name = $relationship_fields[0];
      }

      // Run through the bundles. id like to find a way to look up bundles associated with a field. anyone know?
      foreach (array_keys($all_bundles) as $bundle) {
        foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $field_definition) {
          if ($field_definition->getType() == 'entity_reference' && $field_definition->getName() == $relationship_field_name) {
            if ($field_definition->getName() == 'uid') {
              // TODO this will be a whole different query for users. separate filter?
              continue;
            }
            $field_obj = FieldConfig::loadByName($entity_type_id, $bundle, $field_definition->getName());
            $target_entity_type_id = explode(':', $field_obj->getSetting('handler'));
            // Convert an entity reference view to node or user.
            if (in_array('views', $target_entity_type_id)) {
              $target_entity_type_id = ['default', $entity_type_id];
            }

            // Will tell us node, user etc.
            if ($target_entity_type_id[0] == 'default') {
              $target_entity_type_id = $target_entity_type_id[1];
            }
            // Filter out entity reference views. we wont know their bundles easily.
            if (($handler_settings = $field_obj->getSetting('handler_settings')) && !empty($handler_settings['view'])) {
              drupal_set_message('This is targeting a field filtered by a view. Cannot get bundle.', 'error');
              drupal_set_message('Please use a field filtered by content type only.', 'error');
              return [];
            }
            // Get all the targets (content types etc) that this might hit.
            $target_bundles = array_keys($field_obj->getSetting('handler_settings')['target_bundles']);
            $bundles_needed[] = $bundle;

            // Get the options together.
            $gen_options = [];
            $gen_options = [
              'field' => $field_definition->getName(),
              'entity_type_id' => $entity_type_id,
              'bundle' => $bundles_needed,
              'target_entity_type_id' => $target_entity_type_id,
              'target_bundles' => $target_bundles,
            ];
          }

        }
      }

      // Run the query.
      $get_entity = $this->entityTypeManager->getStorage($gen_options['target_entity_type_id']);
      $relatedContentQuery = $this->entityQuery->get($gen_options['target_entity_type_id'])
        ->condition('type', $gen_options['target_bundles'], 'IN');
      // Leave this for any debugging ->sort('title', 'ASC');.
      if ($this->options['get_unpublished'] != 2) {
        $relatedContentQuery->condition('status', $this->options['get_unpublished']);
      }
      $relatedContentQuery->sort($this->sort_by_options[$this->options['sort_by']], $this->sort_order_options[$this->options['sort_order']]);
      // Returns an array of node ID's.
      $relatedContentIds = $relatedContentQuery->execute();
      if (empty($relatedContentIds)) {
        return [];
      }
      // Remove empty options if desired.
      if ($this->options['get_filter_no_results'] == 0) {
        $db = $this->Connection;
        $query = $db->select($entity_type_id . '__' . $relationship_field_name, 'x')
          ->fields('x', [$relationship_field_name . '_target_id'])
          ->condition('x.' . $relationship_field_name . '_target_id', $relatedContentIds, 'IN');
        $ids_w_content = array_unique($query->execute()->fetchAll(\PDO::FETCH_COLUMN));
        // Keep the sort order of the original query.
        $relatedContentIds = array_intersect($relatedContentIds, $ids_w_content);
      }

      // Get the titles.
      foreach ($relatedContentIds as $contentId) {
        // Building an array with nid as key and title as value.
        $res[$contentId] = \Drupal::service('entity.repository')->getTranslationFromContext($get_entity->load($contentId))->getTitle();
      }
    }

    return $res;
  }

}
