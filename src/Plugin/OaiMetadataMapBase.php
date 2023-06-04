<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for OAI Metadata Map plugins.
 */
abstract class OaiMetadataMapBase extends PluginBase implements OaiMetadataMapInterface {

  /**
   * {@inheritdoc}
   */
  public function build($record) {
    $templatePath = $this->getTemplatePath();
    $twig = \Drupal::service('twig');
    $template = floatval(\Drupal::VERSION) < 10.0 ? $twig->loadTemplate($templatePath) : $twig->load($templatePath);

    return $template->render($record);
  }

  /**
   * Method to return template file path.
   *
   * @return string
   *   Template file path.
   */
  protected function getTemplatePath() {
    $template = $this->getPluginDefinition()['template'];

    \Drupal::moduleHandler()->alter('rest_oai_pmh_metadata_template', $template);

    return \Drupal::service('extension.path.resolver')->getPath($template['type'], $template['name'])
          . '/' . $template['directory']
          . '/' . $template['file'] . '.html.twig';
  }

}
