<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\Views;

/**
 * Abstract class to implement an OAI cache queue worker.
 */
abstract class RestOaiPmhViewsCacheBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * A connection to Drupal's database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * The OAI set ID being processed.
   *
   * @var string
   */
  protected $setId;

  /**
   * The entity type being exposed to OAI-PMH.
   *
   * @var string
   */
  protected $memberEntityType;

  /**
   * The entity storage interface for $memberEntityType.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $memberEntityStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->db = \Drupal::database();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
          $configuration,
          $plugin_id,
          $plugin_definition
      );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $view_id = $data['view_id'];
    $display_id = $data['display_id'];
    $offset = $data['offset'] ?? NULL;
    $limit = $data['limit'];
    $arguments = $data['arguments'];
    $this->setId = $data['set_id'];
    $set_entity_type = $data['set_entity_type'];
    $set_label = $data['set_label'];
    $view_display = $data['view_display'];

    // Load the View and apply the display ID.
    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    $view->getDisplay()->setOption('entity_reference_options', ['limit' => $view->getItemsPerPage()]);
    if (!is_null($offset)) {
      $view->setOffset($offset);
    }

    // Make sure we fetch the total results on the first execution
    // so we can page through all the results.
    $view->get_total_rows = TRUE;
    // Get the first set of results from the View.
    $members = $view->executeDisplay($display_id, $arguments);
    // After we executed the View, we'll know how many items were returned
    // use this to page through all results.
    $total = $view->total_rows;
    // If some results were returned, save them to our rest_oai_pmh_* tables.
    if ($total > 0) {
      // Init the variables used for the UPSERT db call to add/update this SET.
      $merge_keys = [
        'entity_type',
        'set_id',
      ];
      $merge_values = [
        $set_entity_type,
        $this->setId,
      ];
      $this->db->merge('rest_oai_pmh_set')
        ->keys($merge_keys, $merge_values)
        ->fields(
                [
                  'label' => $set_label,
                  'pager_limit' => $limit,
                  'view_display' => $view_display,
                ]
            )->execute();

      // See what type of entity was returned by the View and
      // set variable accordingly so we can load the entity.
      $this->memberEntityType = $view->getBaseEntityType()->id();
      $this->memberEntityStorage = \Drupal::entityTypeManager()->getStorage($this->memberEntityType);

      // Add the results returned to the OAI cache tables.
      $this->indexViewRecords($members);

      // @todo track records that existed BEFORE we indexed the sets, and rm
      // any records that once belonged to the set but might no longer belong.
    }
    // If no results were returned, make sure this set is removed from
    // our tables so it won't be exposed to OAI-PMH.
    else {
      rest_oai_pmh_remove_set($this->setId);
    }
  }

  /**
   * Helper function. Add items returned by a view to the OAI cache tables.
   */
  protected function indexViewRecords($members = FALSE) {
    foreach ($members as $id => $row) {
      // Init the variables used for the UPSERT database call
      // to add/update this RECORD.
      $merge_keys = [
        'entity_type',
        'entity_id',
      ];
      $merge_values = [
        $this->memberEntityType,
        $id,
      ];
      // Load the entity, partly to ensure it exists
      // also to get the changed/created properties.
      $entity = $this->memberEntityStorage->load($id);
      if ($entity) {
        // Get the changed/created values, if they exist.
        $changed = $entity->hasField('changed') ? $entity->changed->value : \Drupal::time()->requestTime();
        $created = $entity->hasField('created') ? $entity->created->value : $changed;
        // Upsert the record into our cache table.
        $this->db->merge('rest_oai_pmh_record')
          ->keys($merge_keys, $merge_values)
          ->fields(
                  [
                    'created' => $created,
                    'changed' => $changed,
                  ]
              )->execute();

        // Add this record to the respective set.
        $merge_keys[] = 'set_id';
        $merge_values[] = $this->setId;
        $this->db->merge('rest_oai_pmh_member')
          ->keys($merge_keys, $merge_values)
          ->execute();
      }
    }
  }

}
