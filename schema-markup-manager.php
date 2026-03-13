<?php
/**
 * Plugin Name: Schema Markup Manager
 * Plugin URI: https://example.com
 * Description: Schema.org JSON-LD markup manager, per-page markup, aggregateRating from reviews plugin.
 * Author: Vlad
 * Version: 1.0.1
 * Text Domain: schema-markup-manager
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SCHEMA_MARKUP_AS_PLUGIN', true );
define( 'SCHEMA_MARKUP_MANAGER_VERSION', '1.0.1' );
define( 'SCHEMA_MARKUP_MANAGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Auto-updates via Plugin Update Checker (GitHub).
if ( file_exists( SCHEMA_MARKUP_MANAGER_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require SCHEMA_MARKUP_MANAGER_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $schema_markup_manager_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/CruentoVulpes/schema-markup-manager/',
            __FILE__,
            'schema-markup-manager'
        );

        $schema_markup_manager_update_checker->setBranch( 'main' );
    }
}

require_once SCHEMA_MARKUP_MANAGER_PLUGIN_DIR . 'includes/class-schema-markup.php';
require_once SCHEMA_MARKUP_MANAGER_PLUGIN_DIR . 'includes/class-schema-markup-manager.php';

add_action( 'init', 'schema_markup_manager_bootstrap', 0 );

function schema_markup_manager_bootstrap() {
    SchemaMarkupManager\Schema_Markup_Manager::instance();

    add_action( 'wp_head', [ SchemaMarkupManager\Schema_Markup::class, 'wp_head_start_buffer' ], -1 );
    add_action( 'wp_head', [ SchemaMarkupManager\Schema_Markup::class, 'wp_head_end_buffer_and_dedupe' ], PHP_INT_MAX );

    if ( class_exists( 'AutoReviews\Plugin' ) && method_exists( \AutoReviews\Plugin::instance(), 'output_schema_in_head' ) ) {
        remove_action( 'wp_head', [ \AutoReviews\Plugin::instance(), 'output_schema_in_head' ], 10 );
    }
}

