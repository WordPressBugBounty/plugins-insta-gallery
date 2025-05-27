<?php

namespace QuadLayers\IGG\Controllers;

use QuadLayers\IGG\Controllers\Elementor_Widget;

/**
 * Elementor_Integration Class
 */
class Elementor {

	protected static $instance;

	private function __construct() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}
		add_action( 'elementor/editor/after_enqueue_scripts', array( 'QuadLayers\IGG\Controllers\Backend', 'add_premium_css' ), 10 );
		add_action( 'elementor/editor/after_enqueue_scripts', array( 'QuadLayers\IGG\Controllers\Backend', 'register_scripts' ), 10 );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'register_scripts' ), 10 );
		add_action( 'elementor/editor/footer', array( __CLASS__, 'add_premium_js' ), 10 );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register script dependencies for Elementor
	 */
	public function register_scripts() {

		$elementor = include QLIGG_PLUGIN_DIR . 'build/elementor/js/index.asset.php';

		wp_enqueue_script(
			'qligg-elementor-widget',
			plugins_url( '/build/elementor/js/index.js', QLIGG_PLUGIN_FILE ),
			$elementor['dependencies'],
			$elementor['version'],
			true
		);

		wp_localize_script(
			'qligg-elementor-widget',
			'qligg_elementor_widget',
			array(
				'i18n' => array(
					'headerMessage' => __( 'Premium Feature', 'insta-gallery' ),
					'message'       => __( 'This option is available only in the Premium version. Unlock it now at QuadLayers.', 'insta-gallery' ),
					'confirm'       => __( 'Confirm', 'insta-gallery' ),
					'cancel'        => __( 'Cancel', 'insta-gallery' ),
				),
				'url'  => 'https://quadlayers.com/products/instagram-feed-gallery/?utm_source=wordpress&utm_medium=qligg_admin&utm_campaign=elementor',
			)
		);
	}

	/**
	 * Register Elementor widgets
	 */
	public function register_widgets( $widgets_manager ) {
		$widgets_manager->register( new Elementor_Widget() );
	}

	public static function add_premium_js() {
		?>
			<script>
				var QLIGG_IS_PREMIUM = false;
			</script>
		<?php
	}

	/**
	 * Return class instance
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
