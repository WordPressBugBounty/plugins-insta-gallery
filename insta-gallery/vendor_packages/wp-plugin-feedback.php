<?php

if ( class_exists( 'QuadLayers\\PluginFeedback\\Load' ) ) {
	\QuadLayers\PluginFeedback\Load::instance()->add(
		QLIGG_PLUGIN_FILE,
		array(
			'support_link' => 'https://wordpress.org/support/plugin/insta-gallery/',
		)
	);
}