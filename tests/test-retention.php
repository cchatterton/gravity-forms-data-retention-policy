<?php
/**
 * Lightweight policy and multisite tests without a WordPress database.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'GFDRP_VERSION', '1.0.0' );
define( 'GFDRP_PLUGIN_FILE', dirname( __DIR__ ) . '/gravity-forms-data-retention-policy/gravity-forms-data-retention-policy.php' );
define( 'GFDRP_PLUGIN_DIR', dirname( __DIR__ ) . '/gravity-forms-data-retention-policy/' );
define( 'GFDRP_SETTINGS_OPTION', 'gravityformsaddon_gfdrp_settings' );
define( 'GFDRP_SITE_VERSION_OPTION', 'gfdrp_site_version' );

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

class WP_Error {}

class GFAPI {
	public static function get_forms( $active = true, $trash = false ) {
		if ( null !== $active || null !== $trash ) {
			throw new RuntimeException( 'Synchronization must request active, inactive, and trashed forms.' );
		}
		global $gfdrp_test_blog_id, $gfdrp_test_forms;
		return $gfdrp_test_forms[ $gfdrp_test_blog_id ];
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
}

require_once GFDRP_PLUGIN_DIR . 'functions/retention.php';
require_once GFDRP_PLUGIN_DIR . 'functions/setup.php';

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

gfdrp_activate( true );

foreach ( array( 1, 2 ) as $site_id ) {
	gfdrp_test_same(
		array( 'policy' => 'delete', 'retain_entries_days' => 28 ),
		$gfdrp_test_options[ $site_id ][ GFDRP_SETTINGS_OPTION ],
		'Every network site must receive the default policy.'
	);
	gfdrp_test_same(
		'1.0.0',
		$gfdrp_test_options[ $site_id ][ GFDRP_SITE_VERSION_OPTION ],
		'Every network site must be marked initialized.'
	);
	gfdrp_test_same(
		'delete',
		$gfdrp_test_forms[ $site_id ][0]['personalData']['retention']['policy'],
		'Every network site form must meet the default action.'
	);
	gfdrp_test_same(
		28,
		$gfdrp_test_forms[ $site_id ][0]['personalData']['retention']['retain_entries_days'],
		'Every network site form must meet the default day ceiling.'
	);
}

$gfdrp_test_options[1][ GFDRP_SETTINGS_OPTION ] = array( 'policy' => 'trash', 'retain_entries_days' => 10 );
$gfdrp_test_forms[1][0] = $retain_form + array( 'id' => 1 );
gfdrp_synchronize_existing_forms();
gfdrp_test_same( 'trash', $gfdrp_test_forms[1][0]['personalData']['retention']['policy'], 'Site one must use its local policy.' );
gfdrp_test_same( 10, $gfdrp_test_forms[1][0]['personalData']['retention']['retain_entries_days'], 'Site one must use its local day ceiling.' );
gfdrp_test_same( 'delete', $gfdrp_test_forms[2][0]['personalData']['retention']['policy'], 'Site two must remain independent.' );

echo "Retention and multisite tests passed.\n";
