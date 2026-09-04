<?php
/**
 * Conservative detection of active forms embedded on the current site.
 *
 * @package GravityFormsDataRetentionPolicy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract referenced Gravity Forms IDs from strings, arrays, and objects.
 *
 * @param mixed $value Content or structured settings.
 * @return int[]
 */
function gfdrp_extract_form_ids( $value ) {
	$ids = array();

	if ( is_array( $value ) || is_object( $value ) ) {
		foreach ( (array) $value as $key => $item ) {
			if ( in_array( (string) $key, array( 'formId', 'form_id', 'form_id_attr' ), true ) && is_scalar( $item ) && absint( $item ) ) {
				$ids[] = absint( $item );
			}

			$ids = array_merge( $ids, gfdrp_extract_form_ids( $item ) );
		}

		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	if ( ! is_string( $value ) || '' === $value ) {
		return array();
	}

	$patterns = array(
		'/\[gravityform\b[^\]]*\bid\s*=\s*["\']?(\d+)/i',
		'/"(?:formId|form_id|form_id_attr)"\s*:\s*"?(\d+)/i',
		'/gravity_form\s*\(\s*(\d+)/i',
		'/s:\d+:"(?:formId|form_id|form_id_attr)";i:(\d+)/i',
		'/s:\d+:"(?:formId|form_id|form_id_attr)";s:\d+:"(\d+)"/i',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $value, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'absint', $matches[1] ) );
		}
	}

	return array_values( array_filter( array_unique( $ids ) ) );
}

/**
 * Recursively extract IDs from parsed Gravity Forms blocks.
 *
 * @param array[] $blocks Parsed blocks.
 * @return int[]
 */
function gfdrp_extract_form_ids_from_blocks( $blocks ) {
	$ids = array();

	foreach ( $blocks as $block ) {
		if ( 'gravityforms/form' === ( $block['blockName'] ?? '' ) ) {
			$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$form_id    = absint( $attributes['formId'] ?? ( $attributes['id'] ?? 0 ) );

			if ( $form_id ) {
				$ids[] = $form_id;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$ids = array_merge( $ids, gfdrp_extract_form_ids_from_blocks( $block['innerBlocks'] ) );
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Add a detected usage location for each form ID.
 *
 * @param array  $usage Form ID keyed usage map.
 * @param int[]  $ids   Referenced form IDs.
 * @param string $label Human-readable usage location.
 * @param string $url   Optional admin URL for the usage location.
 */
function gfdrp_add_usage_references( &$usage, $ids, $label, $url = '' ) {
	foreach ( array_values( array_filter( array_unique( array_map( 'absint', $ids ) ) ) ) as $form_id ) {
		$usage[ $form_id ] = isset( $usage[ $form_id ] ) && is_array( $usage[ $form_id ] ) ? $usage[ $form_id ] : array();
		$key               = md5( $label . "\0" . $url );
		$usage[ $form_id ][ $key ] = array(
			'label' => (string) $label,
			'url'   => (string) $url,
		);
	}
}

/**
 * Find form references in posts, post meta, widgets, theme settings, and themes.
 *
 * @return array<int,array<int,array{label:string,url:string}>>
 */
function gfdrp_find_form_usage() {
	$usage      = array();
	$post_types = get_post_types( array(), 'names' );
	$post_ids   = get_posts(
		array(
			'post_type'              => array_values( $post_types ),
			'post_status'            => array( 'publish', 'draft' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
			continue;
		}

		$post_type    = (string) ( $post->post_type ?? 'content' );
		$post_title   = (string) ( $post->post_title ?? '' );
		$post_title   = '' !== $post_title ? $post_title : __( '(untitled)', 'gravity-forms-data-retention-policy' );
		$status_label = 'publish' === $post->post_status
			? __( 'Published', 'gravity-forms-data-retention-policy' )
			: __( 'Draft', 'gravity-forms-data-retention-policy' );
		$label        = sprintf( '%1$s: %2$s (#%3$d) — %4$s', ucfirst( $post_type ), $post_title, (int) $post_id, $status_label );
		$url          = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '';
		$ids          = array_merge(
			gfdrp_extract_form_ids( $post->post_content ),
			gfdrp_extract_form_ids_from_blocks( parse_blocks( $post->post_content ) ),
			gfdrp_extract_form_ids( get_post_meta( $post_id ) )
		);
		gfdrp_add_usage_references( $usage, $ids, $label, $url );
	}

	foreach ( array( 'widget_gravityforms', 'widget_text', 'widget_block', 'widget_custom_html' ) as $option_name ) {
		gfdrp_add_usage_references(
			$usage,
			gfdrp_extract_form_ids( get_option( $option_name, array() ) ),
			sprintf( __( 'Widget setting: %s', 'gravity-forms-data-retention-policy' ), $option_name )
		);
	}

	gfdrp_add_usage_references( $usage, gfdrp_extract_form_ids( get_theme_mods() ), __( 'Active theme settings', 'gravity-forms-data-retention-policy' ) );

	foreach ( array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory() ) ) ) as $theme_directory ) {
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $theme_directory, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $exception ) {
			unset( $exception );
			continue;
		}

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) && $file->getSize() <= 2 * MB_IN_BYTES ) {
				$content = file_get_contents( $file->getPathname() );

				if ( false !== $content ) {
					$relative_path = ltrim( substr( $file->getPathname(), strlen( $theme_directory ) ), DIRECTORY_SEPARATOR );
					gfdrp_add_usage_references(
						$usage,
						gfdrp_extract_form_ids( $content ),
						sprintf( __( 'Active theme file: %s', 'gravity-forms-data-retention-policy' ), $relative_path )
					);
				}
			}
		}
	}

	foreach ( $usage as $form_id => $locations ) {
		$usage[ $form_id ] = array_values( $locations );
	}

	return $usage;
}

