<?php
/**
 * Gravity Forms settings integration.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_addon_framework();

/**
 * Adds the site-level Retention Policy tab under Forms > Settings.
 */
final class GFDRP_Addon extends GFAddOn {
	/** @var string */
	protected $_version = GFDRP_VERSION;

	/** @var string */
	protected $_min_gravityforms_version = '2.5.8';

	/** @var string */
	protected $_slug = 'gfdrp';

	/** @var string */
	protected $_path = 'gravity-forms-data-retention-policy/gravity-forms-data-retention-policy.php';

	/** @var string */
	protected $_full_path = GFDRP_PLUGIN_FILE;

	/** @var string */
	protected $_title = 'Gravity Forms Data Retention Policy';

	/** @var string */
	protected $_short_title = 'Retention Policy';

	/** @var self|null */
	private static $_instance = null;

	/**
	 * Return the singleton add-on instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Configure site-local plugin settings.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Retention Policy', 'gravity-forms-data-retention-policy' ),
				'description' => esc_html__( 'This is the maximum retention permitted for every form on this site. Existing and future forms may use a stricter rule, but not a looser one.', 'gravity-forms-data-retention-policy' ),
				'fields'      => array(
					array(
						'name'          => 'policy',
						'label'         => esc_html__( 'Entry retention action', 'gravity-forms-data-retention-policy' ),
						'type'          => 'radio',
						'default_value' => 'delete',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Retain entries indefinitely', 'gravity-forms-data-retention-policy' ),
								'value' => 'retain',
							),
							array(
								'label' => esc_html__( 'Trash entries automatically', 'gravity-forms-data-retention-policy' ),
								'value' => 'trash',
							),
							array(
								'label' => esc_html__( 'Delete entries permanently automatically', 'gravity-forms-data-retention-policy' ),
								'value' => 'delete',
							),
						),
					),
					array(
						'name'          => 'retain_entries_days',
						'label'         => esc_html__( 'Number of days to retain entries before trashing/deleting', 'gravity-forms-data-retention-policy' ),
						'type'          => 'text',
						'input_type'    => 'number',
						'class'         => 'small',
						'default_value' => 28,
						'required'      => true,
						'attributes'    => array(
							'min'  => 1,
							'step' => 1,
						),
						'description'   => esc_html__( 'Gravity Forms applies automated retention during its daily scheduled task. This value is ignored when entries are retained indefinitely.', 'gravity-forms-data-retention-policy' ),
					),
					array(
						'name'  => 'policy_controls',
						'label' => esc_html__( 'Policy', 'gravity-forms-data-retention-policy' ),
						'type'  => 'policy_controls',
					),
				),
			),
		);
	}

	/**
	 * Render the policy workflow and unused-form controls.
	 *
	 * @param array $field Field configuration.
	 * @param bool  $echo  Whether to echo the generated markup.
	 * @return string
	 */
	public function settings_policy_controls( $field, $echo = true ) {
		unset( $field );
		$html = gfdrp_get_policy_controls_html();

		if ( $echo ) {
			$allowed_html = wp_kses_allowed_html( 'post' );
			$allowed_html['input'] = array(
				'aria-label' => true,
				'checked'    => true,
				'disabled'   => true,
				'name'       => true,
				'type'       => true,
				'value'      => true,
			);
			$allowed_html['button'] = array(
				'class'          => true,
				'formaction'     => true,
				'formmethod'     => true,
				'formnovalidate' => true,
				'name'           => true,
				'type'           => true,
				'value'          => true,
			);

			echo wp_kses( $html, $allowed_html );
		}

		return $html;
	}

	/**
	 * Remove the site setup marker during a Gravity Forms uninstall action.
	 */
	public function uninstall() {
		delete_option( GFDRP_SITE_VERSION_OPTION );
		delete_option( GFDRP_STATUS_OPTION );
		delete_option( GFDRP_APPLIED_POLICY_OPTION );
		delete_option( GFDRP_EXCLUDED_FORMS_OPTION );
	}
}

/**
 * Return the registered add-on instance.
 *
 * @return GFDRP_Addon
 */
function gfdrp_addon() {
	return GFDRP_Addon::get_instance();
}
