<?php
/**
 * activity_logger.php
 *
 * Kept for backward compatibility only.
 * logActivity() is now fully defined in Connections/functions.php.
 * Do NOT redeclare it here.
 */

if (!function_exists('logActivity')) {
    require_once __DIR__ . '/../Connections/functions.php';
}