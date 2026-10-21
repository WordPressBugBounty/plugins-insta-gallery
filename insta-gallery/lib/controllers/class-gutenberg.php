<?php

namespace QuadLayers\IGG\Controllers;

use QuadLayers\IGG\Helpers;
use QuadLayers\IGG\Models\Feeds as Models_Feeds;
use QuadLayers\IGG\Models\Settings as Models_Settings;
use QuadLayers\IGG\Frontend\Load as Frontend;

use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\User_Profile as Api_Rest_User_Profile;
use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\User_Media as Api_Rest_User_Media;
use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\User_Stories as Api_Rest_User_Stories;
use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\Media_Comments as Api_Rest_Media_Comments;
use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\Tagged_Media as Api_Rest_Tagged_Media;
use QuadLayers\IGG\Api\Rest\Endpoints\Frontend\Hashtag_Media as Api_Rest_Hashtag_Media;
class Gutenberg {

	protected static $instance;

	private function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_iframe_scripts' ) );
	}

	/**
	 * The layouts run inside the iframed block editor and need their own
	 * Swiper (carousel) and Masonry (masonry, highlight) instances there.
	 * Only in the editor: on the frontend the shortcode enqueues them when
	 * the layout needs them.
	 */
	public function enqueue_editor_iframe_scripts() {
		if ( ! is_admin() ) {
			return;
		}
		wp_enqueue_script( 'qligg-swiper' );
		wp_enqueue_script( 'masonry' );
	}

	public function register_scripts() {
		$gutenberg = include QLIGG_PLUGIN_DIR . 'build/gutenberg/js/index.asset.php';

		Frontend::instance()->register_scripts();

		wp_register_style( 'qligg-gutenberg-editor', plugins_url( '/build/gutenberg/css/editor.css', QLIGG_PLUGIN_FILE ), array( 'qligg-frontend', 'qligg-swiper' ), QLIGG_PLUGIN_VERSION );
		wp_register_script( 'qligg-gutenberg', plugins_url( '/build/gutenberg/js/index.js', QLIGG_PLUGIN_FILE ), array_merge( $gutenberg['dependencies'], array( 'qligg-frontend' ) ), $gutenberg['version'], true );

		wp_localize_script(
			'qligg-gutenberg',
			'qligg_gutenberg',
			array(
				'image_url'            => plugins_url( '/assets/backend/img', QLIGG_PLUGIN_FILE ),
				'QLIGG_PERSONAL_LINK'  => Helpers::get_personal_access_token_link(),
				'QLIGG_BUSSINESS_LINK' => Helpers::get_business_access_token_link(),
			)
		);
		/**
		 * Fix missing qligg_frontend object in gutenberg script
		 * Frontend is loaded in the gutenberg editor script directly
		 */
		wp_localize_script(
			'qligg-frontend',
			'qligg_frontend',
			array(
				'settings'       => Models_Settings::instance()->get(),
				'restRoutePaths' => array(
					'username'    => Api_Rest_User_Media::get_rest_url(),
					'tag'         => Api_Rest_Hashtag_Media::get_rest_url(),
					'tagged'      => Api_Rest_Tagged_Media::get_rest_url(),
					'stories'     => Api_Rest_User_Stories::get_rest_url(),
					'comments'    => Api_Rest_Media_Comments::get_rest_url(),
					'userprofile' => Api_Rest_User_Profile::get_rest_url(),
				),
			)
		);
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'qligg-gutenberg-editor' );
		wp_enqueue_script( 'qligg-gutenberg' );
	}

	public function register_block() {
		// The iframed block editor collects the editor_style handles before
		// admin_enqueue_scripts fires: register them before the block. They
		// are only enqueued where the block is used.
		$this->register_scripts();

		register_block_type(
			'qligg/box',
			array(
				'attributes'      => $this->get_attributes(),
				'render_callback' => array( $this, 'render_callback' ),
				'editor_style'    => array( 'qligg-swiper', 'qligg-frontend', 'qligg-gutenberg-editor' ),
				'editor_script'   => array( 'qligg-swiper', 'masonry', 'qligg-frontend', 'qligg-gutenberg' ),
			)
		);
	}

	public function render_callback( $attributes, $content, $block = array() ) {
		return Frontend::instance()->create_shortcode( $attributes );
	}

	private function get_attributes() {

		$feed_arg = Models_Feeds::instance()->get_args();

		$attributes = array();

		foreach ( $feed_arg as $id => $value ) {
			$attributes[ $id ] = array(
				'type'    => array( 'string', 'object', 'array', 'boolean', 'number', 'null' ),
				'default' => $value,
			);
			if ( $id === 'account_id' ) {
				$attributes[ $id ] = array(
					'type'    => array( 'string', 'object', 'array', 'boolean', 'number', 'null' ),
					'default' => '',
				);
			}
		}

		return $attributes;
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
