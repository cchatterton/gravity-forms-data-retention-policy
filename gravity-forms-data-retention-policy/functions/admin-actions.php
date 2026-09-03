<?php
/**
 * Secure policy and unused-form admin actions.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check access to retention administration.
 *
 * @return bool
 */
function gfdrp_user_can_manage_policy() {
	return current_user_can( 'gravityforms_edit_settings' ) || current_user_can( 'manage_options' );
}

/**
 * Return the current site's Gravity Forms retention settings URL.
 *
 * @param array $arguments Optional query arguments.
 * @return string
 */
function gfdrp_settings_url( $arguments = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page'    => 'gf_settings',
				'subview' => 'gfdrp',
			),
			$arguments
		),
		admin_url( 'admin.php' )
	);
}

/**
 * Build a nonce-protected admin action URL.
 *
 * @param string $action Admin-post action.
 * @return string
 */
function gfdrp_admin_action_url( $action ) {
	return wp_nonce_url(
		add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ),
		$action
	);
}

/**
 * Return a user-specific transient key.
 *
 * @param string $type Report type.
 * @return string
 */
function gfdrp_report_key( $type ) {
	return 'gfdrp_' . sanitize_key( $type ) . '_' . get_current_user_id();
}

/**
 * Require permission and a valid nonce for an admin action.
 *
 * @param string $action    Action name.
 * @param string $nonce_key Request field containing the action nonce.
 */
function gfdrp_verify_admin_action( $action, $nonce_key = '_wpnonce' ) {
	if ( ! gfdrp_user_can_manage_policy() ) {
		wp_die( esc_html__( 'You are not allowed to manage the retention policy.', 'gravity-forms-data-retention-policy' ) );
	}

	check_admin_referer( $action, $nonce_key );
}

/**
 * Return whether the current action uses a POST request.
 *
 * @return bool
 */
function gfdrp_is_post_request() {
	return 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
}

/**
 * Generate and retain a read-only policy impact report.
 */
function gfdrp_handle_test_policy() {
	gfdrp_verify_admin_action( 'gfdrp_test_policy' );
	$report = gfdrp_build_policy_report();
	set_transient( gfdrp_report_key( 'policy_report' ), $report, 15 * MINUTE_IN_SECONDS );
	wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'policy_tested' ) ) );
	exit;
}

/**
 * Apply the tested policy and enable ongoing enforcement.
 */
function gfdrp_handle_activate_policy() {
	gfdrp_verify_admin_action( 'gfdrp_activate_policy', 'gfdrp_activate_nonce' );
	$report         = get_transient( gfdrp_report_key( 'policy_report' ) );
	$current_policy = gfdrp_get_site_policy();

	if ( ! gfdrp_is_post_request() || ! is_array( $report ) || ! empty( $report['errors'] ) || ( $report['target_policy'] ?? null ) !== $current_policy ) {
		wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'test_required' ) ) );
		exit;
	}

	$requested_ids = isset( $_POST['gfdrp_policy_form_ids'] ) && is_array( $_POST['gfdrp_policy_form_ids'] )
		? array_map( 'absint', wp_unslash( $_POST['gfdrp_policy_form_ids'] ) )
		: array();
	$candidate_ids = array_map(
		'absint',
		array_column( is_array( $report['forms'] ?? null ) ? $report['forms'] : array(), 'id' )
	);
	$selected_ids  = array_values( array_intersect( array_unique( $requested_ids ), $candidate_ids ) );
	$excluded_ids  = array_values( array_diff( $candidate_ids, $selected_ids ) );

	$previous_setting = get_option( GFDRP_APPLIED_POLICY_OPTION, false );
	$previous_policy  = false === $previous_setting ? null : gfdrp_sanitize_settings( $previous_setting );
	$result           = gfdrp_synchronize_existing_forms( $previous_policy, $current_policy, $selected_ids );

	if ( 0 < $result['failed'] ) {
		set_transient( gfdrp_report_key( 'action_result' ), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'activation_failed' ) ) );
		exit;
	}

	update_option( GFDRP_APPLIED_POLICY_OPTION, $current_policy );
	update_option( GFDRP_EXCLUDED_FORMS_OPTION, $excluded_ids );
	update_option( GFDRP_STATUS_OPTION, 'active' );
	delete_transient( gfdrp_report_key( 'policy_report' ) );
	set_transient( gfdrp_report_key( 'action_result' ), $result, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'activated' ) ) );
	exit;
}

