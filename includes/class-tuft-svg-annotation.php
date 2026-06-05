<?php
/**
 * Shared SVG screenshot annotation builder.
 *
 * Generates an inline SVG that composites a screenshot with a spotlight overlay
 * and a ring-and-crosshair marker at a given click location. Used in both the
 * admin detail meta box (Tuft_Post_Type) and the Alpaca Issue Tracker comment
 * bridge (Tuft_Alpaca_Bridge).
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds inline SVG screenshot annotations for design feedback entries.
 */
class Tuft_SVG_Annotation {

	/**
	 * Build an inline SVG showing a screenshot with a spotlight annotation.
	 *
	 * The SVG uses a mask to cut a circular spotlight out of a dark overlay,
	 * then draws a ring, crosshair arms, and a centre dot at the click coordinates.
	 * When $rect is provided, a dashed bounding-box rectangle is also drawn to show
	 * which element was targeted.
	 * Mask IDs are scoped to $scope_id so multiple instances on the same page
	 * cannot collide.
	 *
	 * @param int        $shot_id  Attachment ID of the screenshot JPEG.
	 * @param float      $x_pct    Click X as a percentage of viewport width (0–100).
	 * @param float      $y_pct    Click Y as a percentage of viewport height (0–100).
	 * @param int        $scope_id Any integer unique to this render context (e.g. post ID).
	 * @param array|null $rect     Optional. Element bounding box as viewport percentages:
	 *                             keys 'left', 'top', 'width', 'height' (all 0–100).
	 * @return string SVG markup, or empty string on failure.
	 */
	public static function build( $shot_id, $x_pct, $y_pct, $scope_id, $rect = null ) {
		$shot_url = wp_get_attachment_url( $shot_id );
		if ( ! $shot_url ) {
			return '';
		}

		$meta = wp_get_attachment_metadata( $shot_id );
		$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 1280;
		$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 800;

		$cx      = (int) round( ( $x_pct / 100 ) * $w );
		$cy      = (int) round( ( $y_pct / 100 ) * $h );
		$spot_r  = (int) round( min( $w, $h ) * 0.15 );
		$mask_id = 'df-sm-' . (int) $scope_id;

		// Crosshair arms: gap = distance from ring edge to arm start; arm = arm length.
		$gap  = 22;
		$arm  = 14;
		$arms = array(
			array( $cx - $gap - $arm, $cy, $cx - $gap, $cy ),
			array( $cx + $gap, $cy, $cx + $gap + $arm, $cy ),
			array( $cx, $cy - $gap - $arm, $cx, $cy - $gap ),
			array( $cx, $cy + $gap, $cx, $cy + $gap + $arm ),
		);

		$escaped_url = esc_url( $shot_url );
		$escaped_id  = esc_attr( $mask_id );

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg"';
		$svg .= ' xmlns:xlink="http://www.w3.org/1999/xlink"';
		$svg .= ' viewBox="0 0 ' . $w . ' ' . $h . '"';
		$svg .= ' style="max-width:100%;height:auto;display:block;border:1px solid #ddd;border-radius:3px;">';

		// Screenshot as background image.
		$svg .= '<image href="' . $escaped_url . '" xlink:href="' . $escaped_url . '"';
		$svg .= ' x="0" y="0" width="' . $w . '" height="' . $h . '"/>';

		// Mask: white everywhere except a black circle at the click point (spotlight hole).
		$svg .= '<defs>';
		$svg .= '<mask id="' . $escaped_id . '">';
		$svg .= '<rect width="' . $w . '" height="' . $h . '" fill="white"/>';
		$svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $spot_r . '" fill="black"/>';
		$svg .= '</mask>';
		$svg .= '</defs>';

		// Dark overlay applied through the mask, leaving the spotlight area bright.
		$svg .= '<rect width="' . $w . '" height="' . $h . '" fill="rgba(0,0,0,0.6)"';
		$svg .= ' mask="url(#' . $escaped_id . ')"/>';

		// Element bounding box: dashed amber rectangle drawn over the overlay.
		if ( is_array( $rect )
			&& isset( $rect['left'], $rect['top'], $rect['width'], $rect['height'] )
		) {
			$rx = max( 0, (int) round( ( $rect['left'] / 100 ) * $w ) );
			$ry = max( 0, (int) round( ( $rect['top'] / 100 ) * $h ) );
			$rw = min( (int) round( ( $rect['width'] / 100 ) * $w ), $w - $rx );
			$rh = min( (int) round( ( $rect['height'] / 100 ) * $h ), $h - $ry );

			if ( $rw > 0 && $rh > 0 ) {
				// White base stroke for contrast against any background.
				$svg .= '<rect x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $rh . '"';
				$svg .= ' fill="rgba(255,255,255,0.06)" stroke="white" stroke-width="2.5"';
				$svg .= ' stroke-opacity="0.5" stroke-dasharray="8,4"/>';
				// Amber overlay stroke offset by half a dash for a two-tone dashed effect.
				$svg .= '<rect x="' . $rx . '" y="' . $ry . '" width="' . $rw . '" height="' . $rh . '"';
				$svg .= ' fill="none" stroke="#fbbf24" stroke-width="1.5"';
				$svg .= ' stroke-dasharray="8,4" stroke-dashoffset="4"/>';
			}
		}

		// Ring: white halo then red stroke for contrast on any background.
		$svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="18"';
		$svg .= ' fill="none" stroke="white" stroke-width="4" stroke-opacity="0.9"/>';
		$svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="18"';
		$svg .= ' fill="none" stroke="#ef4444" stroke-width="2"/>';

		// Crosshair arms — white pass then red pass for the same outline effect.
		foreach ( $arms as $a ) {
			$svg .= '<line x1="' . $a[0] . '" y1="' . $a[1] . '" x2="' . $a[2] . '" y2="' . $a[3] . '"';
			$svg .= ' stroke="white" stroke-width="3" stroke-opacity="0.9"/>';
		}
		foreach ( $arms as $a ) {
			$svg .= '<line x1="' . $a[0] . '" y1="' . $a[1] . '" x2="' . $a[2] . '" y2="' . $a[3] . '"';
			$svg .= ' stroke="#ef4444" stroke-width="1.5"/>';
		}

		// Centre dot: red filled with white core.
		$svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="4" fill="#ef4444"/>';
		$svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="2" fill="white"/>';

		$svg .= '</svg>';

		return $svg;
	}
}
