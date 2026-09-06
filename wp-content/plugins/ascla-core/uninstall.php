<?php
// Deliberately retain community data. Export/backup and an explicit maintenance
// procedure are required before removal. Uninstall never destroys member content.
defined('WP_UNINSTALL_PLUGIN') || exit;
wp_clear_scheduled_hook('ascla_jobs');
wp_clear_scheduled_hook('ascla_monthly');
