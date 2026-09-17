<?php

namespace Rega;

use Rega\Admin\SettingsPage;
use Rega\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Each part registers its own hooks; this class only
 * decides which parts run.
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_translations' ) );
		add_action( 'rest_api_init', array( Routes::class, 'register' ) );

		if ( is_admin() ) {
			SettingsPage::register();
		}
	}

	public static function load_translations(): void {
		load_plugin_textdomain( 'rega', false, dirname( plugin_basename( REGA_FILE ) ) . '/languages' );
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}
}