/**
 * Disable ongoing enforcement without changing any form settings.
 */
function gfdrp_handle_deactivate_policy() {
	gfdrp_verify_admin_action( 'gfdrp_deactivate_policy' );
	update_option( GFDRP_STATUS_OPTION, 'inactive' );
	delete_transient( gfdrp_report_key( 'policy_report' ) );
	wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'deactivated' ) ) );
	exit;
}

/**
 * Scan active forms for detectable site usage.
 */
function gfdrp_handle_test_unused_forms() {
	gfdrp_verify_admin_action( 'gfdrp_test_unused_forms' );
	$report = gfdrp_build_unused_forms_report();
	set_transient( gfdrp_report_key( 'unused_report' ), $report, 15 * MINUTE_IN_SECONDS );
	wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'unused_tested' ) ) );
	exit;
}

/**
 * Rescan and deactivate active forms without a detected reference.
 */
function gfdrp_handle_deactivate_unused_forms() {
	gfdrp_verify_admin_action( 'gfdrp_deactivate_unused_forms', 'gfdrp_unused_nonce' );

	$report = get_transient( gfdrp_report_key( 'unused_report' ) );

	if ( ! gfdrp_is_post_request() || ! is_array( $report ) || ! empty( $report['error'] ) || ! isset( $report['forms'] ) || ! is_array( $report['forms'] ) ) {
		wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => 'unused_test_required' ) ) );
		exit;
	}

	$requested_ids = isset( $_POST['gfdrp_unused_form_ids'] ) && is_array( $_POST['gfdrp_unused_form_ids'] )
		? array_map( 'absint', wp_unslash( $_POST['gfdrp_unused_form_ids'] ) )
		: array();
	$candidate_ids = array();

	foreach ( $report['forms'] as $form ) {
		if ( empty( $form['in_use'] ) ) {
			$candidate_ids[] = absint( $form['id'] ?? 0 );
		}
	}

	$selected_ids = array_values( array_intersect( array_unique( $requested_ids ), $candidate_ids ) );
	$result       = gfdrp_deactivate_unused_forms( $selected_ids );
	delete_transient( gfdrp_report_key( 'unused_report' ) );
	set_transient( gfdrp_report_key( 'action_result' ), $result, 5 * MINUTE_IN_SECONDS );
	$outcome = ! empty( $result['scan_error'] ) ? 'unused_deactivation_failed' : 'unused_deactivated';
	wp_safe_redirect( gfdrp_settings_url( array( 'gfdrp_result' => $outcome ) ) );
	exit;
}

add_action( 'admin_post_gfdrp_test_policy', 'gfdrp_handle_test_policy' );
add_action( 'admin_post_gfdrp_activate_policy', 'gfdrp_handle_activate_policy' );
add_action( 'admin_post_gfdrp_deactivate_policy', 'gfdrp_handle_deactivate_policy' );
add_action( 'admin_post_gfdrp_test_unused_forms', 'gfdrp_handle_test_unused_forms' );
add_action( 'admin_post_gfdrp_deactivate_unused_forms', 'gfdrp_handle_deactivate_unused_forms' );

/**
 * Render an admin result notice for the current workflow action.
 *
 * @return string
 */
