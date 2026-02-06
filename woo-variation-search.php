<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 2.3
 * Author: Custom
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 6.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooVariationSearch {
    
    private static $instance = null;
    private static $is_our_search = false;
    
    private $color_product_ids = array();
    private $matched_variations_cache = array();
    private $variation_objects_cache = array();
    private $is_search_results = false;
    private $is_filter_results = false;
    private $current_search_query = '';
    private $current_filter_term = '';
    private $filter_multi_variations = array();
    private $variation_queue = array();
    private $variation_render_index = array();
    
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'template_redirect', array( $this, 'redirect_product_search' ) );
        add_action( 'wp', array( $this, 'setup_search_image_filter' ) );
        add_action( 'woocommerce_product_query', array( $this, 'setup_tonalidade_filter' ), 998 );
        add_action( 'woocommerce_product_query', array( $this, 'modify_wc_product_query' ), 999 );
        
        if ( get_theme_mod( 'search_live_search', 1 ) ) {
            remove_action( 'wp_ajax_flatsome_ajax_search_products', 'flatsome_ajax_search' );
            remove_action( 'wp_ajax_nopriv_flatsome_ajax_search_products', 'flatsome_ajax_search' );
            add_action( 'wp_ajax_flatsome_ajax_search_products', array( $this, 'custom_ajax_search' ), 5 );
            add_action( 'wp_ajax_nopriv_flatsome_ajax_search_products', array( $this, 'custom_ajax_search' ), 5 );
        }
    }
    
    /**
     * Helper: Get cached variation object
     */
    private function get_cached_variation( $variation_id ) {
        if ( ! isset( $this->variation_objects_cache[ $variation_id ] ) ) {
            $this->variation_objects_cache[ $variation_id ] = wc_get_product( $variation_id );
        }
        return $this->variation_objects_cache[ $variation_id ];
    }
    
    /**
     * Helper: Build query args from variation attributes
     */
    private function build_variation_query_args( $variation ) {
        if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
            return array();
        }
        
        $attributes = $variation->get_attributes();
        
        if ( empty( $attributes ) ) {
            return array();
        }
        
        $query_args = array();
        foreach ( $attributes as $attribute_name => $attribute_value ) {
            if ( ! empty( $attribute_value ) ) {
                $query_args[ 'attribute_' . $attribute_name ] = $attribute_value;
            }
        }
        
        return $query_args;
    }
    
    /**
     * Helper: Add variation attributes to permalink
     */
    private function append_variation_params_to_url( $permalink, $product_id ) {
        $variation_id = $this->get_current_variation_for_product( $product_id );
        
        if ( ! $variation_id ) {
            return $permalink;
        }
        
        $variation = $this->get_cached_variation( $variation_id );
        $query_args = $this->build_variation_query_args( $variation );
        
        if ( ! empty( $query_args ) ) {
            $permalink = add_query_arg( $query_args, $permalink );
        }
        
        return $permalink;
    }
    
    /**
     * Helper: Get matched variations with caching
     */
    private function get_cached_matched_variations( $search ) {
        $cache_key = md5( $search );
        
        if ( $this->current_search_query === $cache_key && ! empty( $this->matched_variations_cache ) ) {
            return $this->matched_variations_cache;
        }
        
        $this->current_search_query = $cache_key;
        $this->matched_variations_cache = $this->get_matched_variations( $search );
        
        return $this->matched_variations_cache;
    }
    
    /**
     * Setup filter for tonalidades-de-tecidos widget
     * When a customer filters by tonalidade (e.g., "Azul"), show variation images matching that color
     * Uses the_posts to duplicate products and the_post to track which variation to render
     */
    public function setup_tonalidade_filter( $query ) {
        if ( $this->is_filter_results ) {
            return;
        }
        
        $filter_value = isset( $_GET['filter_tonalidades-de-tecidos'] ) ? sanitize_text_field( $_GET['filter_tonalidades-de-tecidos'] ) : '';
        
        if ( empty( $filter_value ) ) {
            return;
        }
        
        $this->current_filter_term = $filter_value;
        $this->filter_multi_variations = $this->get_variations_by_tonalidade_slug( $filter_value );
        
        if ( ! empty( $this->filter_multi_variations ) ) {
            foreach ( $this->filter_multi_variations as $product_id => $variation_ids ) {
                $this->matched_variations_cache[ $product_id ] = $variation_ids[0];
                $this->variation_queue[ $product_id ] = $variation_ids;
            }
            
            $this->is_filter_results = true;
            add_filter( 'the_posts', array( $this, 'duplicate_posts_for_variations' ), 999, 2 );
            add_action( 'the_post', array( $this, 'track_variation_render_index' ) );
            add_filter( 'woocommerce_product_get_image', array( $this, 'filter_product_image_html' ), 999, 5 );
            add_filter( 'woocommerce_loop_product_link', array( $this, 'filter_product_link' ), 999, 2 );
            add_filter( 'post_type_link', array( $this, 'filter_product_permalink' ), 999, 2 );
        }
    }
    
    /**
     * Duplicate posts for products with multiple matching variations
     * Each variation gets its own post entry in the loop
     */
    public function duplicate_posts_for_variations( $posts, $query ) {
        if ( empty( $this->filter_multi_variations ) || empty( $posts ) ) {
            return $posts;
        }
        
        $post_type = $query->get( 'post_type' );
        $is_product_query = ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type ) ) );
        
        if ( ! $is_product_query ) {
            return $posts;
        }
        
        $new_posts = array();
        
        foreach ( $posts as $post ) {
            $product_id = $post->ID;
            
            if ( isset( $this->filter_multi_variations[ $product_id ] ) && count( $this->filter_multi_variations[ $product_id ] ) > 1 ) {
                foreach ( $this->filter_multi_variations[ $product_id ] as $variation_id ) {
                    $cloned = clone $post;
                    $cloned->_wvs_variation_id = $variation_id;
                    $new_posts[] = $cloned;
                }
            } else {
                $new_posts[] = $post;
            }
        }
        
        $query->found_posts = count( $new_posts );
        $query->post_count = count( $new_posts );
        
        return $new_posts;
    }
    
    /**
     * Track which variation should render for duplicated product posts
     * Fires via the_post action each time WordPress sets up a post in the loop
     */
    public function track_variation_render_index( $post ) {
        $product_id = $post->ID;
        
        if ( ! isset( $this->variation_queue[ $product_id ] ) ) {
            return;
        }
        
        if ( isset( $post->_wvs_variation_id ) ) {
            $this->matched_variations_cache[ $product_id ] = (int) $post->_wvs_variation_id;
        } else {
            if ( ! isset( $this->variation_render_index[ $product_id ] ) ) {
                $this->variation_render_index[ $product_id ] = 0;
            } else {
                $this->variation_render_index[ $product_id ]++;
            }
            
            $idx = $this->variation_render_index[ $product_id ];
            $queue = $this->variation_queue[ $product_id ];
            
            if ( isset( $queue[ $idx ] ) ) {
                $this->matched_variations_cache[ $product_id ] = $queue[ $idx ];
            }
        }
    }
    
    /**
     * Get variations by tonalidade filter value (used by filter widget)
     * Works like search - uses LIKE to find variations with matching color names
     */
    private function get_variations_by_tonalidade_slug( $filter_value ) {
        global $wpdb;
        
        if ( empty( $filter_value ) ) {
            return array();
        }
        
        $terms = array_map( 'trim', explode( ',', $filter_value ) );
        
        $matched = array();
        $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $terms_table = $wpdb->prefix . 'terms';
        
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$lookup_table}'" );
        
        foreach ( $terms as $term ) {
            $term_original = mb_strtolower( trim( $term ) );
            $term_sanitized = sanitize_title( remove_accents( $term ) );
            
            if ( empty( $term_original ) ) {
                continue;
            }
            
            $search_patterns = array(
                '%' . $wpdb->esc_like( $term_original ) . '%',
                '%' . $wpdb->esc_like( $term_sanitized ) . '%'
            );
            
            if ( $table_exists ) {
                $tonalidade_products = $wpdb->get_results( $wpdb->prepare(
                    "SELECT pal.product_or_parent_id as parent_id, pal.product_id as variation_id
                    FROM {$lookup_table} pal
                    INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
                    INNER JOIN {$wpdb->postmeta} pm ON pal.product_id = pm.post_id AND pm.meta_key = '_stock_status'
                    WHERE pal.taxonomy = 'pa_cores-de-tecidos'
                    AND pal.is_variation_attribute = 1
                    AND pm.meta_value IN ('instock', 'onbackorder')
                    AND (
                        LOWER(t.name) LIKE %s
                        OR LOWER(t.name) LIKE %s
                        OR t.slug LIKE %s
                        OR t.slug LIKE %s
                    )
                    ORDER BY pal.product_or_parent_id, pal.product_id",
                    $search_patterns[0],
                    $search_patterns[1],
                    $search_patterns[0],
                    $search_patterns[1]
                ) );
                
                if ( $tonalidade_products ) {
                    foreach ( $tonalidade_products as $row ) {
                        $matched[ $row->parent_id ][] = (int) $row->variation_id;
                    }
                }
            } else {
                $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
                
                $variations = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.ID as variation_id, p.post_parent as parent_id
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm_attr ON p.ID = pm_attr.post_id AND pm_attr.meta_key = 'attribute_pa_cores-de-tecidos'
                    INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
                    INNER JOIN {$terms_table} t ON pm_attr.meta_value = t.slug OR pm_attr.meta_value = t.name
                    INNER JOIN {$term_taxonomy_table} tt ON t.term_id = tt.term_id AND tt.taxonomy = 'pa_cores-de-tecidos'
                    WHERE p.post_type = 'product_variation'
                    AND p.post_status = 'publish'
                    AND pm_stock.meta_value IN ('instock', 'onbackorder')
                    AND (
                        LOWER(t.name) LIKE %s
                        OR LOWER(t.name) LIKE %s
                        OR t.slug LIKE %s
                        OR t.slug LIKE %s
                    )
                    ORDER BY p.post_parent, p.ID",
                    $search_patterns[0],
                    $search_patterns[1],
                    $search_patterns[0],
                    $search_patterns[1]
                ) );
                
                if ( $variations ) {
                    foreach ( $variations as $row ) {
                        $matched[ $row->parent_id ][] = (int) $row->variation_id;
                    }
                }
            }
        }
        
        return $matched;
    }
    
    public function setup_search_image_filter() {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        
        if ( empty( $search ) ) {
            return;
        }
        
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }
        
        $this->matched_variations_cache = $this->get_cached_matched_variations( $search );
        
        if ( ! empty( $this->matched_variations_cache ) ) {
            $this->is_search_results = true;
            add_filter( 'woocommerce_product_get_image', array( $this, 'filter_product_image_html' ), 999, 5 );
            add_filter( 'woocommerce_loop_product_link', array( $this, 'filter_product_link' ), 999, 2 );
            add_filter( 'post_type_link', array( $this, 'filter_product_permalink' ), 999, 2 );
            add_action( 'wp_footer', array( $this, 'add_variation_link_script' ) );
        }
    }
    
    public function add_variation_link_script() {
        $variation_data = array();
        
        foreach ( $this->matched_variations_cache as $product_id => $variation_id ) {
            $variation = $this->get_cached_variation( $variation_id );
            $query_params = $this->build_variation_query_args( $variation );
            if ( ! empty( $query_params ) ) {
                $variation_data[ $product_id ] = $query_params;
            }
        }
        
        if ( empty( $variation_data ) ) {
            return;
        }
        ?>
        <script type="text/javascript">
        (function() {
            var variationData = <?php echo json_encode( $variation_data ); ?>;
            
            document.querySelectorAll('.products .product').forEach(function(productEl) {
                var link = productEl.querySelector('a.woocommerce-LoopProduct-link, a.plain');
                if (!link) return;
                
                var href = link.getAttribute('href');
                if (!href) return;
                
                for (var productId in variationData) {
                    if (productEl.classList.contains('post-' + productId)) {
                        var params = variationData[productId];
                        var queryString = Object.keys(params).map(function(key) {
                            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
                        }).join('&');
                        
                        var newHref = href + (href.indexOf('?') === -1 ? '?' : '&') + queryString;
                        
                        productEl.querySelectorAll('a').forEach(function(a) {
                            if (a.getAttribute('href') === href) {
                                a.setAttribute('href', newHref);
                            }
                        });
                        break;
                    }
                }
            });
        })();
        </script>
        <?php
    }
    
    public function filter_product_permalink( $permalink, $post ) {
        if ( ( ! $this->is_search_results && ! $this->is_filter_results ) || $post->post_type !== 'product' ) {
            return $permalink;
        }
        
        return $this->append_variation_params_to_url( $permalink, $post->ID );
    }
    
    public function filter_product_link( $permalink, $product ) {
        if ( ! $this->is_search_results && ! $this->is_filter_results ) {
            return $permalink;
        }
        
        return $this->append_variation_params_to_url( $permalink, $product->get_id() );
    }
    
    public function filter_product_image_html( $image, $product, $size, $attr, $placeholder ) {
        if ( ! $this->is_search_results && ! $this->is_filter_results ) {
            return $image;
        }
        
        $product_id = $product->get_id();
        
        $variation_id = $this->get_current_variation_for_product( $product_id );
        
        if ( ! $variation_id ) {
            return $image;
        }
        
        $variation = $this->get_cached_variation( $variation_id );
        
        if ( ! $variation ) {
            return $image;
        }
        
        $variation_image_id = $variation->get_image_id();
        
        if ( ! $variation_image_id ) {
            return $image;
        }
        
        $image_size = apply_filters( 'single_product_archive_thumbnail_size', $size );
        
        return wp_get_attachment_image( $variation_image_id, $image_size, false, $attr );
    }
    
    /**
     * Get current variation ID for a product
     * In filter mode with queue, returns the variation currently being rendered
     * In search mode, returns the first matched variation
     */
    private function get_current_variation_for_product( $product_id ) {
        global $post;
        
        if ( $this->is_filter_results && isset( $post->_wvs_variation_id ) ) {
            return (int) $post->_wvs_variation_id;
        }
        
        if ( isset( $this->matched_variations_cache[ $product_id ] ) ) {
            return $this->matched_variations_cache[ $product_id ];
        }
        
        return null;
    }
    
    public function modify_wc_product_query( $query ) {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        
        if ( empty( $search ) ) {
            return;
        }
        
        $matched_variations = $this->get_cached_matched_variations( $search );
        $this->color_product_ids = ! empty( $matched_variations ) ? array_keys( $matched_variations ) : array();
        
        global $wpdb;
        $search_escaped = '%' . $wpdb->esc_like( $search ) . '%';
        
        $title_matches = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status = 'publish' 
            AND post_title LIKE %s",
            $search_escaped
        ) );
        
        $tag_matches = $this->get_products_by_tag( $search );
        
        $merged_ids = array_unique( array_merge( $title_matches, $this->color_product_ids, $tag_matches ) );
        
        if ( empty( $merged_ids ) ) {
            return;
        }
        
        $in_stock_ids = $this->filter_in_stock_products( $merged_ids );
        
        if ( empty( $in_stock_ids ) ) {
            $query->set( 'post__in', array( 0 ) );
        } else {
            $query->set( 'post__in', $in_stock_ids );
        }
        
        $query->set( 's', '' );
        $query->set( 'orderby', 'post__in' );
        
        $tax_query = $query->get( 'tax_query' );
        if ( ! is_array( $tax_query ) ) {
            $tax_query = array();
        }
        $tax_query[] = array(
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => 'outofstock',
            'operator' => 'NOT IN',
        );
        $query->set( 'tax_query', $tax_query );
        
        add_filter( 'get_search_query', function( $s ) use ( $search ) {
            if ( empty( $s ) && ! empty( $search ) ) {
                return $search;
            }
            return $s;
        } );
    }
    
    private function filter_in_stock_products( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return array();
        }
        
        global $wpdb;
        
        $ids_placeholder = implode( ',', array_map( 'intval', $product_ids ) );
        
        $simple_in_stock = $wpdb->get_col(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status'
            WHERE p.ID IN ({$ids_placeholder})
            AND p.post_type = 'product'
            AND pm.meta_value IN ('instock', 'onbackorder')"
        );
        
        $variable_products = $wpdb->get_col(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.ID IN ({$ids_placeholder})
            AND p.post_type = 'product'
            AND tt.taxonomy = 'product_type'
            AND t.slug = 'variable'"
        );
        
        $in_stock = $simple_in_stock;
        
        foreach ( $variable_products as $product_id ) {
            if ( isset( $this->matched_variations_cache[ $product_id ] ) ) {
                $variation_id = $this->matched_variations_cache[ $product_id ];
                $stock_status = $wpdb->get_var( $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} 
                    WHERE post_id = %d AND meta_key = '_stock_status'",
                    $variation_id
                ) );
                
                if ( $stock_status === 'instock' || $stock_status === 'onbackorder' ) {
                    $in_stock[] = $product_id;
                }
            } else {
                $has_in_stock = $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stock_status'
                    WHERE p.post_parent = %d
                    AND p.post_type = 'product_variation'
                    AND p.post_status = 'publish'
                    AND pm.meta_value IN ('instock', 'onbackorder')
                    LIMIT 1",
                    $product_id
                ) );
                
                if ( $has_in_stock ) {
                    $in_stock[] = $product_id;
                }
            }
        }
        
        return array_unique( $in_stock );
    }
    
    public function redirect_product_search() {
        if ( is_search() && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product' ) {
            $search_query = get_search_query();
            
            if ( ! empty( $search_query ) && function_exists( 'wc_get_page_permalink' ) ) {
                $shop_url = wc_get_page_permalink( 'shop' );
                
                if ( $shop_url ) {
                    $redirect_url = add_query_arg( 's', urlencode( $search_query ), $shop_url );
                    
                    wp_safe_redirect( $redirect_url );
                    exit;
                }
            }
        }
    }
    
    private function get_matched_variations( $search ) {
        global $wpdb;
        
        $search_original = mb_strtolower( trim( $search ) );
        $search_sanitized = sanitize_title( remove_accents( $search ) );
        
        if ( empty( $search_original ) ) {
            return array();
        }
        
        $matched = array();
        $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $terms_table = $wpdb->prefix . 'terms';
        
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$lookup_table}'" );
        
        $search_patterns = array(
            '%' . $wpdb->esc_like( $search_original ) . '%',
            '%' . $wpdb->esc_like( $search_sanitized ) . '%'
        );
        
        if ( $table_exists ) {
            $color_products = $wpdb->get_results( $wpdb->prepare(
                "SELECT pal.product_or_parent_id as parent_id, pal.product_id as variation_id
                FROM {$lookup_table} pal
                INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
                INNER JOIN {$wpdb->postmeta} pm ON pal.product_id = pm.post_id AND pm.meta_key = '_stock_status'
                WHERE pal.taxonomy = 'pa_cores-de-tecidos'
                AND pal.is_variation_attribute = 1
                AND pm.meta_value IN ('instock', 'onbackorder')
                AND (
                    LOWER(t.name) LIKE %s
                    OR LOWER(t.name) LIKE %s
                    OR t.slug LIKE %s
                    OR t.slug LIKE %s
                )
                ORDER BY pal.product_or_parent_id, pal.product_id",
                $search_patterns[0],
                $search_patterns[1],
                $search_patterns[0],
                $search_patterns[1]
            ) );
            
            if ( $color_products ) {
                foreach ( $color_products as $row ) {
                    if ( ! isset( $matched[ $row->parent_id ] ) ) {
                        $matched[ $row->parent_id ] = (int) $row->variation_id;
                    }
                }
            }
            
            return $matched;
        }
        
        $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
        
        $variations = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID as variation_id, p.post_parent as parent_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_attr ON p.ID = pm_attr.post_id AND pm_attr.meta_key = 'attribute_pa_cores-de-tecidos'
            INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
            INNER JOIN {$terms_table} t ON pm_attr.meta_value = t.slug OR pm_attr.meta_value = t.name
            INNER JOIN {$term_taxonomy_table} tt ON t.term_id = tt.term_id AND tt.taxonomy = 'pa_cores-de-tecidos'
            WHERE p.post_type = 'product_variation'
            AND p.post_status = 'publish'
            AND pm_stock.meta_value IN ('instock', 'onbackorder')
            AND (
                LOWER(t.name) LIKE %s
                OR LOWER(t.name) LIKE %s
                OR t.slug LIKE %s
                OR t.slug LIKE %s
            )
            ORDER BY p.post_parent, p.ID",
            $search_patterns[0],
            $search_patterns[1],
            $search_patterns[0],
            $search_patterns[1]
        ) );
        
        if ( $variations ) {
            foreach ( $variations as $row ) {
                if ( ! isset( $matched[ $row->parent_id ] ) ) {
                    $matched[ $row->parent_id ] = (int) $row->variation_id;
                }
            }
        }
        
        return $matched;
    }
    
    private function get_products_by_tag( $search ) {
        global $wpdb;
        
        if ( empty( $search ) ) {
            return array();
        }
        
        $search_lower = mb_strtolower( $search );
        $search_escaped = '%' . $wpdb->esc_like( $search_lower ) . '%';
        
        $product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'product_tag'
            AND (LOWER(t.name) LIKE %s OR LOWER(t.slug) LIKE %s)",
            $search_escaped,
            $search_escaped
        ) );
        
        return $product_ids ? $product_ids : array();
    }
    
    private function get_variation_image_url( $product_id, $matched_variations ) {
        if ( isset( $matched_variations[ $product_id ] ) ) {
            $variation = $this->get_cached_variation( $matched_variations[ $product_id ] );
            if ( $variation && $variation->get_image_id() ) {
                $product_image = wp_get_attachment_image_src( $variation->get_image_id(), 'woocommerce_thumbnail' );
                if ( $product_image ) {
                    return $product_image[0];
                }
            }
        }
        
        $product = wc_get_product( $product_id );
        if ( $product && $product->get_image_id() ) {
            $product_image = wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_thumbnail' );
            return $product_image ? $product_image[0] : '';
        }
        
        return '';
    }
    
    private function get_variation_url( $product, $matched_variations ) {
        $permalink = $product->get_permalink();
        $product_id = $product->get_id();
        
        if ( ! isset( $matched_variations[ $product_id ] ) ) {
            return $permalink;
        }
        
        $variation = $this->get_cached_variation( $matched_variations[ $product_id ] );
        $query_args = $this->build_variation_query_args( $variation );
        
        if ( ! empty( $query_args ) ) {
            $permalink = add_query_arg( $query_args, $permalink );
        }
        
        return $permalink;
    }
    
    public function custom_ajax_search() {
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        header( 'X-LiteSpeed-Purge: no' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        
        global $post;
        $original_post = $post;
        
        self::$is_our_search = true;
        
        if ( ! isset( $_REQUEST['query'] ) ) {
            wp_send_json_error( array( 'message' => 'No search query provided' ) );
        }

        $query = apply_filters( 'flatsome_ajax_search_query', sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) );
        
        if ( empty( $query ) ) {
            wp_send_json( array( 'suggestions' => array() ) );
        }
        
        $wc_activated = function_exists( 'is_woocommerce_activated' ) ? is_woocommerce_activated() : class_exists( 'WooCommerce' );
        $suggestions  = array();

        $args = array(
            's'                   => $query,
            'orderby'             => '',
            'post_type'           => array(),
            'post_status'         => 'publish',
            'posts_per_page'      => 100,
            'ignore_sticky_posts' => 1,
            'post_password'       => '',
            'suppress_filters'    => true,
        );

        $matched_variations = $this->get_cached_matched_variations( $query );
        $added_ids = array();

        if ( $wc_activated ) {
            global $wpdb;
            $search_escaped = '%' . $wpdb->esc_like( $query ) . '%';
            
            $title_product_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish' 
                AND post_title LIKE %s",
                $search_escaped
            ) );
            
            $color_product_ids = ! empty( $matched_variations ) ? array_keys( $matched_variations ) : array();
            $tag_product_ids = $this->get_products_by_tag( $query );
            
            $merged_ids = array_unique( array_merge( $title_product_ids, $color_product_ids, $tag_product_ids ) );
            $in_stock_ids = $this->filter_in_stock_products( $merged_ids );
            
            foreach ( $in_stock_ids as $product_id ) {
                if ( in_array( $product_id, $added_ids, true ) ) {
                    continue;
                }
                
                $product = wc_get_product( $product_id );
                
                if ( ! $product || $product->get_status() !== 'publish' ) {
                    continue;
                }
                
                $added_ids[] = $product_id;
                
                $img_url = $this->get_variation_image_url( $product_id, $matched_variations );
                $product_url = $this->get_variation_url( $product, $matched_variations );

                $suggestions[] = array(
                    'type'  => 'Product',
                    'id'    => $product_id,
                    'value' => $product->get_title(),
                    'url'   => $product_url,
                    'img'   => $img_url,
                    'price' => $product->get_price_html(),
                );
                
                if ( count( $suggestions ) >= 20 ) {
                    break;
                }
            }
        }

        if ( ( ! $wc_activated || get_theme_mod( 'search_result', 1 ) ) && ! isset( $_REQUEST['product_cat'] ) ) {
            if ( function_exists( 'flatsome_ajax_search_posts' ) ) {
                $posts = flatsome_ajax_search_posts( $args );
                wp_reset_postdata();
                
                foreach ( $posts as $result_post ) {
                    if ( in_array( $result_post->ID, $added_ids, true ) ) {
                        continue;
                    }
                    
                    $title_lower = mb_strtolower( get_the_title( $result_post->ID ) );
                    if ( strpos( $title_lower, mb_strtolower( $query ) ) === false ) {
                        continue;
                    }
                    
                    $added_ids[] = $result_post->ID;
                    
                    $suggestions[] = array(
                        'type'  => 'Page',
                        'id'    => $result_post->ID,
                        'value' => get_the_title( $result_post->ID ),
                        'url'   => get_the_permalink( $result_post->ID ),
                        'img'   => get_the_post_thumbnail_url( $result_post->ID, 'thumbnail' ),
                        'price' => '',
                    );
                }
            }
        }

        if ( empty( $suggestions ) ) {
            $no_results = $wc_activated ? __( 'No products found.', 'woocommerce' ) : __( 'No matches found', 'flatsome' );

            $suggestions[] = array(
                'id'    => -1,
                'value' => $no_results,
                'url'   => '',
            );
        }

        if ( function_exists( 'flatsome_unique_suggestions' ) ) {
            $suggestions = flatsome_unique_suggestions( array(), $suggestions );
        }

        self::$is_our_search = false;
        $post = $original_post;
        wp_reset_postdata();
        
        wp_send_json( array( 'suggestions' => $suggestions ) );
    }
}

add_action( 'after_setup_theme', function() {
    WooVariationSearch::get_instance();
}, 100 );
