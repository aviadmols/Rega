<?php

namespace Rega;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in one option. Every read goes through a sanitizer, so a value
 * edited in the database directly can never widen what the API exposes.
 */
final class Settings {

	public const OPTION = 'rega_settings';

	/**
	 * Post types whose published entries Rega may read as guides and articles.
	 *
	 * @return list<string>
	 */
	public static function content_post_types(): array {
		$stored = get_option( self::OPTION, array() );
		$types  = is_array( $stored ) && isset( $stored['content_post_types'] ) ? (array) $stored['content_post_types'] : array( 'post' );

		return self::sanitize_post_types( $types );
	}

	/**
	 * @param array<mixed> $types
	 */
	public static function save_content_post_types( array $types ): void {
		$stored                       = get_option( self::OPTION, array() );
		$stored                       = is_array( $stored ) ? $stored : array();
		$stored['content_post_types'] = self::sanitize_post_types( $types );

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Public post types a guide can live in. Products are exposed by their own endpoint, and
	 * attachments are not content.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function available_content_post_types(): array {
		$available = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'product', 'attachment' ), true ) ) {
				continue;
			}

			$available[ $type->name ] = $type->labels->name;
		}

		return $available;
	}

	/**
	 * @param array<mixed> $types
	 * @return list<string>
	 */
	private static function sanitize_post_types( array $types ): array {
		$allowed = array_keys( self::available_content_post_types() );

		return array_values( array_unique( array_filter( array_map( 'strval', $types ), static fn ( string $type ): bool => in_array( $type, $allowed, true ) ) ) );
	}
}
