<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

/**
 * Define a queue worker to populate the OAI cache table on cron runs.
 *
 * @QueueWorker(
 *   id = "rest_oai_pmh_views_cache_cron",
 *   title = @Translation("REST OAI-PMH Views Cache (cron)"),
 *   cron = {"time" = 60}
 * )
 */
class RestOaiPmhViewsCacheCron extends RestOaiPmhViewsCacheBase {
}
