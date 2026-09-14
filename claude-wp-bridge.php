<?php
/**
 * Plugin Name: Claude WP Bridge
 * Description: Exposes WordPress content, theme files, plugin management and Elementor page data as WordPress Abilities for Claude Code via MCP. Replaces Compulibra Manager and Compulibra Auto Upload.
 * Version:     1.4.1
 * Author:      Mariano Cappucci
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ───────────────────────────────────────
// Tool groups
// ───────────────────────────────────────
//
// Every ability belongs to one group, by risk. An administrator switches the
// groups on and off in Settings → Claude WP Bridge; an ability whose group is
// off is not registered at all, so it cannot be listed or run. The groups that
// can run PHP on the site start switched off.

function claude_wp_bridge_groups() {
    return [
        'read'    => [
            'label'       => __( 'Read the site', 'claude-wp-bridge' ),
            'description' => __( 'Site map, pages, theme files, installed plugins and Elementor structure. Changes nothing.', 'claude-wp-bridge' ),
            'default'     => true,
            'abilities'   => [ 'claude/site-map', 'claude/list-pages', 'claude/get-page', 'claude/list-theme-files', 'claude/get-theme-file', 'claude/list-plugins', 'claude/elementor-list', 'claude/elementor-get' ],
        ],
        'content' => [
            'label'       => __( 'Edit pages and Elementor', 'claude-wp-bridge' ),
            'description' => __( 'Page content, Elementor pages and templates, display conditions and cache flushes. Every Elementor write is backed up first.', 'claude-wp-bridge' ),
            'default'     => true,
            'abilities'   => [ 'claude/update-page', 'claude/elementor-update-element', 'claude/elementor-save', 'claude/elementor-restore', 'claude/elementor-flush', 'claude/elementor-create', 'claude/elementor-set-conditions' ],
        ],
        'plugins' => [
            'label'       => __( 'Activate and deactivate plugins', 'claude-wp-bridge' ),
            'description' => __( 'Switch installed plugins on and off.', 'claude-wp-bridge' ),
            'default'     => false,
            'abilities'   => [ 'claude/manage-plugin' ],
        ],
        'code'    => [
            'label'       => __( 'Write code files', 'claude-wp-bridge' ),
            'description' => __( 'Theme files and uploads to plugins/ and themes/. Whoever holds an Application Password can then run PHP on this site: switch it on only while it is needed, e.g. to update this plugin.', 'claude-wp-bridge' ),
            'default'     => false,
            'abilities'   => [ 'claude/update-theme-file', 'claude/upload-file' ],
        ],
    ];
}

function claude_wp_bridge_enabled_groups() {
    $saved   = get_option( 'claude_wp_bridge_groups', null );
    $enabled = [];
    foreach ( claude_wp_bridge_groups() as $key => $group ) {
        $enabled[ $key ] = ( is_array( $saved ) && array_key_exists( $key, $saved ) ) ? (bool) $saved[ $key ] : $group['default'];
    }
    return $enabled;
}

function claude_wp_bridge_ability_group( $name ) {
    foreach ( claude_wp_bridge_groups() as $key => $group ) {
        if ( in_array( $name, $group['abilities'], true ) ) {
            return $key;
        }
    }
    return null;
}

// Registers an ability only when its group is on. An ability missing from the
// groups is never registered, so a new one cannot slip past the switches.
function claude_wp_bridge_register( $name, array $args ) {
    $group   = claude_wp_bridge_ability_group( $name );
    $enabled = claude_wp_bridge_enabled_groups();
    if ( $group === null || empty( $enabled[ $group ] ) ) {
        return;
    }
    wp_register_ability( $name, $args );
}

// ───────────────────────────────────────
// Elementor helpers
// ───────────────────────────────────────
//
// Elementor renders a page from the JSON in the _elementor_data meta, not from
// post_content (which only holds a plain-HTML copy), so claude/update-page does
// not change what an Elementor page shows. The claude/elementor-* abilities below
// edit that JSON through Elementor's own Document::save(), which validates the
// widgets and regenerates the post_content copy.

// Snapshots of _elementor_data kept per post before every write.
if ( ! defined( 'CLAUDE_WP_BRIDGE_ELEMENTOR_BACKUPS' ) ) {
    define( 'CLAUDE_WP_BRIDGE_ELEMENTOR_BACKUPS', 10 );
}

function claude_wp_bridge_elementor_active() {
    return class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance );
}

// Resolves input['post_id'] to a post the current user may edit.
function claude_wp_bridge_elementor_target( $input ) {
    $post = get_post( intval( $input['post_id'] ?? 0 ) );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Post not found', [ 'status' => 404 ] );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'forbidden', 'You cannot edit this post', [ 'status' => 403 ] );
    }
    return $post;
}

function claude_wp_bridge_elementor_is_built( $post_id ) {
    return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
}

function claude_wp_bridge_elementor_elements( $post_id ) {
    $raw = get_post_meta( $post_id, '_elementor_data', true );
    if ( is_array( $raw ) ) {
        return $raw;
    }
    $data = ( is_string( $raw ) && $raw !== '' ) ? json_decode( $raw, true ) : [];
    return is_array( $data ) ? $data : [];
}

function claude_wp_bridge_elementor_count( array $elements ) {
    $count = 0;
    foreach ( $elements as $el ) {
        if ( ! is_array( $el ) ) continue;
        $count++;
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $count += claude_wp_bridge_elementor_count( $el['elements'] );
        }
    }
    return $count;
}

// Short readable preview of a widget's settings, for the outline.
function claude_wp_bridge_elementor_preview( array $settings ) {
    foreach ( [ 'title', 'editor', 'text', 'title_text', 'description_text', 'link_text', 'button_text', 'html' ] as $key ) {
        $value = $settings[ $key ] ?? null;
        // Elementor v4 atomic widgets wrap values as { "$$type": ..., "value": ... }.
        if ( is_array( $value ) && isset( $value['value'] ) && is_string( $value['value'] ) ) {
            $value = $value['value'];
        }
        if ( is_string( $value ) ) {
            $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
            if ( $text !== '' ) {
                return mb_substr( $text, 0, 120 );
            }
        }
    }
    if ( ! empty( $settings['image']['url'] ) && is_string( $settings['image']['url'] ) ) {
        return 'image: ' . $settings['image']['url'];
    }
    return '';
}

// Flat list of every element: id, type, nesting depth and a text preview.
function claude_wp_bridge_elementor_outline( array $elements, $depth = 0 ) {
    $outline = [];
    foreach ( $elements as $el ) {
        if ( ! is_array( $el ) ) continue;
        $settings  = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : [];
        $outline[] = [
            'id'    => (string) ( $el['id'] ?? '' ),
            'type'  => (string) ( $el['widgetType'] ?? ( $el['elType'] ?? '' ) ),
            'depth' => $depth,
            'text'  => claude_wp_bridge_elementor_preview( $settings ),
        ];
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $outline = array_merge( $outline, claude_wp_bridge_elementor_outline( $el['elements'], $depth + 1 ) );
        }
    }
    return $outline;
}

function claude_wp_bridge_elementor_find( array $elements, $element_id ) {
    foreach ( $elements as $el ) {
        if ( ! is_array( $el ) ) continue;
        if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
            return $el;
        }
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $found = claude_wp_bridge_elementor_find( $el['elements'], $element_id );
            if ( $found !== null ) {
                return $found;
            }
        }
    }
    return null;
}

// Merges $settings into the element with $element_id. Returns the previous
// values of the keys it touched, or null when the element does not exist.
function claude_wp_bridge_elementor_patch( array &$elements, $element_id, array $settings ) {
    foreach ( $elements as &$el ) {
        if ( ! is_array( $el ) ) continue;
        if ( (string) ( $el['id'] ?? '' ) === $element_id ) {
            $current  = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : [];
            $previous = [];
            foreach ( array_keys( $settings ) as $key ) {
                $previous[ $key ] = $current[ $key ] ?? null;
            }
            $el['settings'] = array_merge( $current, $settings );
            return $previous;
        }
        if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
            $previous = claude_wp_bridge_elementor_patch( $el['elements'], $element_id, $settings );
            if ( $previous !== null ) {
                return $previous;
            }
        }
    }
    unset( $el );
    return null;
}

// Stores the current _elementor_data as a backup row and prunes the oldest ones.
// Returns the backup's meta_id.
function claude_wp_bridge_elementor_backup( $post_id ) {
    global $wpdb;
    $raw = get_post_meta( $post_id, '_elementor_data', true );
    $meta_id = add_post_meta( $post_id, '_claude_elementor_backup', wp_slash( [
        'time' => current_time( 'mysql' ),
        'user' => wp_get_current_user()->user_login,
        'data' => is_array( $raw ) ? wp_json_encode( $raw ) : (string) $raw,
    ] ) );
    if ( ! $meta_id ) {
        return new WP_Error( 'backup_failed', 'Could not back up the current Elementor data; nothing was saved', [ 'status' => 500 ] );
    }
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
        $post_id,
        '_claude_elementor_backup'
    ) );
    foreach ( array_slice( $ids, CLAUDE_WP_BRIDGE_ELEMENTOR_BACKUPS ) as $old_id ) {
        delete_metadata_by_mid( 'post', $old_id );
    }
    return (int) $meta_id;
}

function claude_wp_bridge_elementor_backups( $post_id ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
        $post_id,
        '_claude_elementor_backup'
    ) );
    return array_map( function ( $row ) {
        $backup = maybe_unserialize( $row->meta_value );
        return [
            'backup_id' => (int) $row->meta_id,
            'time'      => (string) ( $backup['time'] ?? '' ),
            'user'      => (string) ( $backup['user'] ?? '' ),
            'bytes'     => strlen( (string) ( $backup['data'] ?? '' ) ),
        ];
    }, $rows );
}

// Regenerates Elementor CSS and purges LiteSpeed Cache. Templates (headers,
// footers, popups) appear on every page, so they flush the whole site.
function claude_wp_bridge_elementor_flush( $post_id = 0 ) {
    $flushed   = [];
    $site_wide = ! $post_id || get_post_type( $post_id ) === 'elementor_library';

    if ( claude_wp_bridge_elementor_active() ) {
        if ( ! $site_wide && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            \Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
            $flushed[] = 'elementor-post-css';
        } else {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $flushed[] = 'elementor-all-css';
        }
    }

    if ( ! $site_wide && has_action( 'litespeed_purge_post' ) ) {
        do_action( 'litespeed_purge_post', $post_id );
        $flushed[] = 'litespeed-post';
    } elseif ( has_action( 'litespeed_purge_all' ) ) {
        do_action( 'litespeed_purge_all' );
        $flushed[] = 'litespeed-all';
    }
    return $flushed;
}

// "include/archive/category/125" -> the array the theme builder's
// save_conditions() implodes back into that string; null if it is not one.
function claude_wp_bridge_elementor_parse_condition( $condition ) {
    $parts = array_pad( explode( '/', trim( (string) $condition, '/' ) ), 4, '' );
    if ( count( $parts ) > 4 || ! in_array( $parts[0], [ 'include', 'exclude' ], true ) || $parts[1] === '' ) {
        return null;
    }
    return [ 'type' => $parts[0], 'name' => $parts[1], 'sub_name' => $parts[2], 'sub_id' => $parts[3] ];
}

// Saves $elements (and, when given, the document settings) through Elementor
// after backing up the current data, then flushes caches. Reports the element
// count before and after, because Elementor drops widgets whose type is not
// registered (e.g. from an inactive plugin) and that loss would otherwise be silent.
function claude_wp_bridge_elementor_save( $post_id, array $elements, $settings = null ) {
    $backup_id = claude_wp_bridge_elementor_backup( $post_id );
    if ( is_wp_error( $backup_id ) ) {
        return $backup_id;
    }

    $document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
    if ( ! $document ) {
        return new WP_Error( 'elementor_document', 'Elementor could not load this post as a document', [ 'status' => 500 ] );
    }
    $data = [ 'elements' => $elements ];
    if ( $settings !== null ) {
        $data['settings'] = $settings;
    }
    // Only an explicit false is a refusal: Elementor Pro's loop document
    // overrides save() and drops the parent's return value, so a successful
    // save of a loop item returns null.
    if ( false === $document->save( $data ) ) {
        return new WP_Error( 'elementor_save_failed', 'Elementor refused to save the document', [ 'status' => 500 ] );
    }

    $sent   = claude_wp_bridge_elementor_count( $elements );
    $stored = claude_wp_bridge_elementor_count( claude_wp_bridge_elementor_elements( $post_id ) );
    $result = [
        'success'        => true,
        'post_id'        => $post_id,
        'backup_id'      => $backup_id,
        'elements_sent'  => $sent,
        'elements_saved' => $stored,
        'flushed'        => claude_wp_bridge_elementor_flush( $post_id ),
    ];
    if ( $stored !== $sent ) {
        $result['warning'] = "Elementor stored $stored elements but $sent were sent. Restore with claude/elementor-restore and backup_id $backup_id if content was lost.";
    }
    return $result;
}

add_action( 'wp_abilities_api_init', function () {

    // Abilities that write are annotated 'destructive' => false on purpose. The
    // REST run endpoint (WP 7.1) calls destructive abilities with DELETE and reads
    // their input from the query string, which the web server cuts off long before
    // a page, a theme file or a plugin upload fits. Non-destructive writes go
    // through POST with a JSON body, which has no such limit.

    // ───────────────────────────────────────
    // claude/list-pages
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/list-pages', [
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
    claude_wp_bridge_register( 'claude/get-page', [
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
    claude_wp_bridge_register( 'claude/update-page', [
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/list-theme-files
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/list-theme-files', [
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
    claude_wp_bridge_register( 'claude/get-theme-file', [
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
    claude_wp_bridge_register( 'claude/update-theme-file', [
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/upload-file
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/upload-file', [
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/list-plugins
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/list-plugins', [
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
    claude_wp_bridge_register( 'claude/manage-plugin', [
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/site-map
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/site-map', [
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

    // ───────────────────────────────────────
    // claude/elementor-list
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-list', [
        'label'       => 'List Elementor Documents',
        'description' => 'List every post built with Elementor — pages, posts and Elementor templates such as headers, footers and popups — with ID, title, post type, template type and status.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_type' => [ 'type' => 'string', 'description' => 'Optional: only this post type, e.g. "page" or "elementor_library"' ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'title'         => [ 'type' => 'string' ],
                    'post_type'     => [ 'type' => 'string' ],
                    'template_type' => [ 'type' => 'string' ],
                    'status'        => [ 'type' => 'string' ],
                    'url'           => [ 'type' => 'string' ],
                ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            // 'any' would skip elementor_library, which is excluded from search.
            $types = ! empty( $input['post_type'] )
                ? [ sanitize_key( $input['post_type'] ) ]
                : array_values( array_unique( array_merge( get_post_types( [ 'public' => true ] ), [ 'elementor_library' ] ) ) );
            $posts = get_posts( [
                'post_type'   => $types,
                'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
                'numberposts' => -1,
                'meta_key'    => '_elementor_edit_mode',
                'meta_value'  => 'builder',
                'orderby'     => 'ID',
                'order'       => 'ASC',
            ] );
            return array_map( function ( $p ) {
                return [
                    'id'            => $p->ID,
                    'title'         => $p->post_title,
                    'post_type'     => $p->post_type,
                    'template_type' => (string) get_post_meta( $p->ID, '_elementor_template_type', true ),
                    'status'        => $p->post_status,
                    'url'           => (string) get_permalink( $p->ID ),
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
    // claude/elementor-get
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-get', [
        'label'       => 'Get Elementor Data',
        'description' => 'Read the Elementor structure of a post. "outline" (default) lists every element with its id, type, depth and a text preview; "full" returns the complete elements JSON; element_id returns a single element with all its settings. Also lists the stored backups.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer', 'description' => 'Page, post or Elementor template ID' ],
                'element_id' => [ 'type' => 'string', 'description' => 'Optional: return only this element, with all its settings' ],
                'format'     => [
                    'type'        => 'string',
                    'enum'        => [ 'outline', 'full' ],
                    'default'     => 'outline',
                    'description' => 'outline = compact element list; full = complete elements JSON',
                ],
            ],
            'required'             => [ 'post_id' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            $post = claude_wp_bridge_elementor_target( $input );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            $elements = claude_wp_bridge_elementor_elements( $post->ID );
            $result   = [
                'post_id'              => $post->ID,
                'title'                => $post->post_title,
                'post_type'            => $post->post_type,
                'template_type'        => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
                'built_with_elementor' => claude_wp_bridge_elementor_is_built( $post->ID ),
                'elementor_version'    => (string) get_post_meta( $post->ID, '_elementor_version', true ),
                'element_count'        => claude_wp_bridge_elementor_count( $elements ),
                'source'               => (string) get_post_meta( $post->ID, '_elementor_source', true ),
                'page_settings'        => (array) get_post_meta( $post->ID, '_elementor_page_settings', true ),
                'conditions'           => is_array( $conditions = get_post_meta( $post->ID, '_elementor_conditions', true ) ) ? array_values( $conditions ) : [],
                'backups'              => claude_wp_bridge_elementor_backups( $post->ID ),
            ];

            if ( ! empty( $input['element_id'] ) ) {
                $element = claude_wp_bridge_elementor_find( $elements, (string) $input['element_id'] );
                if ( $element === null ) {
                    return new WP_Error( 'element_not_found', 'Element not found: ' . $input['element_id'], [ 'status' => 404 ] );
                }
                $result['element'] = $element;
            } elseif ( ( $input['format'] ?? 'outline' ) === 'full' ) {
                $result['elements'] = $elements;
            } else {
                $result['outline'] = claude_wp_bridge_elementor_outline( $elements );
            }
            return $result;
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-update-element
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-update-element', [
        'label'       => 'Update Elementor Element',
        'description' => 'Change the settings of one Elementor element (e.g. a heading text, an image, a button link) without resending the whole page. The given keys are merged into the element settings; other settings stay as they are. Backs up the page first and flushes caches.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer', 'description' => 'Page, post or Elementor template ID' ],
                'element_id' => [ 'type' => 'string', 'description' => 'Element id, from claude/elementor-get' ],
                'settings'   => [ 'type' => 'object', 'description' => 'Settings keys to set, e.g. {"title": "New heading"}' ],
            ],
            'required'             => [ 'post_id', 'element_id', 'settings' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            if ( ! claude_wp_bridge_elementor_active() ) {
                return new WP_Error( 'elementor_missing', 'Elementor is not active on this site', [ 'status' => 409 ] );
            }
            $post = claude_wp_bridge_elementor_target( $input );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            if ( ! claude_wp_bridge_elementor_is_built( $post->ID ) ) {
                return new WP_Error( 'not_elementor', 'This post is not built with Elementor', [ 'status' => 409 ] );
            }

            $elements = claude_wp_bridge_elementor_elements( $post->ID );
            $previous = claude_wp_bridge_elementor_patch( $elements, (string) $input['element_id'], (array) $input['settings'] );
            if ( $previous === null ) {
                return new WP_Error( 'element_not_found', 'Element not found: ' . $input['element_id'], [ 'status' => 404 ] );
            }

            $result = claude_wp_bridge_elementor_save( $post->ID, $elements );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $result['previous'] = $previous;
            return $result;
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-save
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-save', [
        'label'       => 'Save Elementor Data',
        'description' => 'Replace the whole Elementor elements JSON of a post (e.g. to add, remove or reorder sections). Get the current JSON with claude/elementor-get format "full" first. Backs up the page first and flushes caches.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'       => [ 'type' => 'integer', 'description' => 'Page, post or Elementor template ID' ],
                'elements'      => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'object' ],
                    'description' => 'The complete elements array, as returned by claude/elementor-get format "full"',
                ],
                'allow_convert' => [
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Allow turning a post that is not built with Elementor into an Elementor post',
                ],
                'settings'      => [
                    'type'        => 'object',
                    'description' => 'Optional: document (page) settings, e.g. {"source": "post_taxonomy"} for a loop item. Replaces the stored settings, so send the complete set from claude/elementor-get.',
                ],
            ],
            'required'             => [ 'post_id', 'elements' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            if ( ! claude_wp_bridge_elementor_active() ) {
                return new WP_Error( 'elementor_missing', 'Elementor is not active on this site', [ 'status' => 409 ] );
            }
            $post = claude_wp_bridge_elementor_target( $input );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            if ( ! claude_wp_bridge_elementor_is_built( $post->ID ) ) {
                if ( empty( $input['allow_convert'] ) ) {
                    return new WP_Error( 'not_elementor', 'This post is not built with Elementor; pass allow_convert: true to convert it', [ 'status' => 409 ] );
                }
                update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
                if ( ! get_post_meta( $post->ID, '_elementor_template_type', true ) ) {
                    update_post_meta( $post->ID, '_elementor_template_type', $post->post_type === 'page' ? 'wp-page' : 'wp-post' );
                }
            }
            return claude_wp_bridge_elementor_save(
                $post->ID,
                (array) $input['elements'],
                isset( $input['settings'] ) ? (array) $input['settings'] : null
            );
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-restore
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-restore', [
        'label'       => 'Restore Elementor Backup',
        'description' => 'Put back a backup taken before an earlier claude/elementor-* write. Without backup_id it restores the most recent one. The restore itself is backed up first, so it can be undone the same way.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'   => [ 'type' => 'integer', 'description' => 'Page, post or Elementor template ID' ],
                'backup_id' => [ 'type' => 'integer', 'description' => 'Optional: backup to restore, from claude/elementor-get' ],
            ],
            'required'             => [ 'post_id' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            if ( ! claude_wp_bridge_elementor_active() ) {
                return new WP_Error( 'elementor_missing', 'Elementor is not active on this site', [ 'status' => 409 ] );
            }
            $post = claude_wp_bridge_elementor_target( $input );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            $backup_id = intval( $input['backup_id'] ?? 0 );
            if ( ! $backup_id ) {
                $backups = claude_wp_bridge_elementor_backups( $post->ID );
                if ( ! $backups ) {
                    return new WP_Error( 'no_backup', 'This post has no backups', [ 'status' => 404 ] );
                }
                $backup_id = $backups[0]['backup_id'];
            }

            $meta = get_metadata_by_mid( 'post', $backup_id );
            if ( ! $meta || (int) $meta->post_id !== $post->ID || $meta->meta_key !== '_claude_elementor_backup' ) {
                return new WP_Error( 'backup_not_found', "Backup $backup_id does not belong to this post", [ 'status' => 404 ] );
            }
            $raw      = (string) ( $meta->meta_value['data'] ?? '' );
            $elements = $raw === '' ? [] : json_decode( $raw, true );
            if ( ! is_array( $elements ) ) {
                return new WP_Error( 'backup_corrupt', "Backup $backup_id does not contain valid Elementor data", [ 'status' => 500 ] );
            }

            $result = claude_wp_bridge_elementor_save( $post->ID, $elements );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $result['restored_from'] = $backup_id;
            return $result;
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-create
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-create', [
        'label'       => 'Create Elementor Document',
        'description' => 'Create an empty Elementor document: a page ("wp-page"), a post ("wp-post") or a template such as "loop-item", "archive", "single-post", "header", "footer" or "section". For a loop item, "source" sets what it loops over: "post" (the default) or "post_taxonomy" (categories and tags). Fill it afterwards with claude/elementor-save.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'template_type' => [ 'type' => 'string', 'description' => 'Elementor document type, e.g. "wp-page", "loop-item" or "archive"' ],
                'title'         => [ 'type' => 'string', 'description' => 'Title of the new document' ],
                'status'        => [
                    'type'        => 'string',
                    'enum'        => [ 'draft', 'publish', 'private' ],
                    'default'     => 'draft',
                    'description' => 'Post status of the new document',
                ],
                'source'        => [ 'type' => 'string', 'description' => 'Optional, loop items only: "post" or "post_taxonomy"' ],
            ],
            'required'             => [ 'template_type', 'title' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            if ( ! claude_wp_bridge_elementor_active() ) {
                return new WP_Error( 'elementor_missing', 'Elementor is not active on this site', [ 'status' => 409 ] );
            }
            $document = \Elementor\Plugin::$instance->documents->create( sanitize_key( $input['template_type'] ), [
                'post_title'  => sanitize_text_field( $input['title'] ),
                'post_status' => $input['status'] ?? 'draft',
            ] );
            if ( is_wp_error( $document ) ) {
                return $document;
            }
            if ( ! $document ) {
                return new WP_Error( 'elementor_create_failed', 'Elementor could not create the document', [ 'status' => 500 ] );
            }

            $post_id = $document->get_main_id();
            if ( ! empty( $input['source'] ) ) {
                // Loop documents store the source in _elementor_source when it
                // arrives in the settings of a save.
                $document->save( [ 'settings' => [ 'source' => sanitize_key( $input['source'] ) ] ] );
            }
            return [
                'success'       => true,
                'post_id'       => $post_id,
                'post_type'     => get_post_type( $post_id ),
                'template_type' => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
                'source'        => (string) get_post_meta( $post_id, '_elementor_source', true ),
                'status'        => get_post_status( $post_id ),
                'url'           => (string) get_permalink( $post_id ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-set-conditions
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-set-conditions', [
        'label'       => 'Set Theme Builder Conditions',
        'description' => 'Set where a theme-builder template (header, footer, archive, single…) is displayed. Each condition is "include|exclude/<name>/<sub_name>/<id>", e.g. "include/archive/category/125" or "include/archive/any_child_of_category/125". The list replaces the current one; an empty list removes them all, which takes the template off the site. Flushes the whole page cache.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer', 'description' => 'Theme-builder template ID' ],
                'conditions' => [
                    'type'        => 'array',
                    'items'       => [ 'type' => 'string' ],
                    'description' => 'Conditions such as "include/archive/category/125"; empty to remove them all',
                ],
            ],
            'required'             => [ 'post_id', 'conditions' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [ 'type' => 'object' ],
        'execute_callback' => function ( $input ) {
            if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
                return new WP_Error( 'theme_builder_missing', 'The Elementor Pro theme builder is not active on this site', [ 'status' => 409 ] );
            }
            $post = claude_wp_bridge_elementor_target( $input );
            if ( is_wp_error( $post ) ) {
                return $post;
            }

            $parsed = [];
            foreach ( (array) $input['conditions'] as $condition ) {
                $one = claude_wp_bridge_elementor_parse_condition( $condition );
                if ( $one === null ) {
                    return new WP_Error( 'invalid_condition', "Invalid condition: $condition", [ 'status' => 400 ] );
                }
                $parsed[] = $one;
            }

            $previous = get_post_meta( $post->ID, '_elementor_conditions', true );
            \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->save_conditions( $post->ID, $parsed );
            $stored = get_post_meta( $post->ID, '_elementor_conditions', true );

            return [
                'success'    => true,
                'post_id'    => $post->ID,
                'previous'   => is_array( $previous ) ? array_values( $previous ) : [],
                'conditions' => is_array( $stored ) ? array_values( $stored ) : [],
                'flushed'    => claude_wp_bridge_elementor_flush( 0 ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

    // ───────────────────────────────────────
    // claude/elementor-flush
    // ───────────────────────────────────────
    claude_wp_bridge_register( 'claude/elementor-flush', [
        'label'       => 'Flush Elementor and Page Cache',
        'description' => 'Regenerate Elementor CSS and purge LiteSpeed Cache. With post_id it flushes that post (or the whole site if it is an Elementor template); without it, the whole site. The claude/elementor-* writes already do this.',
        'category'    => 'site',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => 'Optional: flush only this post' ],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'flushed' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            $post_id = intval( $input['post_id'] ?? 0 );
            if ( $post_id ) {
                $post = claude_wp_bridge_elementor_target( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
            }
            return [ 'flushed' => claude_wp_bridge_elementor_flush( $post_id ) ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_pages' ),
        'meta' => [
            'mcp'          => [ 'public' => true ],
            'show_in_rest' => true,
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
        ],
    ] );

} );

// ───────────────────────────────────────
// Settings screen: Settings → Claude WP Bridge
// ───────────────────────────────────────
//
// Creates the Application Password Claude Code connects with and shows it once,
// ready to paste into the .env file on that computer, so it never has to travel
// through a chat. WordPress keeps only its hash; this plugin stores nothing.
// Also lists and revokes Application Passwords and switches the tool groups.

const CLAUDE_WP_BRIDGE_APP_PREFIX = 'Claude WP Bridge';

add_action( 'admin_menu', function () {
    add_options_page( 'Claude WP Bridge', 'Claude WP Bridge', 'manage_options', 'claude-wp-bridge', 'claude_wp_bridge_settings_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=claude-wp-bridge' ) ) . '">' . esc_html__( 'Settings', 'claude-wp-bridge' ) . '</a>' );
    return $links;
} );

// ───────────────────────────────────────
// Translations
// ───────────────────────────────────────
//
// The screen's strings use the text domain "claude-wp-bridge". The plugin ships
// its Spanish translation inline, so it stays a single file: it applies to any
// Spanish locale (es_AR, es_ES, es_MX…) unless a real translation — e.g. one made
// with Loco Translate — already translated the string.

add_filter( 'gettext_claude-wp-bridge', 'claude_wp_bridge_gettext', 10, 2 );

function claude_wp_bridge_gettext( $translation, $text ) {
    if ( $translation !== $text || strpos( determine_locale(), 'es' ) !== 0 ) {
        return $translation;
    }
    $spanish = claude_wp_bridge_spanish();
    return $spanish[ $text ] ?? $translation;
}

function claude_wp_bridge_spanish() {
    return [
        // Tool groups.
        'Read the site'                    => 'Leer el sitio',
        'Site map, pages, theme files, installed plugins and Elementor structure. Changes nothing.' => 'Mapa del sitio, páginas, archivos del tema, plugins instalados y estructura de Elementor. No cambia nada.',
        'Edit pages and Elementor'         => 'Editar páginas y Elementor',
        'Page content, Elementor pages and templates, display conditions and cache flushes. Every Elementor write is backed up first.' => 'Contenido de las páginas, páginas y plantillas de Elementor, condiciones de visualización y purga de caché. Antes de cada cambio en Elementor se guarda un respaldo.',
        'Activate and deactivate plugins'  => 'Activar y desactivar plugins',
        'Switch installed plugins on and off.' => 'Activa y desactiva los plugins instalados.',
        'Write code files'                 => 'Escribir archivos de código',
        'Theme files and uploads to plugins/ and themes/. Whoever holds an Application Password can then run PHP on this site: switch it on only while it is needed, e.g. to update this plugin.' => 'Archivos del tema y subidas a plugins/ y themes/. Con esto encendido, quien tenga una contraseña de aplicación puede ejecutar PHP en el sitio: enciéndelo solo mientras haga falta, por ejemplo para actualizar este plugin.',
        // Plugin list link.
        'Settings'                         => 'Ajustes',
        // Notices.
        'Tool groups saved. They apply from the next request.' => 'Grupos guardados. Se aplican desde la próxima solicitud.',
        'Application Passwords are not available for this user or this site (WordPress requires HTTPS for them).' => 'Las contraseñas de aplicación no están disponibles para este usuario o este sitio (WordPress exige HTTPS).',
        'Access created.'                  => 'Acceso creado.',
        'You cannot manage the Application Passwords of that user.' => 'No puedes administrar las contraseñas de aplicación de ese usuario.',
        'Access revoked. Whoever used that password can no longer connect.' => 'Acceso revocado. Quien usaba esa contraseña ya no puede conectarse.',
        // New access.
        'Copy this now: it will not be shown again.' => 'Cópiala ahora: no se volverá a mostrar.',
        'Save it as the %s file Claude Code reads on your computer. Do not paste it into the chat.' => 'Guárdala como el archivo %s que lee Claude Code en tu equipo. No la pegues en el chat.',
        'Copy'                             => 'Copiar',
        'Copied'                           => 'Copiado',
        // Access section.
        'Access for Claude'                => 'Acceso para Claude',
        'Claude Code connects with an Application Password. Create one here and paste it into the %s file on the computer where Claude runs; WordPress stores only its hash, and this plugin stores nothing.' => 'Claude Code se conecta con una contraseña de aplicación. Créala aquí y pégala en el archivo %s del equipo donde se ejecuta Claude: WordPress guarda solo su huella (hash) y este plugin no guarda nada.',
        'Create access for Claude'         => 'Crear acceso para Claude',
        'For your user, %s.'               => 'Para tu usuario, %s.',
        'Application Passwords on this site' => 'Contraseñas de aplicación de este sitio',
        'None.'                            => 'Ninguna.',
        'User'                             => 'Usuario',
        'Name'                             => 'Nombre',
        'Created'                          => 'Creada',
        'Last used'                        => 'Último uso',
        'Last IP'                          => 'Última IP',
        '(created here)'                   => '(creada aquí)',
        'Never'                            => 'Nunca',
        'Revoke this Application Password? Whoever uses it will lose access.' => '¿Revocar esta contraseña de aplicación? Quien la use perderá el acceso.',
        'Revoke'                           => 'Revocar',
        // Tool groups section.
        'Tool groups'                      => 'Grupos de herramientas',
        'A group that is off is not registered: its tools cannot be listed or run, whatever password is used.' => 'Un grupo apagado no se registra: sus herramientas no se pueden listar ni ejecutar, con ninguna contraseña.',
        'On'                               => 'Encendido',
        'Save tool groups'                 => 'Guardar grupos',
        // Status section.
        'Status'                           => 'Estado',
        'Abilities API'                    => 'API de habilidades (Abilities)',
        'Available'                        => 'Disponible',
        'Missing (needs WordPress 7.0+)'   => 'No disponible (requiere WordPress 7.0 o superior)',
        'Active'                           => 'Activo',
        'Not active (only needed for the MCP route; the REST route works without it)' => 'No activo (solo hace falta para la conexión por MCP; la conexión por REST funciona sin él)',
        'Not active'                       => 'No activo',
        'Claude tools active'              => 'Herramientas de Claude activas',
        '%1$d of %2$d'                     => '%1$d de %2$d',
        'REST endpoint'                    => 'Endpoint REST',
    ];
}

// Handles the three forms of the screen. Returns [ notice, new .env text or null ].
function claude_wp_bridge_settings_actions() {
    $action = sanitize_key( $_POST['claude_wp_bridge_action'] ?? '' );
    if ( ! in_array( $action, [ 'groups', 'create', 'revoke' ], true ) ) {
        return [ null, null ];
    }
    check_admin_referer( 'claude_wp_bridge_' . $action );

    if ( $action === 'groups' ) {
        $posted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ?? [] ) );
        $saved  = [];
        foreach ( array_keys( claude_wp_bridge_groups() ) as $key ) {
            $saved[ $key ] = in_array( $key, $posted, true );
        }
        update_option( 'claude_wp_bridge_groups', $saved, false );
        return [ [ 'success', __( 'Tool groups saved. They apply from the next request.', 'claude-wp-bridge' ) ], null ];
    }

    if ( $action === 'create' ) {
        $user = wp_get_current_user();
        if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
            return [ [ 'error', __( 'Application Passwords are not available for this user or this site (WordPress requires HTTPS for them).', 'claude-wp-bridge' ) ], null ];
        }
        $created = WP_Application_Passwords::create_new_application_password( $user->ID, [
            'name' => CLAUDE_WP_BRIDGE_APP_PREFIX . ' – ' . wp_date( 'Y-m-d H:i' ),
        ] );
        if ( is_wp_error( $created ) ) {
            return [ [ 'error', $created->get_error_message() ], null ];
        }
        $env = 'WP_URL=' . untrailingslashit( home_url() ) . "\n"
            . 'WP_USER=' . $user->user_login . "\n"
            . 'WP_APP_PASSWORD="' . WP_Application_Passwords::chunk_password( $created[0] ) . "\"\n";
        return [ [ 'success', __( 'Access created.', 'claude-wp-bridge' ) ], $env ];
    }

    $user_id = (int) ( $_POST['user_id'] ?? 0 );
    $uuid    = sanitize_text_field( wp_unslash( $_POST['uuid'] ?? '' ) );
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return [ [ 'error', __( 'You cannot manage the Application Passwords of that user.', 'claude-wp-bridge' ) ], null ];
    }
    $deleted = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
    if ( is_wp_error( $deleted ) ) {
        return [ [ 'error', $deleted->get_error_message() ], null ];
    }
    return [ [ 'success', __( 'Access revoked. Whoever used that password can no longer connect.', 'claude-wp-bridge' ) ], null ];
}

function claude_wp_bridge_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    list( $notice, $new_env ) = claude_wp_bridge_settings_actions();

    $version = get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'];
    $groups  = claude_wp_bridge_groups();
    $enabled = claude_wp_bridge_enabled_groups();
    $page    = admin_url( 'options-general.php?page=claude-wp-bridge' );
    $env_tag = '<code>.env</code>';

    $active = [];
    if ( function_exists( 'wp_get_abilities' ) ) {
        foreach ( wp_get_abilities() as $ability ) {
            $name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : (string) $ability;
            if ( strpos( $name, 'claude/' ) === 0 ) {
                $active[] = $name;
            }
        }
    }
    $all_tools = count( array_merge( ...array_values( wp_list_pluck( $groups, 'abilities' ) ) ) );

    $rows = [];
    $meta = class_exists( 'WP_Application_Passwords' ) ? WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS : '';
    foreach ( $meta ? get_users( [ 'meta_key' => $meta ] ) : [] as $user ) {
        foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $item ) {
            $rows[] = [ 'user' => $user, 'item' => $item ];
        }
    }
    usort( $rows, function ( $a, $b ) {
        return (int) $b['item']['created'] <=> (int) $a['item']['created'];
    } );
    $when = function ( $timestamp ) {
        return $timestamp ? wp_date( 'Y-m-d H:i', (int) $timestamp ) : __( 'Never', 'claude-wp-bridge' );
    };
    ?>
    <div class="wrap">
        <h1>Claude WP Bridge <span style="font-size:13px;color:#646970;">v<?php echo esc_html( $version ); ?></span></h1>

        <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div>
        <?php endif; ?>

        <?php if ( $new_env ) : ?>
            <div class="notice notice-warning" style="padding:12px 16px;">
                <p>
                    <strong><?php esc_html_e( 'Copy this now: it will not be shown again.', 'claude-wp-bridge' ); ?></strong>
                    <?php printf( esc_html__( 'Save it as the %s file Claude Code reads on your computer. Do not paste it into the chat.', 'claude-wp-bridge' ), $env_tag ); ?>
                </p>
                <textarea id="claude-wp-bridge-env" readonly rows="4" style="width:100%;max-width:640px;font-family:monospace;"><?php echo esc_textarea( $new_env ); ?></textarea>
                <p>
                    <button type="button" class="button button-primary" data-copied="<?php echo esc_attr__( 'Copied', 'claude-wp-bridge' ); ?>" onclick="var t=document.getElementById('claude-wp-bridge-env');var b=this;t.select();(navigator.clipboard?navigator.clipboard.writeText(t.value):Promise.reject()).then(function(){b.textContent=b.dataset.copied;},function(){document.execCommand('copy');b.textContent=b.dataset.copied;});"><?php esc_html_e( 'Copy', 'claude-wp-bridge' ); ?></button>
                </p>
            </div>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Access for Claude', 'claude-wp-bridge' ); ?></h2>
        <p><?php printf( esc_html__( 'Claude Code connects with an Application Password. Create one here and paste it into the %s file on the computer where Claude runs; WordPress stores only its hash, and this plugin stores nothing.', 'claude-wp-bridge' ), $env_tag ); ?></p>
        <form method="post" action="<?php echo esc_url( $page ); ?>">
            <?php wp_nonce_field( 'claude_wp_bridge_create' ); ?>
            <input type="hidden" name="claude_wp_bridge_action" value="create">
            <?php submit_button( __( 'Create access for Claude', 'claude-wp-bridge' ), 'primary', 'submit', false ); ?>
            <span class="description"><?php echo esc_html( sprintf( __( 'For your user, %s.', 'claude-wp-bridge' ), wp_get_current_user()->user_login ) ); ?></span>
        </form>

        <h3><?php esc_html_e( 'Application Passwords on this site', 'claude-wp-bridge' ); ?></h3>
        <?php if ( ! $rows ) : ?>
            <p><?php esc_html_e( 'None.', 'claude-wp-bridge' ); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:980px;">
                <thead><tr>
                    <th><?php esc_html_e( 'User', 'claude-wp-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'claude-wp-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'claude-wp-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Last used', 'claude-wp-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Last IP', 'claude-wp-bridge' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) : $item = $row['item']; ?>
                    <tr>
                        <td><?php echo esc_html( $row['user']->user_login ); ?></td>
                        <td>
                            <?php echo esc_html( $item['name'] ); ?>
                            <?php if ( strpos( $item['name'], CLAUDE_WP_BRIDGE_APP_PREFIX ) === 0 ) : ?>
                                <span class="description"><?php esc_html_e( '(created here)', 'claude-wp-bridge' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $when( $item['created'] ) ); ?></td>
                        <td><?php echo esc_html( $when( $item['last_used'] ?? null ) ); ?></td>
                        <td><?php echo esc_html( $item['last_ip'] ?? '' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( $page ); ?>" data-confirm="<?php echo esc_attr__( 'Revoke this Application Password? Whoever uses it will lose access.', 'claude-wp-bridge' ); ?>" onsubmit="return confirm(this.dataset.confirm);">
                                <?php wp_nonce_field( 'claude_wp_bridge_revoke' ); ?>
                                <input type="hidden" name="claude_wp_bridge_action" value="revoke">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr( $row['user']->ID ); ?>">
                                <input type="hidden" name="uuid" value="<?php echo esc_attr( $item['uuid'] ); ?>">
                                <?php submit_button( __( 'Revoke', 'claude-wp-bridge' ), 'delete small', 'submit', false ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Tool groups', 'claude-wp-bridge' ); ?></h2>
        <p><?php esc_html_e( 'A group that is off is not registered: its tools cannot be listed or run, whatever password is used.', 'claude-wp-bridge' ); ?></p>
        <form method="post" action="<?php echo esc_url( $page ); ?>">
            <?php wp_nonce_field( 'claude_wp_bridge_groups' ); ?>
            <input type="hidden" name="claude_wp_bridge_action" value="groups">
            <table class="form-table" role="presentation">
                <?php foreach ( $groups as $key => $group ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( $group['label'] ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="groups[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $enabled[ $key ] ) ); ?>>
                                <?php esc_html_e( 'On', 'claude-wp-bridge' ); ?>
                            </label>
                            <p class="description"><?php echo esc_html( $group['description'] ); ?></p>
                            <p class="description"><?php echo implode( ' ', array_map( function ( $ability ) {
                                return '<code>' . esc_html( $ability ) . '</code>';
                            }, $group['abilities'] ) ); ?></p>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( __( 'Save tool groups', 'claude-wp-bridge' ) ); ?>
        </form>

        <h2><?php esc_html_e( 'Status', 'claude-wp-bridge' ); ?></h2>
        <table class="widefat striped" style="max-width:980px;">
            <tbody>
                <tr><td>WordPress</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                <tr><td><?php esc_html_e( 'Abilities API', 'claude-wp-bridge' ); ?></td><td><?php echo function_exists( 'wp_register_ability' ) ? esc_html__( 'Available', 'claude-wp-bridge' ) : esc_html__( 'Missing (needs WordPress 7.0+)', 'claude-wp-bridge' ); ?></td></tr>
                <tr><td>MCP Adapter</td><td><?php echo function_exists( 'is_plugin_active' ) && is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ? esc_html__( 'Active', 'claude-wp-bridge' ) : esc_html__( 'Not active (only needed for the MCP route; the REST route works without it)', 'claude-wp-bridge' ); ?></td></tr>
                <tr><td>Elementor</td><td><?php echo claude_wp_bridge_elementor_active() ? esc_html__( 'Active', 'claude-wp-bridge' ) : esc_html__( 'Not active', 'claude-wp-bridge' ); ?></td></tr>
                <tr><td><?php esc_html_e( 'Claude tools active', 'claude-wp-bridge' ); ?></td><td><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'claude-wp-bridge' ), count( $active ), $all_tools ) ); ?></td></tr>
                <tr><td><?php esc_html_e( 'REST endpoint', 'claude-wp-bridge' ); ?></td><td><code><?php echo esc_html( rest_url( 'wp-abilities/v1/abilities' ) ); ?></code></td></tr>
            </tbody>
        </table>
    </div>
    <?php
}
