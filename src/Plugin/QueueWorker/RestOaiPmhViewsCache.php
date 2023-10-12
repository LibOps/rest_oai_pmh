<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

/**
 * Define a queue worker to populate the OAI cache table.
 *
 * @QueueWorker(
 *   id = "rest_oai_pmh_views_cache",
 *   title = @Translation("REST OAI-PMH Views Cache")
 * )
 */
class RestOaiPmhViewsCache extends RestOaiPmhViewsCacheBase {
}
