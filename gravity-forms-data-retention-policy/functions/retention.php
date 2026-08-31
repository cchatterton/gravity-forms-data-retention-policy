<?php
/**
 * Retention policy validation and enforcement.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the default site policy.
 *
 * @return array{policy:string,retain_entries_days:int}
 */
function gfdrp_default_settings() {
	return array(
		'policy'              => 'delete',
		'retain_entries_days' => 28,
	);
}

/**
 * Sanitize a site policy.
 *
 * @param mixed $settings Candidate settings.
 * @return array{policy:string,retain_entries_days:int}
 */
function gfdrp_sanitize_settings( $settings ) {
	$defaults = gfdrp_default_settings();
	$settings = is_array( $settings ) ? $settings : array();
	$policy   = isset( $settings['policy'] ) ? sanitize_key( (string) $settings['policy'] ) : $defaults['policy'];

	if ( ! in_array( $policy, array( 'retain', 'trash', 'delete' ), true ) ) {
		$policy = $defaults['policy'];
	}

	$days = isset( $settings['retain_entries_days'] ) ? absint( $settings['retain_entries_days'] ) : $defaults['retain_entries_days'];

	return array(
		'policy'              => $policy,
		'retain_entries_days' => max( 1, $days ),
	);
}

/**
 * Sanitize settings before the Add-On Framework writes them.
 *
 * @param mixed $settings Candidate settings.
 * @return array{policy:string,retain_entries_days:int}
 */
function gfdrp_sanitize_settings_option( $settings ) {
	return gfdrp_sanitize_settings( $settings );
}

/**
 * Get the current site's retention policy.
 *
 * @return array{policy:string,retain_entries_days:int}
 */
function gfdrp_get_site_policy() {
	return gfdrp_sanitize_settings( get_option( GFDRP_SETTINGS_OPTION, gfdrp_default_settings() ) );
}

/**
 * Apply the site ceiling to one form without weakening a stricter form rule.
 *
 * Policy strength is retain < trash < delete. Automated form policies are also
 * capped at the site day limit, regardless of their disposal action.
 *
 * @param array      $form        Gravity Forms form object.
 * @param array|null $site_policy Optional site policy for batch operations.
 * @return array
 */
function gfdrp_apply_site_policy_to_form( $form, $site_policy = null ) {
	if ( ! is_array( $form ) ) {
		return $form;
	}

	$site_policy = gfdrp_sanitize_settings( null === $site_policy ? gfdrp_get_site_policy() : $site_policy );

	if ( 'retain' === $site_policy['policy'] ) {
		return $form;
	}

	$current_policy = isset( $form['personalData']['retention']['policy'] )
		? sanitize_key( (string) $form['personalData']['retention']['policy'] )
		: 'retain';
	$current_days   = isset( $form['personalData']['retention']['retain_entries_days'] )
		? absint( $form['personalData']['retention']['retain_entries_days'] )
		: 0;
	$strength       = array(
		'retain' => 0,
		'trash'  => 1,
		'delete' => 2,
	);

	if ( ! isset( $strength[ $current_policy ] ) ) {
		$current_policy = 'retain';
	}

	$effective_policy = $strength[ $current_policy ] >= $strength[ $site_policy['policy'] ]
		? $current_policy
		: $site_policy['policy'];
	$effective_days   = $current_days > 0
		? min( $current_days, $site_policy['retain_entries_days'] )
		: $site_policy['retain_entries_days'];

	if ( ! isset( $form['personalData'] ) || ! is_array( $form['personalData'] ) ) {
		$form['personalData'] = array();
	}

	$form['personalData']['retention'] = array(
		'policy'              => $effective_policy,
		'retain_entries_days' => $effective_days,
	);

	return $form;
}

/**
 * Enforce the site policy whenever Gravity Forms saves display metadata.
 *
 * @param mixed  $form_meta Form metadata.
 * @param int    $form_id   Form ID.
 * @param string $meta_name Metadata type.
 * @return mixed
 */
function gfdrp_enforce_form_update( $form_meta, $form_id, $meta_name ) {
	unset( $form_id );

	if ( 'display_meta' !== $meta_name || ! is_array( $form_meta ) ) {
		return $form_meta;
	}

	return gfdrp_apply_site_policy_to_form( $form_meta );
}

/**
 * Synchronize every existing form on the current site.
 *
 * @return array{checked:int,updated:int,failed:int}
 */
function gfdrp_synchronize_existing_forms() {
	$result = array(
		'checked' => 0,
		'updated' => 0,
		'failed'  => 0,
	);

	if ( ! class_exists( 'GFAPI' ) ) {
		return $result;
	}

	$forms       = GFAPI::get_forms( null, null );
	$site_policy = gfdrp_get_site_policy();

	if ( ! is_array( $forms ) ) {
		return $result;
	}

	foreach ( $forms as $form ) {
		if ( ! is_array( $form ) ) {
			continue;
		}

		++$result['checked'];
		$enforced_form = gfdrp_apply_site_policy_to_form( $form, $site_policy );

		if ( $enforced_form === $form ) {
			continue;
		}

		$update_result = GFAPI::update_form( $enforced_form );

		if ( is_wp_error( $update_result ) ) {
			++$result['failed'];
			continue;
		}

		++$result['updated'];
	}

	return $result;
}

/**
 * Reapply the site ceiling after the Add-On Framework changes its option.
 *
 * @param string $option Option name.
 * @param mixed  $value  Option value.
 */
function gfdrp_settings_option_added( $option, $value ) {
	unset( $value );

	if ( GFDRP_SETTINGS_OPTION === $option ) {
		gfdrp_synchronize_existing_forms();
	}
}

/**
 * Reapply the site ceiling after the Add-On Framework changes its option.
 *
 * @param string $option    Option name.
 * @param mixed  $old_value Previous value.
 * @param mixed  $value     New value.
 */
function gfdrp_settings_option_updated( $option, $old_value, $value ) {
	unset( $old_value, $value );

	if ( GFDRP_SETTINGS_OPTION === $option ) {
		gfdrp_synchronize_existing_forms();
	}
}

/**
 * Register enforcement hooks after Gravity Forms is loaded.
 */
function gfdrp_register_retention_hooks() {
	add_filter( 'pre_update_option_' . GFDRP_SETTINGS_OPTION, 'gfdrp_sanitize_settings_option' );
	add_filter( 'gform_form_update_meta', 'gfdrp_enforce_form_update', 10, 3 );
	add_action( 'added_option', 'gfdrp_settings_option_added', 10, 2 );
	add_action( 'updated_option', 'gfdrp_settings_option_updated', 10, 3 );
}
