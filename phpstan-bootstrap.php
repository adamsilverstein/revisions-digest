<?php
/**
 * PHPStan bootstrap file for WordPress constants and stubs.
 *
 * This file defines WordPress constants and classes that PHPStan needs
 * to analyze the codebase correctly.
 *
 * @package revisions-digest
 */

// WordPress core constants.
define( 'ABSPATH', '/var/www/html/' );
define( 'WPINC', 'wp-includes' );

// Time constants.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
