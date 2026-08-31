<?php
/**
 * Plugin Name: Gravity Forms Data Retention Policy
 * Description: Enforces a site-wide maximum retention policy across Gravity Forms entries.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Update URI: https://github.com/cchatterton/gravity-forms-data-retention-policy
 * Author: AlphaSys
 * Author URI: https://alphasys.com.au
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gravity-forms-data-retention-policy
 * Network: true
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFDRP_VERSION', '1.0.1' );
define( 'GFDRP_PLUGIN_FILE', __FILE__ );
define( 'GFDRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFDRP_SETTINGS_OPTION', 'gravityformsaddon_gfdrp_settings' );
define( 'GFDRP_SITE_VERSION_OPTION', 'gfdrp_site_version' );

require_once GFDRP_PLUGIN_DIR . 'functions/retention.php';
require_once GFDRP_PLUGIN_DIR . 'functions/setup.php';
require_once GFDRP_PLUGIN_DIR . 'functions/github-updater.php';

register_activation_hook( GFDRP_PLUGIN_FILE, 'gfdrp_activate' );

add_action( 'gform_loaded', 'gfdrp_load_addon', 5 );
add_action( 'gform_loaded', 'gfdrp_register_retention_hooks', 8 );
add_action( 'gform_loaded', 'gfdrp_maybe_initialize_current_site', 20 );
add_action( 'wp_initialize_site', 'gfdrp_initialize_new_network_site', 100 );
add_action( 'admin_notices', 'gfdrp_gravity_forms_notice' );
add_action( 'network_admin_notices', 'gfdrp_gravity_forms_notice' );

new GFDRP_GitHub_Updater();
