<?php
/**
 * Plugin Name: Claude WP Bridge
 * Description: Exposes WordPress content, theme files and plugin management as WordPress Abilities for Claude Code via MCP. Replaces Compulibra Manager and Compulibra Auto Upload.
 * Version:     1.1.0
 * Author:      Mariano Cappucci
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_abilities_api_init', function () {

    // ───────────────────────────────────────
    // claude/list-pages
    // ───────────────────────────────────────
    wp_register_ability( 'claude/list-pages', [
        'label'       => 'List Pages',
        'description' => 'List all WordPress pages with ID, title, slug and status.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'    => 'string',
                    'enum'    => [ 'any', 'publish', 'draft', 'private' ],
                    'default' => 'any',
                    'description' => 'Filter by post status',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'     => [ 'type' => 'integer' ],
                    'title'  => [ 'type' => 'string' ],
                    'slug'   => [ 'type' => 'string' ],
                    'status' => [ 'type' => 'string' ],
                    'url'    => [ 'type' => 'string' ],
                ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $posts = get_posts( [
                'post_type'   => 'page',
                'post_status' => $input['status'] ?? 'any',
                'numberposts' => -1,
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
            ] );
            return array_map( function ( $p ) {
                return [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'slug'   => $p->post_name,
                    'status' => $p->post_status,
                    'url'    => get_permalink( $p->ID ),
                ];
            }, $posts );
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/get-page
    // ───────────────────────────────────────
    wp_register_ability( 'claude/get-page', [
        'label'       => 'Get Page Content',
        'description' => 'Get the raw HTML content of a WordPress page by ID.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'page_id' => [ 'type' => 'integer', 'description' => 'The page ID' ],
            ],
            'required'             => [ 'page_id' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
                'slug'    => [ 'type' => 'string' ],
                'status'  => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $post = get_post( intval( $input['page_id'] ) );
            if ( ! $post || $post->post_type !== 'page' ) {
                return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
            }
            return [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'slug'    => $post->post_name,
                'status'  => $post->post_status,
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/update-page
    // ───────────────────────────────────────
    wp_register_ability( 'claude/update-page', [
        'label'       => 'Update Page Content',
        'description' => 'Replace the HTML content of a WordPress page. Bypasses kses filtering to preserve raw HTML, CSS, and inline scripts.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'page_id' => [ 'type' => 'integer', 'description' => 'The page ID' ],
                'content' => [ 'type' => 'string', 'description' => 'New HTML content for post_content' ],
                'title'   => [ 'type' => 'string', 'description' => 'Optional: new page title' ],
            ],
            'required'             => [ 'page_id', 'content' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'page_id' => [ 'type' => 'integer' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $page_id = intval( $input['page_id'] );
            $post    = get_post( $page_id );
            if ( ! $post || $post->post_type !== 'page' ) {
                return new WP_Error( 'not_found', 'Page not found', [ 'status' => 404 ] );
            }

            $update = [ 'ID' => $page_id, 'post_content' => $input['content'] ];
            if ( ! empty( $input['title'] ) ) {
                $update['post_title'] = sanitize_text_field( $input['title'] );
            }

            // Temporarily disable kses so raw HTML is preserved as-is.
            kses_remove_filters();
            $result = wp_update_post( $update, true );
            kses_init_filters();

            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return [ 'success' => true, 'page_id' => $result ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/list-theme-files
    // ───────────────────────────────────────
    wp_register_ability( 'claude/list-theme-files', [
        'label'       => 'List Theme Files',
        'description' => 'List files in the active theme directory, optionally filtered by extension.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'ext' => [
                    'type'        => 'string',
                    'description' => 'Filter by extension, e.g. "css", "php", "js". Omit for all files.',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [ 'type' => 'string' ],
        ],
        'execute_callback' => function ( $input ) {
            $theme_dir = get_stylesheet_directory();
            $ext_filter = isset( $input['ext'] ) ? '.' . ltrim( $input['ext'], '.' ) : null;

            $files = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $file ) {
                if ( $file->isFile() ) {
                    $rel = str_replace( $theme_dir . '/', '', $file->getPathname() );
                    if ( $ext_filter === null || str_ends_with( $rel, $ext_filter ) ) {
                        $files[] = $rel;
                    }
                }
            }
            sort( $files );
            return $files;
        },
        'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/get-theme-file
    // ───────────────────────────────────────
    wp_register_ability( 'claude/get-theme-file', [
        'label'       => 'Get Theme File',
        'description' => 'Read a file from the active WordPress theme directory.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path' => [ 'type' => 'string', 'description' => 'Relative path within the theme, e.g. "style.css"' ],
            ],
            'required'             => [ 'path' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'path'    => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $theme_dir = get_stylesheet_directory();
            $rel_path  = ltrim( str_replace( '..', '', $input['path'] ), '/\\' );
            $full_path = $theme_dir . '/' . $rel_path;

            if ( ! file_exists( $full_path ) || is_dir( $full_path ) ) {
                return new WP_Error( 'not_found', "File not found: $rel_path", [ 'status' => 404 ] );
            }
            return [
                'path'    => $rel_path,
                'content' => file_get_contents( $full_path ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/update-theme-file
    // ───────────────────────────────────────
    wp_register_ability( 'claude/update-theme-file', [
        'label'       => 'Update Theme File',
        'description' => 'Write content to a file in the active WordPress theme directory. Creates the file if it does not exist.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path'    => [ 'type' => 'string', 'description' => 'Relative path within the theme' ],
                'content' => [ 'type' => 'string', 'description' => 'New file content' ],
            ],
            'required'             => [ 'path', 'content' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'path'    => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $theme_dir = get_stylesheet_directory();
            $rel_path  = ltrim( str_replace( '..', '', $input['path'] ), '/\\' );
            $full_path = $theme_dir . '/' . $rel_path;

            $dir = dirname( $full_path );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $bytes = file_put_contents( $full_path, $input['content'] );
            if ( $bytes === false ) {
                return new WP_Error( 'write_error', 'Failed to write file', [ 'status' => 500 ] );
            }
            return [ 'success' => true, 'path' => $rel_path ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_theme_options' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/upload-file
    // ───────────────────────────────────────
    wp_register_ability( 'claude/upload-file', [
        'label'       => 'Upload File',
        'description' => 'Write a file to wp-content/plugins/ or wp-content/themes/. Content can be plain text or base64-encoded (set base64: true).',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'path'    => [ 'type' => 'string', 'description' => 'Relative path from wp-content/, e.g. "plugins/myplugin/myplugin.php"' ],
                'content' => [ 'type' => 'string', 'description' => 'File content (plain text or base64)' ],
                'base64'  => [ 'type' => 'boolean', 'default' => false, 'description' => 'True if content is base64-encoded' ],
            ],
            'required'             => [ 'path', 'content' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success'   => [ 'type' => 'boolean' ],
                'full_path' => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $rel_path = ltrim( str_replace( '..', '', $input['path'] ), '/\\' );

            if ( ! preg_match( '#^(plugins|themes)/#', $rel_path ) ) {
                return new WP_Error( 'path_denied', 'Path must start with plugins/ or themes/', [ 'status' => 403 ] );
            }

            $full_path = WP_CONTENT_DIR . '/' . $rel_path;
            $dir = dirname( $full_path );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $content = $input['content'];
            if ( ! empty( $input['base64'] ) ) {
                $content = base64_decode( $content );
                if ( $content === false ) {
                    return new WP_Error( 'decode_error', 'Invalid base64 content', [ 'status' => 400 ] );
                }
            }

            $bytes = file_put_contents( $full_path, $content );
            if ( $bytes === false ) {
                return new WP_Error( 'write_error', 'Failed to write file', [ 'status' => 500 ] );
            }
            return [ 'success' => true, 'full_path' => $full_path ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/list-plugins
    // ───────────────────────────────────────
    wp_register_ability( 'claude/list-plugins', [
        'label'       => 'List Plugins',
        'description' => 'List all installed WordPress plugins with their name, status, version and description.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => [ 'any', 'active', 'inactive' ],
                    'default'     => 'any',
                    'description' => 'Filter by plugin status',
                ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'slug'        => [ 'type' => 'string' ],
                    'name'        => [ 'type' => 'string' ],
                    'version'     => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'active'      => [ 'type' => 'boolean' ],
                ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all     = get_plugins();
            $active  = get_option( 'active_plugins', [] );
            $filter  = $input['status'] ?? 'any';
            $result  = [];
            foreach ( $all as $slug => $data ) {
                $is_active = in_array( $slug, $active, true );
                if ( $filter === 'active'   && ! $is_active ) continue;
                if ( $filter === 'inactive' &&   $is_active ) continue;
                $result[] = [
                    'slug'        => $slug,
                    'name'        => $data['Name'],
                    'version'     => $data['Version'],
                    'description' => wp_strip_all_tags( $data['Description'] ),
                    'active'      => $is_active,
                ];
            }
            return $result;
        },
        'permission_callback' => fn() => current_user_can( 'activate_plugins' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/manage-plugin
    // ───────────────────────────────────────
    wp_register_ability( 'claude/manage-plugin', [
        'label'       => 'Manage Plugin',
        'description' => 'Activate or deactivate a WordPress plugin by its slug (e.g. "akismet/akismet.php").',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'plugin' => [
                    'type'        => 'string',
                    'description' => 'Plugin slug, e.g. "my-plugin/my-plugin.php"',
                ],
                'action' => [
                    'type'        => 'string',
                    'enum'        => [ 'activate', 'deactivate' ],
                    'default'     => 'activate',
                    'description' => 'Whether to activate or deactivate the plugin',
                ],
            ],
            'required'             => [ 'plugin' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
                'action'  => [ 'type' => 'string' ],
                'plugin'  => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $plugin = sanitize_text_field( $input['plugin'] );
            $action = $input['action'] ?? 'activate';

            if ( $action === 'deactivate' ) {
                deactivate_plugins( $plugin );
                return [ 'success' => true, 'action' => 'deactivated', 'plugin' => $plugin ];
            }

            $result = activate_plugin( $plugin );
            if ( is_wp_error( $result ) ) {
                return new WP_Error( 'activation_failed', $result->get_error_message(), [ 'status' => 500 ] );
            }
            return [ 'success' => true, 'action' => 'activated', 'plugin' => $plugin ];
        },
        'permission_callback' => fn() => current_user_can( 'activate_plugins' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/site-map
    // ───────────────────────────────────────
    wp_register_ability( 'claude/site-map', [
        'label'       => 'Site Map',
        'description' => 'Returns a complete overview of the site: name, URL, WordPress version, active theme, and all pages.',
        'category'    => 'site',
        'input_schema' => [ 'type' => 'object', 'additionalProperties' => false ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'site_name'  => [ 'type' => 'string' ],
                'site_url'   => [ 'type' => 'string' ],
                'theme'      => [ 'type' => 'string' ],
                'wp_version' => [ 'type' => 'string' ],
                'pages'      => [ 'type' => 'array' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $pages = get_posts( [
                'post_type'   => 'page',
                'post_status' => 'any',
                'numberposts' => -1,
                'orderby'     => 'menu_order',
            ] );
            return [
                'site_name'  => get_bloginfo( 'name' ),
                'site_url'   => get_site_url(),
                'theme'      => wp_get_theme()->get( 'Name' ),
                'wp_version' => get_bloginfo( 'version' ),
                'pages'      => array_map( fn( $p ) => [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'slug'   => $p->post_name,
                    'status' => $p->post_status,
                ], $pages ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

} );
