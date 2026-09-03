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
		foreach ( (array) $value as $item ) {
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
 * Find form references in posts, post meta, widgets, theme settings, and themes.
 *
 * @return int[]
 */
function gfdrp_find_referenced_form_ids() {
	$referenced = array();
	$post_types = get_post_types( array(), 'names' );
	$post_ids   = get_posts(
		array(
			'post_type'              => array_values( $post_types ),
			'post_status'            => 'any',
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

		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			continue;
		}

		$referenced = array_merge( $referenced, gfdrp_extract_form_ids( $post->post_content ) );
		$referenced = array_merge( $referenced, gfdrp_extract_form_ids_from_blocks( parse_blocks( $post->post_content ) ) );
		$referenced = array_merge( $referenced, gfdrp_extract_form_ids( get_post_meta( $post_id ) ) );
	}

	foreach ( array( 'widget_gravityforms', 'widget_text', 'widget_block', 'widget_custom_html' ) as $option_name ) {
		$referenced = array_merge( $referenced, gfdrp_extract_form_ids( get_option( $option_name, array() ) ) );
	}

	$referenced = array_merge( $referenced, gfdrp_extract_form_ids( get_theme_mods() ) );

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
					$referenced = array_merge( $referenced, gfdrp_extract_form_ids( $content ) );
				}
			}
		}
	}

	return array_values( array_filter( array_unique( array_map( 'absint', $referenced ) ) ) );
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

	$report['referenced_ids'] = gfdrp_find_referenced_form_ids();

	foreach ( GFAPI::get_forms( true, false ) as $form ) {
		$form_id = absint( $form['id'] ?? 0 );

		if ( $form_id && ! in_array( $form_id, $report['referenced_ids'], true ) ) {
			$report['forms'][] = array(
				'id'    => $form_id,
				'title' => (string) ( $form['title'] ?? '' ),
			);
		}
	}

	return $report;
}

/**
 * Deactivate forms that still have no detected usage at execution time.
 *
 * @return array{deactivated:int,failed:int,scan_error:bool}
 */
function gfdrp_deactivate_unused_forms() {
	$report = gfdrp_build_unused_forms_report();
	$result = array(
		'deactivated' => 0,
		'failed'      => 0,
		'scan_error'  => ! empty( $report['error'] ),
	);

	if ( $result['scan_error'] ) {
		return $result;
	}

	foreach ( $report['forms'] as $form ) {
		$updated = GFAPI::update_form_property( $form['id'], 'is_active', false );

		if ( is_wp_error( $updated ) || false === $updated ) {
			++$result['failed'];
		} else {
			++$result['deactivated'];
		}
	}

	return $result;
}
