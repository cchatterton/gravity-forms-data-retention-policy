<?php
/**
 * Lightweight policy and multisite tests without a WordPress database.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'GFDRP_VERSION', '1.2.0' );
define( 'GFDRP_PLUGIN_FILE', dirname( __DIR__ ) . '/gravity-forms-data-retention-policy/gravity-forms-data-retention-policy.php' );
define( 'GFDRP_PLUGIN_DIR', dirname( __DIR__ ) . '/gravity-forms-data-retention-policy/' );
define( 'GFDRP_SETTINGS_OPTION', 'gravityformsaddon_gfdrp_settings' );
define( 'GFDRP_SITE_VERSION_OPTION', 'gfdrp_site_version' );
define( 'GFDRP_STATUS_OPTION', 'gfdrp_policy_status' );
define( 'GFDRP_APPLIED_POLICY_OPTION', 'gfdrp_applied_policy' );
define( 'GFDRP_EXCLUDED_FORMS_OPTION', 'gfdrp_excluded_form_ids' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'MB_IN_BYTES', 1048576 );

$gfdrp_test_blog_id = 1;
$gfdrp_test_options = array( 1 => array(), 2 => array() );
$gfdrp_test_forms   = array(
	1 => array(
		array(
			'id'           => 1,
			'personalData' => array(
				'retention' => array(
					'policy'              => 'retain',
					'retain_entries_days' => 0,
				),
			),
		),
	),
	2 => array(
		array(
			'id'           => 2,
			'personalData' => array(
				'retention' => array(
					'policy'              => 'trash',
					'retain_entries_days' => 90,
				),
			),
		),
	),
);
$gfdrp_test_entries = array();

function __( $text, $domain = '' ) {
	unset( $domain );
	return $text;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_option( $name, $default = false ) {
	global $gfdrp_test_blog_id, $gfdrp_test_options;
	return $gfdrp_test_options[ $gfdrp_test_blog_id ][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	global $gfdrp_test_blog_id, $gfdrp_test_options;
	$gfdrp_test_options[ $gfdrp_test_blog_id ][ $name ] = $value;
	return true;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function is_multisite() {
	return true;
}

function get_sites( $arguments ) {
	if ( 'ids' !== $arguments['fields'] || 0 !== $arguments['number'] ) {
		throw new RuntimeException( 'Unexpected site query.' );
	}
	return array( 1, 2 );
}

function switch_to_blog( $blog_id ) {
	global $gfdrp_test_blog_id;
	$gfdrp_test_blog_id = $blog_id;
}

function restore_current_blog() {
	global $gfdrp_test_blog_id;
	$gfdrp_test_blog_id = 1;
}

function get_post_types( $arguments = array(), $output = 'names' ) {
	unset( $arguments, $output );
	return array( 'post' => 'post' );
}

function get_posts( $arguments = array() ) {
	unset( $arguments );
	return array( 101 );
}

function get_post( $post_id ) {
	return (object) array(
		'ID'           => $post_id,
		'post_status'  => 'publish',
		'post_content' => '[gravityform id="3" title="false"]',
	);
}

function get_post_meta( $post_id ) {
	unset( $post_id );
	return array();
}

function parse_blocks( $content ) {
	unset( $content );
	return array();
}

function get_theme_mods() {
	return array();
}

function get_stylesheet_directory() {
	return '/gfdrp-test-theme-does-not-exist';
}

function get_template_directory() {
	return '/gfdrp-test-theme-does-not-exist';
}

class WP_Error {}

class GFAPI {
	public static function get_forms( $active = true, $trash = false ) {
		global $gfdrp_test_blog_id, $gfdrp_test_forms;
		$forms = $gfdrp_test_forms[ $gfdrp_test_blog_id ];

		if ( null === $active && null === $trash ) {
			return $forms;
		}

		return array_values(
			array_filter(
				$forms,
				static function ( $form ) use ( $active, $trash ) {
					return (bool) ( $form['is_active'] ?? true ) === (bool) $active
						&& (bool) ( $form['is_trash'] ?? false ) === (bool) $trash;
				}
			)
		);
	}

	public static function update_form( $form ) {
		global $gfdrp_test_blog_id, $gfdrp_test_forms;
		foreach ( $gfdrp_test_forms[ $gfdrp_test_blog_id ] as $index => $candidate ) {
			if ( $candidate['id'] === $form['id'] ) {
				$gfdrp_test_forms[ $gfdrp_test_blog_id ][ $index ] = $form;
				return $form['id'];
			}
		}
		return new WP_Error();
	}

	public static function get_entries( $form_id, $criteria = array(), $sorting = null, $paging = null, &$total_count = null ) {
		global $gfdrp_test_entries;
		unset( $sorting );
		$matches = array_values(
			array_filter(
				$gfdrp_test_entries,
				static function ( $entry ) use ( $form_id, $criteria ) {
					return (int) $entry['form_id'] === (int) $form_id
						&& $entry['status'] === $criteria['status']
						&& $entry['date_created'] <= $criteria['end_date'];
				}
			)
		);
		$total_count = count( $matches );
		$offset      = (int) ( $paging['offset'] ?? 0 );
		$page_size   = (int) ( $paging['page_size'] ?? 20 );
		return array_slice( $matches, $offset, $page_size );
	}

	public static function update_form_property( $form_id, $property, $value ) {
		global $gfdrp_test_blog_id, $gfdrp_test_forms;
		foreach ( $gfdrp_test_forms[ $gfdrp_test_blog_id ] as $index => $form ) {
			if ( (int) $form['id'] === (int) $form_id ) {
				$gfdrp_test_forms[ $gfdrp_test_blog_id ][ $index ][ $property ] = $value;
				return true;
			}
		}
		return false;
	}
}

require_once GFDRP_PLUGIN_DIR . 'functions/retention.php';
require_once GFDRP_PLUGIN_DIR . 'functions/setup.php';
require_once GFDRP_PLUGIN_DIR . 'functions/audit.php';
require_once GFDRP_PLUGIN_DIR . 'functions/form-usage.php';

function gfdrp_test_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

gfdrp_test_same(
	array( 'policy' => 'delete', 'retain_entries_days' => 1 ),
	gfdrp_sanitize_settings( array( 'policy' => 'invalid', 'retain_entries_days' => 0 ) ),
	'Invalid settings must fall back to permanent deletion and at least one day.'
);

$retain_form = array(
	'personalData' => array(
		'retention' => array( 'policy' => 'retain', 'retain_entries_days' => 0 ),
	),
);
$delete_28 = gfdrp_apply_site_policy_to_form( $retain_form, array( 'policy' => 'delete', 'retain_entries_days' => 28 ) );
gfdrp_test_same( 'delete', $delete_28['personalData']['retention']['policy'], 'Retain must be upgraded to delete.' );
gfdrp_test_same( 28, $delete_28['personalData']['retention']['retain_entries_days'], 'The site day limit must be applied.' );

$strict_form = array(
	'personalData' => array(
		'retention' => array( 'policy' => 'delete', 'retain_entries_days' => 7 ),
	),
);
gfdrp_test_same(
	$strict_form,
	gfdrp_apply_site_policy_to_form( $strict_form, array( 'policy' => 'trash', 'retain_entries_days' => 28 ) ),
	'A stricter form policy must remain unchanged.'
);

$late_delete = array(
	'personalData' => array(
		'retention' => array( 'policy' => 'delete', 'retain_entries_days' => 90 ),
	),
);
$clamped = gfdrp_apply_site_policy_to_form( $late_delete, array( 'policy' => 'trash', 'retain_entries_days' => 28 ) );
gfdrp_test_same( 'delete', $clamped['personalData']['retention']['policy'], 'A stronger action must be preserved.' );
gfdrp_test_same( 28, $clamped['personalData']['retention']['retain_entries_days'], 'A stronger action must still obey the day ceiling.' );

gfdrp_test_same(
	'unchanged',
	gfdrp_enforce_form_update( 'unchanged', 4, 'notifications' ),
	'Non-display form metadata must not be changed.'
);

gfdrp_test_same(
	$retain_form,
	gfdrp_enforce_form_update( $retain_form, 4, 'display_meta' ),
	'Inactive policy enforcement must not change form metadata.'
);

gfdrp_activate( true );

foreach ( array( 1, 2 ) as $site_id ) {
	gfdrp_test_same(
		array( 'policy' => 'delete', 'retain_entries_days' => 28 ),
		$gfdrp_test_options[ $site_id ][ GFDRP_SETTINGS_OPTION ],
		'Every network site must receive the default policy.'
	);
	gfdrp_test_same(
		'1.2.0',
		$gfdrp_test_options[ $site_id ][ GFDRP_SITE_VERSION_OPTION ],
		'Every network site must be marked initialized.'
	);
	gfdrp_test_same(
		'inactive',
		$gfdrp_test_options[ $site_id ][ GFDRP_STATUS_OPTION ],
		'Every network site policy must start inactive.'
	);
	gfdrp_test_same( array(), $gfdrp_test_options[ $site_id ][ GFDRP_EXCLUDED_FORMS_OPTION ], 'Every network site must start without form exclusions.' );
}

gfdrp_test_same( 'retain', $gfdrp_test_forms[1][0]['personalData']['retention']['policy'], 'Plugin activation must not change forms.' );
gfdrp_test_same( 'trash', $gfdrp_test_forms[2][0]['personalData']['retention']['policy'], 'Network activation must not change forms on subsites.' );

$legacy_policy         = array( 'policy' => 'trash', 'retain_entries_days' => 45 );
$gfdrp_test_options[3] = array(
	GFDRP_SETTINGS_OPTION     => $legacy_policy,
	GFDRP_SITE_VERSION_OPTION => '1.0.1',
);
switch_to_blog( 3 );
gfdrp_initialize_current_site();
gfdrp_test_same( $legacy_policy, get_option( GFDRP_APPLIED_POLICY_OPTION ), 'An upgrade must remember the policy enforced by the previous release.' );
gfdrp_test_same( 'inactive', get_option( GFDRP_STATUS_OPTION ), 'An upgraded site must enter the explicit workflow inactive.' );
restore_current_blog();

$gfdrp_test_options[1][ GFDRP_STATUS_OPTION ]         = 'active';
$gfdrp_test_options[1][ GFDRP_APPLIED_POLICY_OPTION ] = array( 'policy' => 'delete', 'retain_entries_days' => 28 );
$enforced_while_active = gfdrp_enforce_form_update( $retain_form, 1, 'display_meta' );
gfdrp_test_same( 'delete', $enforced_while_active['personalData']['retention']['policy'], 'Active enforcement must use the explicitly applied policy.' );
$gfdrp_test_options[1][ GFDRP_EXCLUDED_FORMS_OPTION ] = array( 4 );
gfdrp_test_same( $retain_form, gfdrp_enforce_form_update( $retain_form, 4, 'display_meta' ), 'An unchecked form must remain excluded from ongoing enforcement.' );
$gfdrp_test_options[1][ GFDRP_EXCLUDED_FORMS_OPTION ] = array();
gfdrp_settings_option_updated(
	GFDRP_SETTINGS_OPTION,
	array( 'policy' => 'delete', 'retain_entries_days' => 28 ),
	array( 'policy' => 'trash', 'retain_entries_days' => 10 )
);
gfdrp_test_same( 'inactive', $gfdrp_test_options[1][ GFDRP_STATUS_OPTION ], 'Saving changed settings must require a new test and activation.' );
gfdrp_test_same( 'retain', $gfdrp_test_forms[1][0]['personalData']['retention']['policy'], 'Saving changed settings must not update forms.' );

$gfdrp_test_options[1][ GFDRP_SETTINGS_OPTION ] = array( 'policy' => 'trash', 'retain_entries_days' => 10 );
$gfdrp_test_forms[1][0] = $retain_form + array( 'id' => 1 );
gfdrp_synchronize_existing_forms();
gfdrp_test_same( 'trash', $gfdrp_test_forms[1][0]['personalData']['retention']['policy'], 'Site one must use its local policy.' );
gfdrp_test_same( 10, $gfdrp_test_forms[1][0]['personalData']['retention']['retain_entries_days'], 'Site one must use its local day ceiling.' );
gfdrp_test_same( 'trash', $gfdrp_test_forms[2][0]['personalData']['retention']['policy'], 'Site two must remain independent.' );

$old_policy = array( 'policy' => 'delete', 'retain_entries_days' => 28 );
$new_policy = array( 'policy' => 'trash', 'retain_entries_days' => 60 );
$gfdrp_test_forms[1] = array(
	array(
		'id'           => 1,
		'personalData' => array( 'retention' => $old_policy ),
	),
	array(
		'id'           => 3,
		'personalData' => array(
			'retention' => array( 'policy' => 'delete', 'retain_entries_days' => 7 ),
		),
	),
	array(
		'id'           => 4,
		'personalData' => array(
			'retention' => array( 'policy' => 'retain', 'retain_entries_days' => 0 ),
		),
	),
);

$migration_result = gfdrp_synchronize_existing_forms( $old_policy, $new_policy );
gfdrp_test_same( 3, $migration_result['checked'], 'A policy change must check every form.' );
gfdrp_test_same( 2, $migration_result['updated'], 'Inherited and newly non-compliant forms must be updated.' );
gfdrp_test_same( $new_policy, $gfdrp_test_forms[1][0]['personalData']['retention'], 'An exact old-policy match must follow the new policy.' );
gfdrp_test_same(
	array( 'policy' => 'delete', 'retain_entries_days' => 7 ),
	$gfdrp_test_forms[1][1]['personalData']['retention'],
	'A custom stricter policy must not be loosened.'
);
gfdrp_test_same( $new_policy, $gfdrp_test_forms[1][2]['personalData']['retention'], 'A looser custom form must meet the new ceiling.' );

$gfdrp_test_forms[1][0]['personalData']['retention'] = $old_policy;
$gfdrp_test_forms[1][2]['personalData']['retention'] = array( 'policy' => 'retain', 'retain_entries_days' => 0 );
$selected_result = gfdrp_synchronize_existing_forms( $old_policy, $new_policy, array( 1 ) );
gfdrp_test_same( 1, $selected_result['checked'], 'Activation must only inspect forms selected from the test.' );
gfdrp_test_same( $new_policy, $gfdrp_test_forms[1][0]['personalData']['retention'], 'A selected form must receive the tested policy.' );
gfdrp_test_same( 'retain', $gfdrp_test_forms[1][2]['personalData']['retention']['policy'], 'An unchecked form must remain unchanged.' );

$retain_policy = array( 'policy' => 'retain', 'retain_entries_days' => 60 );
gfdrp_synchronize_existing_forms( $new_policy, $retain_policy );
gfdrp_test_same( $retain_policy, $gfdrp_test_forms[1][0]['personalData']['retention'], 'Inherited forms must also follow a loosened retain policy.' );
gfdrp_test_same(
	array( 'policy' => 'delete', 'retain_entries_days' => 7 ),
	$gfdrp_test_forms[1][1]['personalData']['retention'],
	'A custom stricter policy must remain unchanged when the site policy becomes retain.'
);

$extracted_ids = gfdrp_extract_form_ids(
	array(
		'[gravityform id="12" title="false"]',
		'<!-- wp:gravityforms/form {"formId":"14"} /-->',
		'gravity_form( 16, false );',
		'a:1:{s:7:"form_id";i:18;}',
		array( 'form_id' => 22 ),
	)
);
sort( $extracted_ids );
gfdrp_test_same( array( 12, 14, 16, 18, 22 ), $extracted_ids, 'Supported embed formats must identify their form IDs.' );

$audit_form = array(
	'id'     => 20,
	'fields' => array(
		array( 'id' => 5, 'type' => 'fileupload' ),
		array( 'id' => 6, 'type' => 'text' ),
	),
);
$gfdrp_test_entries = array(
	array( 'form_id' => 20, 'status' => 'active', 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), '5' => 'upload.pdf' ),
	array( 'form_id' => 20, 'status' => 'spam', 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 35 * DAY_IN_SECONDS ), '5' => '' ),
	array( 'form_id' => 20, 'status' => 'active', 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), '5' => 'new.pdf' ),
);
$entry_audit = gfdrp_audit_form_entries( $audit_form, array( 'policy' => 'delete', 'retain_entries_days' => 28 ) );
gfdrp_test_same( 2, $entry_audit['entries'], 'The audit must count entries already beyond the retention period.' );
gfdrp_test_same( 1, $entry_audit['file_entries'], 'The audit must identify affected entries with file uploads.' );

$gfdrp_test_forms[1] = array(
	array( 'id' => 3, 'title' => 'Embedded', 'is_active' => true, 'is_trash' => false ),
	array( 'id' => 4, 'title' => 'Not Referenced', 'is_active' => true, 'is_trash' => false ),
);
$unused_report = gfdrp_build_unused_forms_report();
gfdrp_test_same( 2, count( $unused_report['forms'] ), 'The usage scan must report every active form.' );
gfdrp_test_same( true, $unused_report['forms'][0]['in_use'], 'A referenced form must be marked in use.' );
gfdrp_test_same( 'Content: (untitled) (#101)', $unused_report['forms'][0]['uses'][0]['label'], 'The usage scan must identify where a form is referenced.' );
gfdrp_test_same( false, $unused_report['forms'][1]['in_use'], 'An unreferenced active form must be preselected as unused.' );
$deactivation_result = gfdrp_deactivate_unused_forms( array( 3, 4 ) );
gfdrp_test_same( 1, $deactivation_result['deactivated'], 'The unused-form action must deactivate only the reported form.' );
gfdrp_test_same( true, $gfdrp_test_forms[1][0]['is_active'], 'A referenced form must remain active.' );
gfdrp_test_same( false, $gfdrp_test_forms[1][1]['is_active'], 'An unreferenced form must be deactivated.' );

echo "Retention and multisite tests passed.\n";
