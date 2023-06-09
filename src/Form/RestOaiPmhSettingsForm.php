<?php

namespace Drupal\rest_oai_pmh\Form;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rest_oai_pmh\Plugin\rest\resource\OaiPmh;
use Drupal\views\Views;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\ProxyClass\Routing\RouteBuilder;

/**
 * Module settings admin form.
 */
class RestOaiPmhSettingsForm extends ConfigFormBase {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The cache discovery service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheDiscovery;

  /**
   * The router builder service.
   *
   * @var \Drupal\Core\ProxyClass\Routing\RouteBuilder
   */
  protected $routerBuilder;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_discovery
   *   The cache discovery service.
   * @param \Drupal\Core\ProxyClass\Routing\RouteBuilder $router_builder
   *   The router builder service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, PathValidatorInterface $path_validator, CacheBackendInterface $cache_discovery, RouteBuilder $router_builder) {
    parent::__construct($config_factory);

    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->pathValidator = $path_validator;
    $this->cacheDiscovery = $cache_discovery;
    $this->routerBuilder = $router_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('entity_type.manager'),
          $container->get('module_handler'),
          $container->get('path.validator'),
          $container->get('cache.discovery'),
          $container->get('router.builder')
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'rest_oai_pmh.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rest_oai_pmh_settings_form';
  }

  /**
   * Helper function. Get the metadata mapping configuration.
   */
  private function getMappingConfig(string $metadata_prefix) {
    $mapping_config = $this->config('rest_oai_pmh.settings')
      ->get('metadata_map_plugins');
    if (!is_array($mapping_config)) {
      return FALSE;
    }
    foreach ($mapping_config as $map) {
      if ($map['label'] == $metadata_prefix) {
        return $map['value'];
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rest_oai_pmh.settings');

    $form['data'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('What to expose to OAI-PMH'),
      '#attributes' => ['style' => 'max-width: 750px'],
      '#description' => $this->t(
          '<p>Select which Views with an Entity Reference display will be exposed to OAI-PMH.</p>
        <p>Each View will be represented as a set in the OAI-PMH endpoint, except for those Views that contain a contextual filter to an entity reference. If the View has one of these contextual filters, the possible values in the referenced field will be used as the sets.</p>'
      ),
    ];

    $displays = Views::getApplicableViews('entity_reference_display');
    $view_storage = $this->entityTypeManager->getStorage('view');
    $view_displays = [];
    foreach ($displays as $data) {
      [$view_id, $display_id] = $data;
      $view = $view_storage->load($view_id);
      $display = $view->get('display');
      $set_name = $view_id . ':' . $display_id;
      $view_displays[$set_name] = $display[$display_id]['display_title'] . ' (' . $set_name . ')';
    }

    $form['data']['view_displays'] = [
      '#type' => 'checkboxes',
      '#options' => $view_displays,
      '#default_value' => $config->get('view_displays') ?: [],
    ];

    $support_sets = $config->get('support_sets');
    $form['support_sets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Support Sets'),
      '#description' => $this->t('If you want all the Views selected to be treated a single set, and simply expose all the records, you can uncheck this box to have your OAI-PMH endpoint not support sets.'),
      '#default_value' => is_null($support_sets) ? TRUE : $support_sets,
    ];

    $form['mapping'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => TRUE,
      '#title' => $this->t('Metadata Mappings'),
      '#attributes' => ['style' => 'max-width: 750px'],
      '#description' => $this->t('<p>Select which metadata plugins should be enabled.</p>'),
    ];
    $mapping_prefix_plugins = [];
    foreach (\Drupal::service('plugin.manager.oai_metadata_map')->getDefinitions() as $plugin_id => $plugin_definition) {
      $mapping_prefix_plugins[$plugin_definition['metadata_format']][$plugin_id] = $plugin_definition['label']->render();
    }
    foreach ($mapping_prefix_plugins as $metadata_prefix => $options) {
      $mapping_config = $this->getMappingConfig($metadata_prefix);
      $form['mapping'][$metadata_prefix] = [
        '#type' => 'select',
        '#empty_value' => '',
        '#options' => $options,
        '#title' => $metadata_prefix,
        '#default_value' => !$mapping_config ? '' : $mapping_config,
      ];
    }

    $form['view'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => TRUE,
      '#title' => $this->t('MODS View'),
      '#attributes' => ['style' => 'max-width: 750px'],
      '#description' => $this->t('<p>Set which view to pull information from for the MODS mapping.</p>'),
    ];
    $view = $config->get('mods_view');
    $form['view']['view_machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View Machine Name'),
      '#default_value' => empty($view['view_machine_name']) ? '' : $view['view_machine_name'],
    ];
    $form['view']['view_display_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('View Display Name'),
      '#default_value' => empty($view['view_display_name']) ? '' : $view['view_display_name'],
    ];

    $name = $config->get('repository_name');
    $form['repository_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Name'),
      '#default_value' => $name ?: $this->config('system.site')->get('name'),
      '#required' => TRUE,
    ];

    $email = $config->get('repository_email');
    $form['repository_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Repository Admin E-Mail'),
      '#default_value' => $email ?: $this->config('system.site')->get('mail'),
      '#required' => TRUE,
    ];

    $path = $config->get('repository_path');
    $form['repository_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Path'),
      '#default_value' => $path ?: OaiPmh::OAI_DEFAULT_PATH,
      '#required' => TRUE,
    ];

    $expiration = $config->get('expiration');
    $form['expiration'] = [
      '#type' => 'number',
      '#title' => $this->t('The number of seconds until a resumption token expires'),
      '#default_value' => $expiration ?: 3600,
      '#required' => TRUE,
    ];

    $cache_technique = $config->get('cache_technique');
    $form['cache_technique'] = [
      '#title' => $this->t('Repository Caching Technique'),
      '#description' => $this->t(
          '<p>Select which caching technique should be used to ensure your repository is up to date with changes in your repo.</p>
        <ul>
          <li>Liberal Cache Clearing Strategy: will clear cache whenever an entity that could possibly effect the OAI repository is added/edited/updated</li>
          <li>Conservative Cache Clearing Strategy: will only remove entities from the OAI repository when they are deleted from Drupal. Will require more manual rebuilds or cron configuration.</li>
        </ul>'
      ),
      '#type' => 'select',
      '#options' => [],
      '#required' => TRUE,
      '#default_value' => $cache_technique ?: 'liberal_cache',
    ];
    foreach (\Drupal::service('plugin.manager.oai_cache')->getDefinitions() as $plugin_id => $plugin_definition) {
      $form['cache_technique']['#options'][$plugin_id] = $plugin_definition['label']->render();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $submitted_path = $form_state->getValue('repository_path');
    $submitted_path = "/" . trim($submitted_path, "\r\n\t /");
    $old_path = $this->config('rest_oai_pmh.settings')->get('repository_path');
    // If they haven't set a path before, make it the default.
    if (!$old_path) {
      $old_path = OaiPmh::OAI_DEFAULT_PATH;
    }

    // If the admin is changing the path
    // make sure the path they're changing it to doesn't already exist.
    if ($submitted_path !== OaiPmh::OAI_DEFAULT_PATH
          && $this->pathValidator->getUrlIfValidWithoutAccessCheck($submitted_path)
      ) {
      $form_state->setErrorByName('repository_path', $this->t('The path you attempted to change to already exists.'));
    }
    // If the admin changed the OAI endpoint path
    // invalidate cache and rebuild routes.
    elseif ($submitted_path !== $old_path) {
      $this->updateRestEndpointPath($submitted_path);

      // Check that the next path exists
      // if it doesn't, revert to what the path was before and throw an error.
      if (!$this->pathValidator->getUrlIfValidWithoutAccessCheck($submitted_path)) {
        $this->updateRestEndpointPath($old_path);
        $form_state->setErrorByName('repository_path', $this->t('Could not save the path you provided. Please check that the path does not contain any invalid characters.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('rest_oai_pmh.settings');

    $old_view_displays = $config->get('view_displays');
    $view_displays = [];
    $rebuild_views = [];
    foreach ($form_state->getValue('view_displays') as $view_display => $enabled) {
      if ($enabled) {
        $view_displays[$view_display] = $view_display;
        if (empty($old_view_displays[$view_display])) {
          $rebuild_views[] = $view_display;
        }
      }
      else {
        rest_oai_pmh_remove_sets_by_display_id($view_display);
      }
    }

    $mapping = [];
    foreach ($form_state->getValue('mapping') as $label => $value) {
      $mapping[] = [
        'label' => $label,
        'value' => $value,
      ];
    }

    $config->set('view_displays', $view_displays)
      ->set('support_sets', $form_state->getValue('support_sets'))
      ->set('metadata_map_plugins', $mapping)
      ->set('mods_view', $form_state->getValue('view'))
      ->set('repository_name', $form_state->getValue('repository_name'))
      ->set('repository_email', $form_state->getValue('repository_email'))
      ->set('expiration', $form_state->getValue('expiration'))
      ->set('cache_technique', $form_state->getValue('cache_technique'))
      ->save();

    rest_oai_pmh_cache_views($rebuild_views);
  }

  /**
   * Helper function when updating the OAI endpoint path.
   */
  protected function updateRestEndpointPath($path) {
    $this->config('rest_oai_pmh.settings')
      ->set('repository_path', $path)
      ->save();

    // When updating the REST endpoint's path
    // the route path needs cleared to enable the new path.
    $this->cacheDiscovery->delete('rest_plugins');
    $this->routerBuilder->rebuild();
  }

}