/**
 * Return the IDs of all forms with a detected usage location.
 *
 * @return int[]
 */
function gfdrp_find_referenced_form_ids() {
	return array_map( 'absint', array_keys( gfdrp_find_form_usage() ) );
}

/**
 * Report active forms for which no supported embed reference can be found.
 *
 * @return array{forms:array,referenced_ids:int[],generated_at:int,error:bool}
 */
function gfdrp_build_unused_forms_report() {
	$report = array(
		'forms'          => array(),
		'referenced_ids' => array(),
		'generated_at'   => time(),
		'error'          => false,
	);

	if ( ! class_exists( 'GFAPI' ) ) {
		$report['error'] = true;
		return $report;
	}

	$usage                    = gfdrp_find_form_usage();
	$report['referenced_ids'] = array_map( 'absint', array_keys( $usage ) );

	foreach ( GFAPI::get_forms( true, false ) as $form ) {
		$form_id = absint( $form['id'] ?? 0 );

		if ( $form_id ) {
			$report['forms'][] = array(
				'id'     => $form_id,
				'title'  => (string) ( $form['title'] ?? '' ),
				'in_use' => ! empty( $usage[ $form_id ] ),
				'uses'   => $usage[ $form_id ] ?? array(),
			);
		}
	}

	return $report;
}

/**
 * Deactivate forms that still have no detected usage at execution time.
 *
 * @param int[] $selected_ids Form IDs selected from the preceding scan.
 * @return array{deactivated:int,failed:int,scan_error:bool}
 */
function gfdrp_deactivate_unused_forms( $selected_ids ) {
	$report = gfdrp_build_unused_forms_report();
	$selected_ids = array_values( array_filter( array_unique( array_map( 'absint', is_array( $selected_ids ) ? $selected_ids : array() ) ) ) );
	$result = array(
		'deactivated' => 0,
		'failed'      => 0,
		'scan_error'  => ! empty( $report['error'] ),
	);

	if ( $result['scan_error'] ) {
		return $result;
	}

	foreach ( $report['forms'] as $form ) {
		if ( ! in_array( $form['id'], $selected_ids, true ) || ! empty( $form['in_use'] ) ) {
			continue;
		}

		$updated = GFAPI::update_form_property( $form['id'], 'is_active', false );

		if ( is_wp_error( $updated ) || false === $updated ) {
			++$result['failed'];
		} else {
			++$result['deactivated'];
		}
	}

	return $result;
}
