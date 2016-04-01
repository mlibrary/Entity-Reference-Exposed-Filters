<?php
/**
 * @file
 * Definition of Drupal\eref\Plugin\views\filter\EREFNodeTitles.
 */
namespace Drupal\eref\Plugin\views\filter;
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
class EREFNodeTitles extends ManyToOne {
  /**
   * {@inheritdoc}
   */

  private $sort_by_options;
  private $sort_order_options;

//  TODO, should probably do this
//  public function validateExposed(&$form, FormStateInterface $form_state) {
//  }
  
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);

    //set the sort options
    $this->sort_by_options = array('nid','title');
    $this->sort_order_options = array('DESC','ASC');
  }

  public function has_relationship($id) {
    //check for existence of relationship
    if (empty($this->view->getHandlers('relationship'))) {
      $message = 'You must setup a relationship to an entity_referernce field referencing a node to use this filter';
      //we dont see this in ajax
      drupal_set_message(t($message), 'error');
      $this->view->removeHandler($this->view->current_display, 'filter', $id);
      $this->submitOptionsForm();
      //TODO a start
      //$response = new AjaxResponse();
      //$response->addCommand(new HtmlCommand('form', $message));
      //TODO better than nothing (we dont want fatal errors), but needs to be handled correctly
      exit;
    }
    return TRUE;
  }

public function submitOptionsForm(&$form, FormStateInterface $form_state) {
  unset($form);
  unset($form_state);
}

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $this->has_relationship($form_state->get('id'));
    parent::buildOptionsForm($form, $form_state);
  }

  //define the sort params as extra options
  public function hasExtraOptions() { return TRUE; }
  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    $this->has_relationship($form_state->get('id'));
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
    //TODO need to check for existence of relationship earlier
    unset($form['relationship']['#options']['none']);
    //disable the expose button. this should be an exposed filter
    $form['expose_button'] = array('#disabled' => TRUE);
  }

  protected function defineOptions() {
    $options = parent::defineOptions();
    //always exposed
    $options['exposed'] = array('default' => 1);

    //get the relationships. set the first as the default
    //TODO remove non-node relationships from the list
    $relationship_fields = $this->view->getHandlers('relationship');
    $this->relationship_field_names = array_keys($relationship_fields);
    $options['relationship'] = array('default' => $relationship_field_names[0]);
    
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
    //TODO what do we do with reverse field handlers?
    $relationship_fields = array_keys($this->view->getHandlers('relationship'));
    $relationship = $this->view->getHandler($this->view->current_display, 'filter', $this->options['id']);
    if (isset($relationship['relationship'])) {
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
    //TODO this doesnt work for tax terms. separate filter
        ->condition('type', $gen_options['target_bundles'], 'IN')
        ->condition('status', 1)
        ->sort($this->sort_by_options[$this->options['sort_by']], $this->sort_order_options[$this->options['sort_order']]);
        //leave this for any debugging ->sort('title', 'ASC');
    $relatedContentIds = $relatedContentQuery->execute(); //returns an array of node ID's
    
    //get the titles
    $res = array();
    foreach($relatedContentIds as $contentId){
        // building an array with nid as key and title as value
        $res[$contentId] = $get_entity->load($contentId)->getTitle();
    }
    
    return $res;
  }
}