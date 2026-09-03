<?php
/**
 * Plugin activation and Gravity Forms bootstrap.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize settings and forms for the current site.
 */
function gfdrp_initialize_current_site() {
	$installed_version = get_option( GFDRP_SITE_VERSION_OPTION, '' );

	if ( false === get_option( GFDRP_SETTINGS_OPTION, false ) ) {
		update_option( GFDRP_SETTINGS_OPTION, gfdrp_default_settings() );
	}

	if ( '' !== $installed_version && version_compare( $installed_version, '1.1.0', '<' ) && false === get_option( GFDRP_APPLIED_POLICY_OPTION, false ) ) {
		update_option( GFDRP_APPLIED_POLICY_OPTION, gfdrp_get_site_policy() );
	}

	if ( false === get_option( GFDRP_STATUS_OPTION, false ) ) {
		update_option( GFDRP_STATUS_OPTION, 'inactive' );
	}

	update_option( GFDRP_SITE_VERSION_OPTION, GFDRP_VERSION );
}

/**
 * Initialize a single site only when its setup version is absent or old.
 */
function gfdrp_maybe_initialize_current_site() {
	if ( GFDRP_VERSION !== get_option( GFDRP_SITE_VERSION_OPTION, '' ) ) {
		gfdrp_initialize_current_site();
	}
}

/**
 * Run plugin activation for a single site or an entire network.
 *
 * @param bool $network_wide Whether activation was requested network-wide.
 */
function gfdrp_activate( $network_wide ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			gfdrp_initialize_current_site();
			restore_current_blog();
		}

		return;
	}

	gfdrp_initialize_current_site();
}

/**
 * Initialize a newly created site when this plugin is network active.
 *
 * @param WP_Site $new_site New site object.
 */
function gfdrp_initialize_new_network_site( $new_site ) {
	if ( ! is_multisite() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( ! is_plugin_active_for_network( plugin_basename( GFDRP_PLUGIN_FILE ) ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	gfdrp_initialize_current_site();
	restore_current_blog();
}

/**
 * Register the Gravity Forms Add-On Framework integration.
 */
function gfdrp_load_addon() {
	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	require_once GFDRP_PLUGIN_DIR . 'functions/admin.php';
	GFAddOn::register( 'GFDRP_Addon' );
}

/**
 * Explain the missing dependency without blocking WordPress.
 */
function gfdrp_gravity_forms_notice() {
	if ( class_exists( 'GFForms' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Gravity Forms Data Retention Policy requires Gravity Forms 2.5.8 or later. Its retention settings will become available when Gravity Forms is active.', 'gravity-forms-data-retention-policy' );
	echo '</p></div>';
}
