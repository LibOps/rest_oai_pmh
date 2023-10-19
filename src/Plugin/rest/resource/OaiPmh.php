<?php

namespace Drupal\rest_oai_pmh\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RenderContext;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "oai_pmh",
 *   label = @Translation("OAI-PMH"),
 *   uri_paths = {
 *     "canonical" = "/oai/request",
 *     "https://www.drupal.org/link-relations/create" = "/oai/request"
 *   }
 * )
 */
class OaiPmh extends ResourceBase {

  const OAI_DEFAULT_PATH = '/oai/request';
  const OAI_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The request being served.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The REST response.
   *
   * @var \Drupal\rest\ResourceResponse
   */
  private $response = [];

  /**
   * An array of error(s) for the response. Default false.
   *
   * @var array
   */
  private $error = FALSE;

  /**
   * An entity that is being exposed to OAI-PMH.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $entity;


  /**
   * The entity's type.
   *
   * @var string
   */
  private $bundle;


  /**
   * The OAI-PMH verb passed into the request.
   *
   * @var string
   */
  private $verb;


  /**
   * The view display IDs rendered by this OAI-PMH endpoint.
   *
   * @var array
   */
  private $viewDisplays;


  /**
   * The name of the repository this OAI-PMH endpoint is serving content for.
   *
   * @var string
   */
  private $repositoryName;

  /**
   * The name of the repository this OAI-PMH endpoint is serving content for.
   *
   * @var string
   */
  private $repositoryEmail;

  /**
   * The name of the repository this OAI-PMH endpoint is serving content for.
   *
   * @var string
   */
  private $repositoryPath;


  /**
   * Unix timestamp when the resumption token expires.
   *
   * @var int
   */
  private $expiration;

  /**
   * Object used to stored metadata about an OAI entity in current response.
   *
   * @var object
   */
  private $oaiEntity;

  /**
   * The metadataPrefix GET parameter from the current request.
   *
   * @var string
   */
  private $metadataPrefix;

  /**
   * The resumption token ID that will be returned in the current response.
   *
   * @var int
   */
  private $nextTokenId;

  /**
   * Kv store to get the current rest_oai_pmh.resumption_token.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  private $keyValueStore;

  /**
   * Module responsible for mapping Drupal fields to a metadata schema.
   *
   * @var string
   */
  private $metadataMapPlugins;

  /**
   * How OAI maps Drupal fields.
   *
   * @var string
   */
  private $mappingSource;

  /**
   * Whether this OAI endpoint support sets.
   *
   * @var bool
   */
  private $supportSets;

  /**
   * Constructs a new OaiPmh object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   An immutable config object.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Symfony\Component\HttpFoundation\Request $currentRequest
   *   The request being made to the OAI-PMH endpoint.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   A module handler instance.
   */
  public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        array $serializer_formats,
        ImmutableConfig $config,
        LoggerInterface $logger,
        AccountProxyInterface $current_user,
        Request $currentRequest,
        ModuleHandlerInterface $module_handler
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
    $this->currentRequest = $currentRequest;
    $this->moduleHandler = $module_handler;

    // Read the config settings for this endpoint
    // and set some properties for this class from the config.
    $config = \Drupal::config('rest_oai_pmh.settings');
    $fields = [
      'bundle' => 'bundle',
      'view_displays' => 'viewDisplays',
      'repository_name' => 'repositoryName',
      'repository_email' => 'repositoryEmail',
      'repository_path' => 'repositoryPath',
      'expiration' => 'expiration',
      'support_sets' => 'supportSets',
      'mapping_source' => 'mappingSource',
      'metadata_map_plugins' => 'metadataMapPlugins',
    ];
    foreach ($fields as $field => $property) {
      // Metadata map plugins are stored in
      // ['label' => label, 'value' => value] format.
      if ($field == 'metadata_map_plugins') {
        $map_plugins = [];
        if (is_array($config->get($field))) {
          foreach ($config->get($field) as $map) {
            $map_plugins[$map['label']] = $map['value'];
          }
        }
        $this->{$property} = $map_plugins;
      }
      else {
        $this->{$property} = $config->get($field);
      }
    }

