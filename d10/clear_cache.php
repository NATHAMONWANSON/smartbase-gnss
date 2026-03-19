<?php
// Load Drupal
define('DRUPAL_ROOT', getcwd());
require_once DRUPAL_ROOT . '/web/core/includes/bootstrap.inc';

// Bootstrap Drupal
\Drupal::service('kernel')->boot();

// Clear all caches
drupal_flush_all_caches();

echo "Cache cleared successfully\n";
?>
