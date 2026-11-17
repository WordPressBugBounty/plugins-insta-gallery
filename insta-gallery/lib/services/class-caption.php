<?php

namespace QuadLayers\IGG\Services;

/**
 * Instagram captions are plain text that any Instagram user writes (hashtag
 * and tagged feeds), so they are served without HTML tags.
 */
class Caption {

	/**
	 * Removes the HTML tags of a caption, keeping the text that only looks
	 * like a tag ("I <3 you", "2 < 3"), unlike wp_strip_all_tags().
	 *
	 * Repeated until nothing changes, as removing a tag can join a new one
	 * ("<<b>img onerror=...>").
	 *
	 * @param string $caption Caption.
	 * @return string
	 */
	public static function strip_tags( $caption ) {
		do {
			$previous = $caption;
			$caption  = preg_replace( '/<\/?[a-z!][^>]*>/i', '', $caption );
		} while ( null !== $caption && $caption !== $previous );

		return null === $caption ? '' : $caption;
	}

	/**
	 * Removes the HTML tags of the captions of the media and their children.
	 *
	 * @param array $items Media items.
	 * @return array
	 */
	public static function strip_media_tags( array $items ) {
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['media_description'] ) && is_string( $item['media_description'] ) ) {
				$items[ $key ]['media_description'] = self::strip_tags( $item['media_description'] );
			}
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$items[ $key ]['children'] = self::strip_media_tags( $item['children'] );
			}
		}

		return $items;
	}
}
