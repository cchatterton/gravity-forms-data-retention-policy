<?php
/**
 * Read-only policy impact reporting.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return file-capable field IDs for a form.
 *
 * @param array $form Gravity Forms form object.
 * @return string[]
 */
function gfdrp_get_file_field_ids( $form ) {
	$field_ids = array();

	foreach ( isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array() as $field ) {
		$type = is_object( $field ) && method_exists( $field, 'get_input_type' )
			? $field->get_input_type()
			: ( is_object( $field ) ? ( $field->type ?? '' ) : ( $field['type'] ?? '' ) );
		$id   = is_object( $field ) ? ( $field->id ?? 0 ) : ( $field['id'] ?? 0 );

		if ( $id && in_array( $type, array( 'fileupload', 'post_image' ), true ) ) {
			$field_ids[] = (string) $id;
		}
	}

	return $field_ids;
}

/**
 * Count entries affected by an automated target policy and entries with files.
 *
 * @param array $form          Gravity Forms form object.
 * @param array $target_policy Target retention policy.
 * @return array{entries:int,file_entries:int,error:bool}
 */
function gfdrp_audit_form_entries( $form, $target_policy ) {
	$target_policy = gfdrp_sanitize_settings( $target_policy );
	$result        = array(
		'entries'      => 0,
		'file_entries' => 0,
		'error'        => false,
	);

	if ( 'retain' === $target_policy['policy'] || empty( $form['id'] ) || ! class_exists( 'GFAPI' ) ) {
		return $result;
	}

	$field_ids = gfdrp_get_file_field_ids( $form );
	$statuses  = 'delete' === $target_policy['policy']
		? array( 'active', 'spam', 'trash' )
		: array( 'active', 'spam' );
	$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( $target_policy['retain_entries_days'] * DAY_IN_SECONDS ) );

	foreach ( $statuses as $status ) {
		$offset = 0;

		do {
			$total_count = 0;
			$entries     = GFAPI::get_entries(
				(int) $form['id'],
				array(
					'status'   => $status,
					'end_date' => $cutoff,
				),
				null,
				array(
					'offset'    => $offset,
					'page_size' => 200,
				),
				$total_count
			);

			if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
				$result['error'] = true;
				break 2;
			}

			if ( 0 === $offset ) {
				$result['entries'] += (int) $total_count;
			}

			foreach ( $entries as $entry ) {
				foreach ( $field_ids as $field_id ) {
					if ( ! empty( $entry[ $field_id ] ) ) {
						++$result['file_entries'];
						break;
					}
				}
			}

			$offset += count( $entries );
		} while ( $offset < $total_count && ! empty( $entries ) );
	}

	return $result;
}

/**
 * Build a read-only report of form changes and entries due at the next cron run.
 *
 * @return array{forms:array,forms_changed:int,entries_affected:int,entries_deleted:int,file_entries:int,errors:int,generated_at:int,target_policy:array}
 */
function gfdrp_build_policy_report() {
	$report = array(
		'forms'            => array(),
		'forms_changed'    => 0,
		'entries_affected' => 0,
		'entries_deleted'  => 0,
		'file_entries'     => 0,
		'errors'           => 0,
		'generated_at'     => time(),
		'target_policy'    => gfdrp_get_site_policy(),
	);

	if ( ! class_exists( 'GFAPI' ) ) {
		$report['errors'] = 1;
		return $report;
	}

	$forms            = GFAPI::get_forms( null, null );
	$target_policy    = $report['target_policy'];
	$previous_setting = get_option( GFDRP_APPLIED_POLICY_OPTION, false );
	$previous_policy  = false === $previous_setting ? null : gfdrp_sanitize_settings( $previous_setting );

	foreach ( is_array( $forms ) ? $forms : array() as $form ) {
		if ( ! is_array( $form ) ) {
			continue;
		}

		$target_form = null !== $previous_policy && gfdrp_form_matches_policy( $form, $previous_policy )
			? gfdrp_set_form_retention_policy( $form, $target_policy )
			: gfdrp_apply_site_policy_to_form( $form, $target_policy );

		if ( $target_form === $form ) {
			continue;
		}

		$entry_impact = gfdrp_audit_form_entries( $form, $target_form['personalData']['retention'] );
		$old_policy   = isset( $form['personalData']['retention'] )
			? gfdrp_sanitize_settings( $form['personalData']['retention'] )
			: array( 'policy' => 'retain', 'retain_entries_days' => 1 );

		$report['forms'][] = array(
			'id'           => (int) ( $form['id'] ?? 0 ),
			'title'        => (string) ( $form['title'] ?? '' ),
			'from'         => $old_policy,
			'to'           => $target_form['personalData']['retention'],
			'entries'      => $entry_impact['entries'],
			'file_entries' => $entry_impact['file_entries'],
			'error'        => $entry_impact['error'],
		);
		++$report['forms_changed'];
		$report['entries_affected'] += $entry_impact['entries'];
		$report['file_entries']     += $entry_impact['file_entries'];
		$report['errors']           += $entry_impact['error'] ? 1 : 0;

		if ( 'delete' === $target_form['personalData']['retention']['policy'] ) {
			$report['entries_deleted'] += $entry_impact['entries'];
		}
	}

	return $report;
}

/**
 * Format a policy for an admin report.
 *
 * @param array $policy Retention policy.
 * @return string
 */
function gfdrp_format_policy( $policy ) {
	$policy = gfdrp_sanitize_settings( $policy );

	if ( 'retain' === $policy['policy'] ) {
		return __( 'Retain indefinitely', 'gravity-forms-data-retention-policy' );
	}

	$action = 'delete' === $policy['policy']
		? __( 'Permanently delete', 'gravity-forms-data-retention-policy' )
		: __( 'Move to trash', 'gravity-forms-data-retention-policy' );

	return sprintf(
		/* translators: 1: retention action, 2: number of days. */
		__( '%1$s after %2$d days', 'gravity-forms-data-retention-policy' ),
		$action,
		$policy['retain_entries_days']
	);
}
