<?php

namespace QuadLayers\PluginFeedback;

class Scripts
{

    public static $instance;
    public static $plugins;

    private function __construct(array $plugins)
    {
        self::$plugins = $plugins;
        add_action('admin_enqueue_scripts', [self::class, 'load']);
    }

    public static function instance(array $plugins)
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($plugins);
        }
        return self::$instance;
    }

    public static function load(): void
    {
        global $pagenow;
    
        // Only load on the plugins page
        if ($pagenow !== 'plugins.php') {
            return;
        }

        $feedback = include plugin_dir_path(__FILE__) . '../build/js/index.asset.php';
    
        wp_enqueue_style('wp-components');
        wp_enqueue_script('quadlayers-plugin-feedback', plugins_url('../build/js/index.js', __FILE__), $feedback['dependencies'], '1.0.0', true);
    
        wp_localize_script(
            'quadlayers-plugin-feedback', 
            'quadlayersPluginFeedback', 
            [
                    'nonce'     => wp_create_nonce('quadlayers_send_feedback_nonce'),
                    'plugins'   => self::$plugins
                ]
        );
    }
}
