<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;
use Drupal\views\Views;

/**
 * Mods using a View.
 *
 * @OaiMetadataMap(
 *  id = "mods",
 *  label = @Translation("MODS (View Mapping)"),
 *  metadata_format = "mods",
 *  template = {
 *    "type" = "module",
 *    "name" = "rest_oai_pmh",
 *    "directory" = "templates",
 *    "file" = "mods"
 *  }
 * )
 */
class Mods extends OaiMetadataMapBase {

  /**
   * Provides information on the metadata format.
   *
   * @return string[]
   *   The metadata format specification.
   */
  public function getMetadataFormat() {
    return [
      'metadataPrefix' => 'mods',
      'schema' => 'http://www.loc.gov/standards/mods/v3/mods-3-7.xsd',
      'metadataNamespace' => 'http://www.loc.gov/mods/v3',
    ];
  }

  /**
   * Provides information contained in the metadata wrapper.
   *
   * @return string[]
   *   The information needed in the metadata wrapper.
   */
  public function getMetadataWrapper() {
    return [
      'mods' => [
        '@xmlns:mods' => 'http://www.loc.gov/mods/v3',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        '@xsi:schemaLocation' => 'http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-7.xsd',
      ],
    ];
  }

  /**
   * Method to transform the provided entity into the desired metadata record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to transform.
   *
   * @return string
   *   rendered XML.
   */
  public function transformRecord(ContentEntityInterface $entity) {
    $config = \Drupal::config('rest_oai_pmh.settings');
    $view_info = $config->get('mods_view');
    if (empty($view_info['view_machine_name'])) {
      \Drupal::logger('mods')->warning(
            $this->t("View machine name not set.")
        );
      return '';
    }
    $view = Views::getView($view_info['view_machine_name']);
    if (!isset($view)) {
      \Drupal::logger('mods')->warning(
            $this->t("View machine name not valid.")
        );
      return '';
    }
    if (!$view->access($view_info['view_display_name'])) {
      \Drupal::logger('mods')->warning(
            $this->t("View display name not valid or not set.")
        );
      return '';
    }

    $view->setDisplay($view_info['view_display_name']);
    $argument = [$entity->id()];
    $view->setArguments($argument);
    $view->preExecute();
    $view->execute();
    $view_result = $view->result;
    $view->render();

    foreach ($view_result as $row) {
      foreach ($view->field as $field) {
        $label = $field->label();
        $value = $field->advancedRender($row);

        if (!is_string($value)) {
          $value = $value->__toString();
        }

        if (!empty($value)) {
          $render_array['elements'][$label] = $value;
        }
      }
    }

    if (empty($render_array)) {
      return '';
    }

    return parent::build($render_array);
  }

}
