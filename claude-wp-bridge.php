<?php
/**
 * Plugin Name: Claude WP Bridge
 * Description: Exposes WordPress content, theme files, plugin management and Elementor page data as WordPress Abilities for Claude Code via MCP. Replaces Compulibra Manager and Compulibra Auto Upload.
 * Version:     1.3.0
 * Author:      Mariano Cappucci
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
    if ( ! $document->save( $data ) ) {
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
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
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
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

    // ───────────────────────────────────────
    // claude/elementor-list
    // ───────────────────────────────────────
    wp_register_ability( 'claude/elementor-list', [
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
    wp_register_ability( 'claude/elementor-get', [
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
    wp_register_ability( 'claude/elementor-update-element', [
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
    wp_register_ability( 'claude/elementor-save', [
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
    wp_register_ability( 'claude/elementor-restore', [
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
    wp_register_ability( 'claude/elementor-create', [
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
    wp_register_ability( 'claude/elementor-set-conditions', [
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
    wp_register_ability( 'claude/elementor-flush', [
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
