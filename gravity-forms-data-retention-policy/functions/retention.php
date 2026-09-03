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
 * Return whether retention enforcement is currently active on this site.
 *
 * @return bool
 */
function gfdrp_is_policy_active() {
	return 'active' === get_option( GFDRP_STATUS_OPTION, 'inactive' );
}

/**
 * Return the policy that was explicitly activated.
 *
 * @return array{policy:string,retain_entries_days:int}
 */
function gfdrp_get_applied_policy() {
	return gfdrp_sanitize_settings( get_option( GFDRP_APPLIED_POLICY_OPTION, gfdrp_get_site_policy() ) );
}

/**
 * Return form IDs excluded from the active site policy.
 *
 * @return int[]
 */
function gfdrp_get_excluded_form_ids() {
	$value = get_option( GFDRP_EXCLUDED_FORMS_OPTION, array() );
	$value = is_array( $value ) ? $value : array();

	return array_values( array_filter( array_unique( array_map( 'absint', $value ) ) ) );
}

/**
 * Determine whether a form exactly inherits a site policy.
 *
 * The day value is irrelevant while the effective policy is to retain entries.
 *
 * @param array $form   Gravity Forms form object.
 * @param array $policy Retention policy.
 * @return bool
 */
function gfdrp_form_matches_policy( $form, $policy ) {
	if ( ! is_array( $form ) ) {
		return false;
	}

	$policy         = gfdrp_sanitize_settings( $policy );
	$current_policy = isset( $form['personalData']['retention']['policy'] )
		? sanitize_key( (string) $form['personalData']['retention']['policy'] )
		: 'retain';

	if ( $current_policy !== $policy['policy'] ) {
		return false;
	}

	if ( 'retain' === $current_policy ) {
		return true;
	}

	$current_days = isset( $form['personalData']['retention']['retain_entries_days'] )
		? absint( $form['personalData']['retention']['retain_entries_days'] )
		: 0;

	return $current_days === $policy['retain_entries_days'];
}

/**
 * Set a form to an exact policy while preserving unrelated personal-data fields.
 *
 * @param array $form   Gravity Forms form object.
 * @param array $policy Retention policy.
 * @return array
 */
function gfdrp_set_form_retention_policy( $form, $policy ) {
	$policy = gfdrp_sanitize_settings( $policy );

	if ( ! isset( $form['personalData'] ) || ! is_array( $form['personalData'] ) ) {
		$form['personalData'] = array();
	}

	$form['personalData']['retention'] = $policy;

	return $form;
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

	return gfdrp_set_form_retention_policy(
		$form,
		array(
			'policy'              => $effective_policy,
			'retain_entries_days' => $effective_days,
		)
	);
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
	if ( ! gfdrp_is_policy_active() || 'display_meta' !== $meta_name || ! is_array( $form_meta ) || in_array( absint( $form_id ), gfdrp_get_excluded_form_ids(), true ) ) {
		return $form_meta;
	}

	return gfdrp_apply_site_policy_to_form( $form_meta, gfdrp_get_applied_policy() );
}

/**
 * Synchronize every existing form on the current site.
 *
 * @param array|null $previous_policy Policy previously saved at site level.
 * @param array|null $site_policy     New policy, or the current saved policy.
 * @param int[]|null $included_ids    Optional form IDs selected from a policy test.
 * @return array{checked:int,updated:int,failed:int}
 */
function gfdrp_synchronize_existing_forms( $previous_policy = null, $site_policy = null, $included_ids = null ) {
	$result = array(
		'checked' => 0,
		'updated' => 0,
		'failed'  => 0,
	);

	if ( ! class_exists( 'GFAPI' ) ) {
		return $result;
	}

	$forms           = GFAPI::get_forms( null, null );
	$site_policy     = gfdrp_sanitize_settings( null === $site_policy ? gfdrp_get_site_policy() : $site_policy );
	$previous_policy = null === $previous_policy ? null : gfdrp_sanitize_settings( $previous_policy );
	$included_ids    = is_array( $included_ids ) ? array_values( array_unique( array_map( 'absint', $included_ids ) ) ) : null;

	if ( ! is_array( $forms ) ) {
		return $result;
	}

	foreach ( $forms as $form ) {
		if ( ! is_array( $form ) ) {
			continue;
		}

		if ( null !== $included_ids && ! in_array( absint( $form['id'] ?? 0 ), $included_ids, true ) ) {
			continue;
		}

		++$result['checked'];
		$enforced_form = null !== $previous_policy && gfdrp_form_matches_policy( $form, $previous_policy )
			? gfdrp_set_form_retention_policy( $form, $site_policy )
			: gfdrp_apply_site_policy_to_form( $form, $site_policy );

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
 * Mark the policy inactive after its saved configuration changes.
 *
 * @param string $option    Option name.
 * @param mixed  $old_value Previous value.
 * @param mixed  $value     New value.
 */
function gfdrp_settings_option_updated( $option, $old_value, $value ) {
	if ( GFDRP_SETTINGS_OPTION !== $option ) {
		return;
	}

	$old_policy = gfdrp_sanitize_settings( $old_value );
	$new_policy = gfdrp_sanitize_settings( $value );

	if ( $old_policy !== $new_policy ) {
		update_option( GFDRP_STATUS_OPTION, 'inactive' );
	}
}

/**
 * Register enforcement hooks after Gravity Forms is loaded.
 */
function gfdrp_register_retention_hooks() {
	add_filter( 'pre_update_option_' . GFDRP_SETTINGS_OPTION, 'gfdrp_sanitize_settings_option' );
	add_filter( 'gform_form_update_meta', 'gfdrp_enforce_form_update', 10, 3 );
	add_action( 'updated_option', 'gfdrp_settings_option_updated', 10, 3 );
}