    // Make sure the path is always set
    // if we don't have one, resort to default value.
    if (!$this->repositoryPath) {
      $this->repositoryPath = self::OAI_DEFAULT_PATH;
    }

    // Create a key/value store for resumption tokens.
    $this->keyValueStore = \Drupal::keyValue('rest_oai_pmh.resumption_token');
    $this->nextTokenId = $this->keyValueStore
      ->get('nextTokenId');
    if (!$this->nextTokenId) {
      $this->nextTokenId = 1;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $container->getParameter('serializer.formats'),
          $container->get('config.factory')->get('rest_oai_pmh.settings'),
          $container->get('logger.factory')->get('rest_oai_pmh'),
          $container->get('current_user'),
          $container->get('request_stack')->getCurrentRequest(),
          $container->get('module_handler')
      );
  }

  /**
   * Responds to POST requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post() {
    return $this->get();
  }

  /**
   * Responds to GET requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {
    // Init a basic response used by all verbs.
    $base_oai_url = $this->currentRequest->getSchemeAndHttpHost() . $this->repositoryPath;
    $this->response = [
      '@xmlns' => 'http://www.openarchives.org/OAI/2.0/',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd',
      'responseDate' => gmdate(self::OAI_DATE_FORMAT, \Drupal::time()->getRequestTime()),
      'request' => [
        'oai-dc-string' => $base_oai_url,
      ],
    ];

    $verb = $this->currentRequest->get('verb');
    $set_id = $this->currentRequest->get('set');
    $verbs = [
      'GetRecord',
      'Identify',
      'ListIdentifiers',
      'ListMetadataFormats',
      'ListRecords',
      'ListSets',
    ];
    // Make sure a valid verb was passed in as a GET parameter
    // if so, call the respective function implemented in this class.
    if (in_array($verb, $verbs)) {
      // If we do not have any entries in the cached table,
      // the cache needs rebuilt.
      // Do so now instead of waiting on Drupal cron to avoid empty results.
      if (\Drupal::database()->query('SELECT COUNT(*) FROM {rest_oai_pmh_record}')->fetchField() == 0) {
        $context = new RenderContext();
        \Drupal::service('renderer')->executeInRenderContext(
              $context, function () {
                  rest_oai_pmh_rebuild_entries();
              }
          );
      }
      $this->response['request']['@verb'] = $this->verb = $verb;

      // Since we're using protected functions for the verbs
      // the function names are in camelcase.
      $f = lcfirst($verb);
      $this->{$f}();
    }
    // If not a valid verb, print the error message.
    else {
      $this->setError('badVerb', 'Value of the verb argument is not a legal OAI-PMH verb, the verb argument is missing, or the verb argument is repeated.');
    }

    // resumptionToken needs to be at the bottom of the request
    // so if it exists, go take it out of the array
    // and add it back to ensure it's the last element in the array.
    if (!empty($this->response[$this->verb]['resumptionToken']) && count($this->response[$this->verb]['resumptionToken'])) {
      $resumption_token = $this->response[$this->verb]['resumptionToken'];
      unset($this->response[$this->verb]['resumptionToken']);
      $this->response[$this->verb]['resumptionToken'] = $resumption_token;
    }

    $response = new ResourceResponse($this->response, 200);

    // @todo for now disabling cache altogether until can come up with sensible method if there is one
    $response->addCacheableDependency(
          [
            '#cache' => [
              'max-age' => 0,
            ],
          ]
      );
    \Drupal::service('page_cache_kill_switch')->trigger();

    return $response;
  }

  /**
   * Functionality for the OAI-PMH verb GetRecord.
   */
  protected function getRecord() {

    $identifier = $this->currentRequest->get('identifier');
    if (empty($identifier)) {
      $this->setError('badArgument', 'Missing required argument identifier.');
    }

    $this->loadEntity($identifier);

    $components = explode(':', $identifier);
    $host = $this->currentRequest->getHttpHost();
    // Remove port from hostname.
    if (strpos($host, ':') !== FALSE) {
      $host_parts = explode(':', $host);
      $host = $host_parts[0];
    }

    // Check to ensure the identifier is valid
    // and an entity was loaded.
    if (count($components) != 3
          || $components[0] !== 'oai'
          || $components[1] !== $host
          || empty($this->entity)
      ) {
      $this->setError('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
    }

    $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    $this->checkMetadataPrefix();

    // Check if an error was thrown.
    if ($this->error) {
      // Per OAI specs, remove the verb from the response.
      unset($this->response['request']['@verb']);
    }
    // If no error, print the record.
    else {
      $this->response['request']['@metadataPrefix'] = $this->metadataPrefix;
      $this->response[$this->verb]['record'] = $this->getRecordById($identifier);
    }
  }

  /**
   * Functionality for the OAI-PMH verb Identify.
   */
  protected function identify() {
    // Query our table to see the oldest entity exposed to OAI.
    $earliest_date = \Drupal::database()->query(
          'SELECT MIN(created)
      FROM {rest_oai_pmh_record}'
      )->fetchField();

    $this->response[$this->verb] = [
      'repositoryName' => $this->repositoryName,
      'baseURL' => $this->currentRequest->getSchemeAndHttpHost() . $this->repositoryPath,
      'protocolVersion' => '2.0',
      'adminEmail' => $this->repositoryEmail,
      'earliestDatestamp' => gmdate(self::OAI_DATE_FORMAT, $earliest_date),
      'deletedRecord' => 'no',
      'granularity' => 'YYYY-MM-DDThh:mm:ssZ',
      'description' => [
        'oai-identifier' => [
          '@xmlns' => 'http://www.openarchives.org/OAI/2.0/oai-identifier',
          '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd',
          'scheme' => 'oai',
          'repositoryIdentifier' => $this->currentRequest->getHttpHost(),
          'delimiter' => ':',
          'sampleIdentifier' => 'oai:' . $this->currentRequest->getHttpHost() . ':node-1',
        ],
      ],
    ];
  }

  /**
   * Functionality for the OAI-PMH verb ListMetadataFormats.
   */
  protected function listMetadataFormats() {
    // @todo support more metadata formats
    $formats = [];
    foreach ($this->getMetadataFormats() as $format) {
      $plugin = $this->getMetadataPlugin($format);
      $formats[] = $plugin->getMetadataFormat();
    }
    $this->response[$this->verb]['metadataFormat'] = $formats;
  }

  /**
   * Functionality for the OAI-PMH verb ListIdentifiers.
   */
  protected function listIdentifiers() {
    $entities = $this->getRecordIds();
    foreach ($entities as $entity) {
      $this->oaiEntity = $entity;
      $identifier = $this->buildIdentifier($entity);
      $this->loadEntity($identifier, TRUE);
      $this->response[$this->verb]['header'][] = $this->getHeaderById($identifier);
    }
    if (empty($this->response[$this->verb]['header'])) {
      $this->setError('noRecordsMatch', 'No records found.');
      unset($this->response[$this->verb]);
    }
  }

  /**
   * Functionality for the OAI-PMH verb ListRecords.
   */
  protected function listRecords() {
    $entities = $this->getRecordIds();
    foreach ($entities as $entity) {
      $this->oaiEntity = $entity;
      $identifier = $this->buildIdentifier($entity);
      $this->loadEntity($identifier, TRUE);
      $this->response[$this->verb]['record'][] = $this->getRecordById($identifier);
    }
    if (empty($this->response[$this->verb]['record'])) {
      $this->setError('noRecordsMatch', 'No records found.');
      unset($this->response[$this->verb]);
    }
  }

  /**
   * Functionality for the OAI-PMH verb ListSets.
   */
  protected function listSets() {
    // Throw an error if no Views available.
    if (count($this->viewDisplays) == 0 || empty($this->supportSets)) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
      return;
    }

    $this->response[$this->verb] = [];

    $sets = \Drupal::database()->query('SELECT set_id, label FROM {rest_oai_pmh_set}');
    foreach ($sets as $set) {
      $this->response[$this->verb]['set'][] = [
        'setSpec' => $set->set_id,
        'setName' => $set->label,
      ];
    }
  }

  /**
   * Helper function.
   *
   * A lot of different scenarios can cause an error based on GET parameters, so
   *   have a standard convention to record these errors and print them in XML.
   */
  protected function setError($code, $string) {
    $this->response['error'][] = [
      '@code' => $code,
      'oai-dc-string' => $string,
    ];
    $this->error = TRUE;
  }

  /**
   * Helper function to get a record by identifier.
   */
  protected function getRecordById($identifier) {
    $record = [];
    $record['header'] = $this->getHeaderById($identifier);
    $record['metadata'] = $this->getRecordMetadata();

    return $record;
  }

  /**
   * Helper function to get header section of a record by identifier.
   */
  protected function getHeaderById($identifier) {
    $header = [
      'identifier' => $identifier,
    ];

    // If the entity being exposed to OAI has a changed field.
    // print that in the header.
    if ($this->entity->hasField('changed')) {
      $header['datestamp'] = gmdate(self::OAI_DATE_FORMAT, $this->entity->changed->value);
    }

    // If sets are supported
    // print the sets this record belongs to.
    if (!empty($this->oaiEntity) && !empty($this->supportSets)) {
      $sets = explode(',', $this->oaiEntity->sets);
      foreach ($sets as $set) {
        $header['setSpec'][] = $set;
      }
    }
    return $header;
  }

  /**
   * Helper function to get metadata section of a record by identifier.
   */
  protected function getRecordMetadata() {
    if (empty($this->metadataPrefix)) {
      $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    }

    // Transform the record with the relevant plugin.
    // Process the transformation to isolate any early rendering.
    $context = new RenderContext();
    $result = \Drupal::service('renderer')->executeInRenderContext(
          $context, function () {
              $mapping_plugin = $this->getMetadataPlugin($this->metadataPrefix);
              $record = $mapping_plugin->transformRecord($this->entity);
              $metadata = $mapping_plugin->getMetadataWrapper();
              $wrapper_key = array_keys($metadata)[0];
              $metadata[$wrapper_key]['metadata-xml'] = trim($record);
              return $metadata;
          }
      );
    return $result;
  }

  /**
   * Helper function to get record IDs.
   */
  private function getRecordIds() {
    $verb = $this->response['request']['@verb'];
    $resumption_token = $this->currentRequest->get('resumptionToken');
    $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    $set = $this->currentRequest->get('set');
    $from = $this->currentRequest->get('from');
    $until = $this->currentRequest->get('until');
    $cursor = 0;
    $completeListSize = 0;
    $views_total = [];
    // If a resumption token was passed, try to find it in the key store.
    if ($resumption_token) {
      $token = $this->keyValueStore->get($resumption_token);
      // If we found a token and it's not expired, get the values needed.
      if ($token
            && $token['expires'] > \Drupal::time()->getRequestTime()
            && $token['verb'] == $this->verb
        ) {
        $this->metadataPrefix = $token['metadata_prefix'];
        $cursor = $token['cursor'];
        $set = $token['set'];
        $from = $token['from'];
        $until = $token['until'];
        $completeListSize = $token['completeListSize'];
      }
      else {
        // If we found a token, and we're here, it means the token is expired
        // delete it from key value store.
        if ($token && $token['expires'] < \Drupal::time()->getRequestTime()) {
          $this->keyValueStore->delete($resumption_token);
        }
        $this->setError('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired.');
      }
    }
    // If a set parameter was passed, but this OAI endpoint doesn't support sets
    // throw an error.
    elseif ((empty($this->supportSets) || empty($this->viewDisplays)) && $set) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
    }

    $this->checkMetadataPrefix();

    $db_conn = \Drupal::database();

    if ($this->error) {
      return [];
    }
    else {
      // Our {rest_oai_pmh_set} table stores the pager information
      // for the Views exposed to OAI. To play it safe, make the
      // limit // max results returned be the smallest pager size
      // for all the Views exposed to OAI.
      $end = $db_conn->driver() !== 'pgsql' ?
             $db_conn->query('SELECT MIN(`pager_limit`) FROM {rest_oai_pmh_set}')->fetchField() :
             $db_conn->query('SELECT MIN(pager_limit) FROM {rest_oai_pmh_set}')->fetchField();
      $this->response['request']['@metadataPrefix'] = $this->metadataPrefix;
    }

    // Query our {rest_oai_pmh_*} tables to get records that are exposed to OAI.
    $query = $db_conn->select('rest_oai_pmh_record', 'r');
    $query->innerJoin('rest_oai_pmh_member', 'm', 'm.entity_id = r.entity_id AND m.entity_type = r.entity_type');
    $query->innerJoin('rest_oai_pmh_set', 's', 's.set_id = m.set_id');
    $query->fields('r', ['entity_id', 'entity_type']);
    if ($db_conn->driver() !== 'pgsql') {
      $query->addExpression('GROUP_CONCAT(m.set_id)', 'sets');
    }
    else {
      // XXX: GROUP_CONCAT() doesn't exist in PostgreSQL.
      $query->addExpression("STRING_AGG(m.set_id, ',')", 'sets');
    }
    $query->groupBy('r.entity_type')->groupBy('r.entity_id');

    // XXX: Result sets can be unpredictable when limiting without an ORDER BY
    // clause.
    // @see: https://www.postgresql.org/docs/current/queries-limit.html.
    $query->orderBy('r.entity_id');

    // If set ID was passed in URL, filter on that
    // otherwise filter on all sets as defined on set field.
    if ($set) {
      $query->condition('m.set_id', $set);
    }

    // If from was passed as  GET parameter, filter on that.
    if ($from) {
      $this->response['request']['@from'] = $from;
      $query->condition('changed', strtotime($from), '>=');
    }
    // If until was passed as  GET parameter, filter on that.
    if ($until) {
      $this->response['request']['@until'] = $until;
      $query->condition('changed', strtotime($until), '<=');
    }

    // If we haven't checked the complete list size yet
    // i.e. this isn't a call from a resumption token
    // get the complete list size for this request.
    if (empty($completeListSize)) {
      $completeListSize = $query->countQuery()->execute()->fetchField();
    }

    $this->response[$this->verb]['resumptionToken'] = [];

    // If the total results are more than what was returned here
    // add a resumption token.
    if ($completeListSize > ($cursor + $end) && $end > 0) {
      // Set the expiration date per the admin settings.
      $expires = \Drupal::time()->getRequestTime() + $this->expiration;

      $this->response[$this->verb]['resumptionToken'] += [
        '@completeListSize' => $completeListSize,
        '@cursor' => $cursor,
        'oai-dc-string' => $this->nextTokenId,
        '@expirationDate' => gmdate(self::OAI_DATE_FORMAT, $expires),
      ];

      // Save the settings for the resumption token
      // that will be shown in these results.
      $token = [
        'metadata_prefix' => $this->metadataPrefix,
        'set' => $set,
        'cursor' => $cursor + $end,
        'expires' => $expires,
        'verb' => $this->response['request']['@verb'],
        'from' => $from,
        'until' => $until,
        'completeListSize' => $completeListSize,
      ];
      $this->keyValueStore->set($this->nextTokenId, $token);

      // Increment the token id for the next resumption token that will show.
      // @todo should we incorporate semaphores here to avoid possible duplicates?
      $this->nextTokenId += 1;
      $this->keyValueStore->set('nextTokenId', $this->nextTokenId);
    }

    // Put a pager on the query if there's a pager on the Views exposed to OAI.
    if ($end > 0) {
      $query->range($cursor, $end);
    }

    $entities = $query->execute();

    return $entities;
  }

  /**
   * Helper function. Create an OAI identifier for the given entity.
   */
  protected function buildIdentifier($entity) {
    $identifier = 'oai:';
    $identifier .= $this->currentRequest->getHost();
    $identifier .= ':';
    $identifier .= $entity->entity_type;
    $identifier .= '-' . $entity->entity_id;

    return $identifier;
  }

  /**
   * Helper function. Load an entity to be printed in OAI endpoint.
   *
   * @param string $identifier
   *   OAI identifier for a record.
   * @param bool $skip_check
   *   Whether to ensure entity being passed in $identifier
   *   is indeed exposed to OAI. Some OAI verbs (like ListRecords)
   *   are querying only the entities that are indeed exposed to OAI.
   *   Other verbs, like GetRecord, get an identifier passed and
   *   are asked for the metadata for that record.
   *   So need to check that the entity is indeed in OAI.
   */
  protected function loadEntity($identifier, $skip_check = FALSE) {
    $entity = FALSE;
    $components = explode(':', $identifier);
    $id = empty($components[2]) ? FALSE : $components[2];
    if ($id) {
      [$entity_type, $entity_id] = explode('-', $id);

      try {
        // If we need to check whether the entity is in OAI, do so
        // we don't do this for ListRecords b/c we already know the entity
        // is in OAI since we queried it from the table we're checking against
        // but for GetRecord, the user is passing the identifier,
        // so we need to ensure it's legit
        // basically just a performance enhancement to not always check.
        if (!$skip_check) {

          // Fetch all sets the record belongs to
          // even if sets aren't supported by OAI,
          // our system still stores the set information
          // so it's a reliable method to check
          // PLUS we get all the sets the record belongs to
          // so we can print the sets in <header>.
          $d_args = [
            ':type' => $entity_type,
            ':id' => $entity_id,
          ];
          $db_conn = \Drupal::database();
          if ($db_conn->driver() !== 'pgsql') {
            $in_oai_view = $db_conn->query(
                  'SELECT GROUP_CONCAT(set_id) FROM {rest_oai_pmh_record} r
              INNER JOIN {rest_oai_pmh_member} m ON m.entity_id = r.entity_id AND m.entity_type = r.entity_type
              WHERE r.entity_id = :id
                AND r.entity_type = :type
              GROUP BY r.entity_id', $d_args
              )->fetchField();
          }
          else {
            // XXX: GROUP_CONCAT() doesn't exist in PostgreSQL.
            $in_oai_view = $db_conn->query(
                  'SELECT STRING_AGG(set_id, \',\') FROM {rest_oai_pmh_record} r
              INNER JOIN {rest_oai_pmh_member} m ON m.entity_id = r.entity_id AND m.entity_type = r.entity_type
              WHERE r.entity_id = :id
                AND r.entity_type = :type
              GROUP BY r.entity_id', $d_args
              )->fetchField();
          }

          // Store the set membership from our table
          // so we can print set membership in <header>.
          $this->oaiEntity = (object) ['sets' => $in_oai_view];
        }

        // If we're skipping the OAI check OR we didn't skip the check
        // and the record is in OAI load the entity.
        if ($skip_check || $in_oai_view) {
          $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
          $entity = $storage->load($entity_id);
        }
      }
      catch (Exception $e) {
      }
    }

    // Make sure the entity was loaded properly
    // AND the person viewing has access.
    $this->entity = $entity && $entity->access('view') ? $entity : FALSE;
  }

  /**
   * Helper function to check the value passed to metadataPrefix.
   */
  protected function checkMetadataPrefix() {
    // If no metadata prefix passed into request, throw error.
    if (empty($this->metadataPrefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    // Do we have a plugin configured for it?
    elseif (!in_array($this->metadataPrefix, $this->getMetadataFormats())) {
      $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }
  }

  /**
   * Returns the configured plugin associated with a given metadata prefix.
   */
  protected function getMetadataPlugin(string $metadata_prefix) {
    if (empty($this->metadataMapPlugins[$metadata_prefix])) {
      // Should probably throw an exception...
      return FALSE;
    }
    $mapping_plugin_manager = \Drupal::service('plugin.manager.oai_metadata_map');
    return $mapping_plugin_manager->createInstance($this->metadataMapPlugins[$metadata_prefix]);
  }

  /**
   * Returns a list of available metadata formats.
   *
   * @return array
   *   Available metadata formats.
   */
  protected function getMetadataFormats() {
    return array_keys(array_filter($this->metadataMapPlugins));
  }

}