function gfdrp_get_action_notice_html() {
	$result = isset( $_GET['gfdrp_result'] ) ? sanitize_key( wp_unslash( $_GET['gfdrp_result'] ) ) : '';
	$data   = get_transient( gfdrp_report_key( 'action_result' ) );
	$map    = array(
		'policy_tested'       => array( 'info', __( 'The read-only policy test is complete. Review the report before activation.', 'gravity-forms-data-retention-policy' ) ),
		'test_required'       => array( 'warning', __( 'Run the policy test again before activating these settings.', 'gravity-forms-data-retention-policy' ) ),
		'activation_failed'   => array( 'error', __( 'The policy could not be activated because one or more forms could not be updated.', 'gravity-forms-data-retention-policy' ) ),
		'activated'           => array( 'success', sprintf( __( 'The policy is active. %d forms were updated.', 'gravity-forms-data-retention-policy' ), (int) ( $data['updated'] ?? 0 ) ) ),
		'deactivated'         => array( 'success', __( 'Policy enforcement is inactive. Existing form settings were not reverted.', 'gravity-forms-data-retention-policy' ) ),
		'unused_tested'       => array( 'info', __( 'The unused-form scan is complete. Review the candidates before deactivation.', 'gravity-forms-data-retention-policy' ) ),
		'unused_test_required'       => array( 'warning', __( 'Run the unused-form scan again before deactivating forms.', 'gravity-forms-data-retention-policy' ) ),
		'unused_deactivation_failed' => array( 'error', __( 'The confirmation scan failed. No forms were deactivated.', 'gravity-forms-data-retention-policy' ) ),
		'unused_deactivated'         => array( 'success', sprintf( __( '%1$d forms were deactivated; %2$d could not be updated.', 'gravity-forms-data-retention-policy' ), (int) ( $data['deactivated'] ?? 0 ), (int) ( $data['failed'] ?? 0 ) ) ),
	);

	if ( ! isset( $map[ $result ] ) ) {
		return '';
	}

	return sprintf(
		'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
		esc_attr( $map[ $result ][0] ),
		esc_html( $map[ $result ][1] )
	);
}

/**
 * Render the read-only policy impact report.
 *
 * @param array $report Policy report.
 * @return string
 */
function gfdrp_get_policy_report_html( $report ) {
	if ( ! is_array( $report ) ) {
		return '';
	}

	$html  = '<h4>' . esc_html__( 'Policy impact report', 'gravity-forms-data-retention-policy' ) . '</h4>';
	$html .= '<p>' . sprintf(
		esc_html__( '%1$d forms will change. %2$d existing entries are already old enough to be affected at the next Gravity Forms daily cron run; %3$d would be permanently deleted. %4$d affected entries contain a value in a File Upload or Post Image field.', 'gravity-forms-data-retention-policy' ),
		(int) $report['forms_changed'],
		(int) $report['entries_affected'],
		(int) $report['entries_deleted'],
		(int) $report['file_entries']
	) . '</p>';
	$html .= '<p class="description">' . esc_html__( 'Entry totals do not include Save and Continue draft submissions. Gravity Forms applies the same retention period to those drafts during its cleanup task.', 'gravity-forms-data-retention-policy' ) . '</p>';
	$html .= '<p class="description">' . esc_html__( 'All affected forms are selected by default. Uncheck a form to leave it unchanged and exclude it from ongoing policy enforcement.', 'gravity-forms-data-retention-policy' ) . '</p>';

	if ( ! empty( $report['errors'] ) ) {
		$html .= '<div class="notice notice-error inline"><p>' . esc_html__( 'One or more entry counts could not be completed. Activation is disabled until a complete test succeeds.', 'gravity-forms-data-retention-policy' ) . '</p></div>';
		return $html;
	}

	$html .= wp_nonce_field( 'gfdrp_activate_policy', 'gfdrp_activate_nonce', false, false );

	if ( empty( $report['forms'] ) ) {
		$html .= '<p>' . esc_html__( 'No forms require a policy change.', 'gravity-forms-data-retention-policy' ) . '</p>';
	} else {
		$html .= '<table class="widefat striped"><thead><tr><th class="check-column">' . esc_html__( 'Apply defaults', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Form', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Current', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Proposed', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Entries affected', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'With files', 'gravity-forms-data-retention-policy' ) . '</th></tr></thead><tbody>';

		foreach ( $report['forms'] as $form ) {
			$form_id = absint( $form['id'] ?? 0 );
			$html   .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="gfdrp_policy_form_ids[]" value="' . esc_attr( (string) $form_id ) . '" checked aria-label="' . esc_attr( sprintf( __( 'Apply defaults to %s', 'gravity-forms-data-retention-policy' ), (string) $form['title'] ) ) . '"></th><td>' . esc_html( '#' . $form_id . ' ' . $form['title'] ) . '</td><td>' . esc_html( gfdrp_format_policy( $form['from'] ) ) . '</td><td>' . esc_html( gfdrp_format_policy( $form['to'] ) ) . '</td><td>' . esc_html( (string) $form['entries'] ) . '</td><td>' . esc_html( (string) $form['file_entries'] ) . '</td></tr>';
		}

		$html .= '</tbody></table>';
	}

	$html .= '<p><button type="submit" name="action" value="gfdrp_activate_policy" class="button button-primary" formmethod="post" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formnovalidate>' . esc_html__( 'Activate Selected Policy Changes', 'gravity-forms-data-retention-policy' ) . '</button></p>';

	return $html;
}

