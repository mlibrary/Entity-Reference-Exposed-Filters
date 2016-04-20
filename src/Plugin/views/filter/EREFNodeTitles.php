<?php
/**
 * @file
 * Definition of Drupal\entity_reference_exposed_filters\Plugin\views\filter\EREFNodeTitles.
 */
namespace Drupal\entity_reference_exposed_filters\Plugin\views\filter;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\ViewExecutable;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;

/**
 * Filters by given list of related content title options.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("eref_node_titles")
 */
//TODO this doesnt work for tax terms or users. separate filter
class EREFNodeTitles extends ManyToOne {
  /**
   * {@inheritdoc}
   */

  private $sort_by_options;
  private $sort_order_options;
  private $get_relationships;

  public function validate() {
    if (empty($this->get_relationships)) {
      $this->broken();
    }
  }

  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
    $this->get_relationships = $this->view->getHandlers('relationship');
    if ($this->get_relationships === NULL) {
      $this->get_relationship = array();
    }
    //check for existence of relationship and remove non-standard and non-node relationships
    //TODO this seems horrible. How can I get the relationship type from the handler?
    $invalid_relationships = array('cid', 'comment_cid', 'last_comment_uid', 'uid', 'vid', 'nid');
    foreach ($this->get_relationships as $key => $relationship) {
      //$is_node = strpos($relationship['table'], 'ode__');
      $is_target = strpos($relationship['id'], 'target_id');
      if ($relationship['plugin_id'] != 'standard' || 
          in_array($key, $invalid_relationships) || 
          //$is_node === false || 
          $is_target !== false) {
        unset($this->get_relationships[$key]);
      }
    }
    if (empty($this->get_relationships)) {
      $message = 'You must setup a relationship to an entity_reference field referencing a node to use this filter (i.e. "Content referenced from..")';
      drupal_set_message(t($message), 'error');
    }
    //set the sort options
    $this->sort_by_options = array('nid','title');
    $this->sort_order_options = array('DESC','ASC');
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $this->options['expose']['identifier'] = $form_state->get('id');
    parent::buildOptionsForm($form, $form_state);
    //TODO this probably should be done in an options function but didn't seem to work...this does
    $form['relationship']['#options'] = array_intersect_key($form['relationship']['#options'], $this->get_relationships);
  }

  //define the sort params as extra options
  public function hasExtraOptions() { return TRUE; }

  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    $form['sort_by'] = array(
      '#type' => 'radios',
      '#title' => t('Sort by'),
      '#default_value'=> $this->options['sort_by'],
      '#options' => $this->sort_by_options,
      '#description' => t('On what attribute do you want to sort the node titles? '),
      '#required' => TRUE,
    );
    $form['sort_order'] = array(
      '#type' => 'radios',
      '#title' => t('Sort by'),
      '#default_value'=> $this->options['sort_order'],
      '#options' => $this->sort_order_options,
      '#description' => t('In what order do you want to sort the node titles?'),
      '#required' => TRUE,
    );
  }

  public function submitExtraOptionsForm($form, FormStateInterface $form_state) {
    //define and regenerate the options if we change the sort
    $this->defineOptions();
    $this->generateOptions();
  }

  public function valueForm(&$form, FormStateInterface $form_state) {
    parent::valueForm($form, $form_state);
    //disable the none option. we have to have a relationship
    unset($form['relationship']['#options']['none']);
    //disable the expose button. this should be an exposed filter
    $form['expose_button'] = array('#disabled' => TRUE);
  }

  protected function defineOptions() {
    $options = parent::defineOptions();
    //always exposed
    $options['exposed'] = array('default' => 1);

    //get the relationships. set the first as the default
    $relationship_field_names = array_keys($this->get_relationships);
    $options['relationship'] = array('default' => $relationship_field_names[0], $this->get_relationships);

    //set the sort defaults. always numeric. compare with sort options private arrays to get value for sort
    $options['sort_order'] = array('default' => 0);
    $options['sort_by'] = array('default' => 1);

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    //generate the values from the helper function.
    //TODO? - regenerate the list everytime the relationship field is changed
    $this->valueOptions = $this->generateOptions();
    return $this->valueOptions;
  }
  
  /**
   * Helper function that generates the options.
   * @return array
   */
  public function generateOptions() {
    $res = array();
    $relationship_fields = array_keys($this->get_relationships);

    if (!empty($this->get_relationships) && isset($relationship_fields[0])) {
      //get the base view. we need it for bundle info and field defs
      $base_table = array_keys($this->view->getBaseTables());
      $entity_type_db = reset($base_table);
      switch ($entity_type_db) {
        case 'users_field_data':
          $entity_type_id = 'user';
          break;
        case 'node_field_data':
          $entity_type_id = 'node';
          break;
        default:
          return;
          break;
      }
      //get bundles from a field name.
      $all_bundles = \Drupal::entityManager()->getBundleInfo($entity_type_id);

      $relationship = $this->view->getHandler($this->view->current_display, 'filter', $this->options['id']);
      if (isset($relationship['relationship']) && $relationship['relationship'] != 'none') {
        $relationship_field_name = $relationship['relationship'];
      }
      else {
        //we need this as a default
        $relationship_field_name = $relationship_fields[0];
      }
      //run through the bundles. id like to find a way to look up bundles associated with a field. anyone know?
      foreach (array_keys($all_bundles) as $bundle) {
        foreach (\Drupal::entityManager()->getFieldDefinitions($entity_type_id, $bundle) as $field_definition) {
          if ($field_definition->getType() == 'entity_reference' && $field_definition->getName() == $relationship_field_name) {
            if ($field_definition->getName() == 'uid') {
              //TODO this will be a whole different query for users. separate filter
              continue;
            }
            $field_obj = \Drupal\field\Entity\FieldConfig::loadByName($entity_type_id, $bundle, $field_definition->getName());
            $target_entity_type_id = explode(':',$field_obj->getSetting('handler'));

            if (in_array('views', $target_entity_type_id)) {
              //TODO This wont support views entity references yet
              continue;
            }
            
            //will tell us node, user etc.
            if ($target_entity_type_id[0] == 'default') {
              $target_entity_type_id = $target_entity_type_id[1];
            }
            //get all the targets (content types etc) that this might hit
            $target_bundles = array_keys($field_obj->getSetting('handler_settings')['target_bundles']);
            $bundles_needed[] = $bundle;
            
            //get the options together
            $gen_options = array();
            $gen_options = array(
              'field' => $field_definition->getName(),
              'entity_type_id' => $entity_type_id,
              'bundle' => $bundles_needed,
              'target_entity_type_id' => $target_entity_type_id,
              'target_bundles' => $target_bundles
            );
          }
        }
      }
      
      //run the query
      $get_entity = \Drupal::entityManager()->getStorage($gen_options['target_entity_type_id']);
      $relatedContentQuery = \Drupal::entityQuery($gen_options['target_entity_type_id'])
          ->condition('type', $gen_options['target_bundles'], 'IN')
          ->condition('status', 1)
          ->sort($this->sort_by_options[$this->options['sort_by']], $this->sort_order_options[$this->options['sort_order']]);
          //leave this for any debugging ->sort('title', 'ASC');
      $relatedContentIds = $relatedContentQuery->execute(); //returns an array of node ID's
      
      //get the titles
      foreach($relatedContentIds as $contentId){
          // building an array with nid as key and title as value
          $res[$contentId] = $get_entity->load($contentId)->getTitle();
      }
    }
    return $res;
  }
}