/**
 * Render the unused active forms report.
 *
 * @param array $report Usage report.
 * @return string
 */
function gfdrp_get_unused_forms_report_html( $report ) {
	if ( ! is_array( $report ) ) {
		return '';
	}

	$html = '<h4>' . esc_html__( 'Active forms and detected usage', 'gravity-forms-data-retention-policy' ) . '</h4>';

	if ( ! empty( $report['error'] ) ) {
		return $html . '<div class="notice notice-error inline"><p>' . esc_html__( 'The active-form scan could not be completed.', 'gravity-forms-data-retention-policy' ) . '</p></div>';
	}

	if ( empty( $report['forms'] ) ) {
		return $html . '<p>' . esc_html__( 'There are no active forms.', 'gravity-forms-data-retention-policy' ) . '</p>';
	}

	$candidate_count = 0;
	$html           .= wp_nonce_field( 'gfdrp_deactivate_unused_forms', 'gfdrp_unused_nonce', false, false );
	$html           .= '<table class="widefat striped"><thead><tr><th class="check-column">' . esc_html__( 'Deactivate', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Active form', 'gravity-forms-data-retention-policy' ) . '</th><th>' . esc_html__( 'Detected usage', 'gravity-forms-data-retention-policy' ) . '</th></tr></thead><tbody>';

	foreach ( $report['forms'] as $form ) {
		$form_id = absint( $form['id'] ?? 0 );
		$in_use  = ! empty( $form['in_use'] );
		$uses    = array();

		foreach ( is_array( $form['uses'] ?? null ) ? $form['uses'] : array() as $use ) {
			$label  = esc_html( (string) ( $use['label'] ?? '' ) );
			$uses[] = ! empty( $use['url'] )
				? '<a href="' . esc_url( $use['url'] ) . '">' . $label . '</a>'
				: $label;
		}

		if ( ! $in_use ) {
			++$candidate_count;
		}

		$checkbox = '<input type="checkbox" name="gfdrp_unused_form_ids[]" value="' . esc_attr( (string) $form_id ) . '" aria-label="' . esc_attr( sprintf( __( 'Deactivate %s', 'gravity-forms-data-retention-policy' ), (string) $form['title'] ) ) . '"' . ( $in_use ? ' disabled' : ' checked' ) . '>';
		$usage    = $in_use ? implode( '<br>', array_filter( $uses ) ) : '<strong>' . esc_html__( 'No usage detected', 'gravity-forms-data-retention-policy' ) . '</strong>';
		$html    .= '<tr><th scope="row" class="check-column">' . $checkbox . '</th><td>' . esc_html( '#' . $form_id . ' ' . $form['title'] ) . '</td><td>' . $usage . '</td></tr>';
	}

	$html .= '</tbody></table>';

	if ( $candidate_count ) {
		$html .= '<p><button type="submit" name="action" value="gfdrp_deactivate_unused_forms" class="button" formmethod="post" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formnovalidate>' . esc_html__( 'Deactivate Selected Unused Forms', 'gravity-forms-data-retention-policy' ) . '</button></p>';
	} else {
		$html .= '<p>' . esc_html__( 'Every active form has a detected usage location.', 'gravity-forms-data-retention-policy' ) . '</p>';
	}

	return $html;
}

/**
 * Build the complete settings-page workflow controls.
 *
 * @return string
 */
function gfdrp_get_policy_controls_html() {
	$is_active      = gfdrp_is_policy_active();
	$status_label   = $is_active
		? __( 'Active', 'gravity-forms-data-retention-policy' )
		: __( 'Inactive', 'gravity-forms-data-retention-policy' );
	$policy_report  = get_transient( gfdrp_report_key( 'policy_report' ) );
	$unused_report  = get_transient( gfdrp_report_key( 'unused_report' ) );
	$current        = gfdrp_get_site_policy();
	$report_matches = is_array( $policy_report ) && ( $policy_report['target_policy'] ?? null ) === $current;
	$html           = gfdrp_get_action_notice_html();

	$html .= '<p><strong>' . esc_html__( 'Status:', 'gravity-forms-data-retention-policy' ) . '</strong> ' . esc_html( $status_label ) . '</p>';

	if ( $is_active ) {
		$html .= '<p><strong>' . esc_html__( 'Applied policy:', 'gravity-forms-data-retention-policy' ) . '</strong> ' . esc_html( gfdrp_format_policy( gfdrp_get_applied_policy() ) ) . '</p>';
		$excluded_ids = gfdrp_get_excluded_form_ids();

		if ( $excluded_ids ) {
			$html .= '<p><strong>' . esc_html__( 'Policy exceptions:', 'gravity-forms-data-retention-policy' ) . '</strong> ' . esc_html( sprintf( __( 'Forms %s remain unchanged and are excluded from ongoing enforcement.', 'gravity-forms-data-retention-policy' ), implode( ', ', array_map( 'strval', $excluded_ids ) ) ) ) . '</p>';
		}
	}
	$html .= '<p>' . esc_html__( 'Click Save Settings first. Saving settings does not change any forms. Then run Test Policy, review the impact, and activate the tested policy.', 'gravity-forms-data-retention-policy' ) . '</p>';
	$html .= '<p><a class="button" href="' . esc_url( gfdrp_admin_action_url( 'gfdrp_test_policy' ) ) . '">' . esc_html__( 'Test Policy', 'gravity-forms-data-retention-policy' ) . '</a> ';

	if ( $is_active ) {
		$html .= '<a class="button" href="' . esc_url( gfdrp_admin_action_url( 'gfdrp_deactivate_policy' ) ) . '">' . esc_html__( 'Deactivate Policy', 'gravity-forms-data-retention-policy' ) . '</a>';
	}

	$html .= '</p>';

	if ( $report_matches ) {
		$html .= gfdrp_get_policy_report_html( $policy_report );
	}

	$html .= '<hr><h4>' . esc_html__( 'Unused active forms', 'gravity-forms-data-retention-policy' ) . '</h4>';
	$html .= '<p>' . esc_html__( 'The scan checks posts, pages, custom post content and metadata, Gravity Forms blocks and shortcodes, common widgets, theme settings, and active theme PHP files. It cannot prove that a form is not loaded dynamically by a plugin, API call, or external application.', 'gravity-forms-data-retention-policy' ) . '</p>';
	$html .= '<p><a class="button" href="' . esc_url( gfdrp_admin_action_url( 'gfdrp_test_unused_forms' ) ) . '">' . esc_html__( 'Scan Active Forms', 'gravity-forms-data-retention-policy' ) . '</a></p>';
	$html .= gfdrp_get_unused_forms_report_html( $unused_report );

	return $html;
}
