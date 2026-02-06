<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 2.4
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
    private $buffering_active = false;
    
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
    
    private function get_cached_variation( $variation_id ) {
        if ( ! isset( $this->variation_objects_cache[ $variation_id ] ) ) {
            $this->variation_objects_cache[ $variation_id ] = wc_get_product( $variation_id );
        }
        return $this->variation_objects_cache[ $variation_id ];
    }
    
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
     * Uses output buffering to capture shop loop HTML and duplicate product cards
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
            }
            
            $this->is_filter_results = true;
            add_filter( 'woocommerce_product_get_image', array( $this, 'filter_product_image_html' ), 999, 5 );
            add_filter( 'woocommerce_loop_product_link', array( $this, 'filter_product_link' ), 999, 2 );
            add_filter( 'post_type_link', array( $this, 'filter_product_permalink' ), 999, 2 );
            
            add_action( 'woocommerce_before_shop_loop', array( $this, 'start_shop_output_buffer' ), 999 );
            add_action( 'woocommerce_after_shop_loop', array( $this, 'end_shop_output_buffer' ), 1 );
        }
    }
    
    public function start_shop_output_buffer() {
        $this->buffering_active = true;
        ob_start();
    }
    
    public function end_shop_output_buffer() {
        if ( ! $this->buffering_active ) {
            return;
        }
        $this->buffering_active = false;
        $html = ob_get_clean();
        echo $this->duplicate_variation_cards( $html );
    }
    
    /**
     * Find a product card element in HTML by its post-{ID} CSS class
     * Returns array with 'html', 'start', 'end' or null
     */
    private function extract_product_card( $html, $product_id ) {
        $class_marker = 'post-' . $product_id;
        $pos = strpos( $html, $class_marker );
        if ( $pos === false ) {
            return null;
        }
        
        $before = substr( $html, 0, $pos );
        $tag_start = strrpos( $before, '<' );
        if ( $tag_start === false ) {
            return null;
        }
        
        $tag_fragment = substr( $html, $tag_start, 50 );
        if ( ! preg_match( '/^<(\w+)/', $tag_fragment, $match ) ) {
            return null;
        }
        $tag_name = strtolower( $match[1] );
        
        $depth = 0;
        $len = strlen( $html );
        $i = $tag_start;
        
        while ( $i < $len ) {
            $next_tag = strpos( $html, '<', $i + 1 );
            if ( $next_tag === false ) {
                break;
            }
            
            $chunk = substr( $html, $next_tag, strlen( $tag_name ) + 3 );
            
            if ( stripos( $chunk, '</' . $tag_name ) === 0 ) {
                if ( $depth === 0 ) {
                    $close_end = strpos( $html, '>', $next_tag );
                    if ( $close_end === false ) {
                        break;
                    }
                    $close_end++;
                    $card_html = substr( $html, $tag_start, $close_end - $tag_start );
                    return array(
                        'html'  => $card_html,
                        'start' => $tag_start,
                        'end'   => $close_end,
                    );
                }
                $depth--;
                $i = $next_tag + 1;
            } elseif ( stripos( $chunk, '<' . $tag_name ) === 0 && isset( $chunk[ strlen( $tag_name ) + 1 ] ) && in_array( $chunk[ strlen( $tag_name ) + 1 ], array( ' ', '>', "\t", "\n", "\r", '/' ) ) ) {
                $self_close_check = substr( $html, $next_tag, 200 );
                $gt_pos = strpos( $self_close_check, '>' );
                if ( $gt_pos !== false && substr( $self_close_check, $gt_pos - 1, 1 ) !== '/' ) {
                    $depth++;
                }
                $i = $next_tag + 1;
            } else {
                $i = $next_tag + 1;
            }
        }
        
        return null;
    }
    
    /**
     * Duplicate product cards in HTML for products with multiple matching variations
     */
    private function duplicate_variation_cards( $html ) {
        if ( empty( $this->filter_multi_variations ) ) {
            return $html;
        }
        
        $has_multi = false;
        foreach ( $this->filter_multi_variations as $product_id => $variation_ids ) {
            if ( count( $variation_ids ) > 1 ) {
                $has_multi = true;
                break;
            }
        }
        
        if ( ! $has_multi ) {
            return $html;
        }
        
        foreach ( $this->filter_multi_variations as $product_id => $variation_ids ) {
            if ( count( $variation_ids ) <= 1 ) {
                continue;
            }
            
            $card_info = $this->extract_product_card( $html, $product_id );
            if ( ! $card_info ) {
                continue;
            }
            
            $original_card = $card_info['html'];
            $product_permalink = get_permalink( $product_id );
            
            $first_variation = $this->get_cached_variation( $variation_ids[0] );
            $first_query_args = $first_variation ? $this->build_variation_query_args( $first_variation ) : array();
            $first_url = ! empty( $first_query_args ) ? add_query_arg( $first_query_args, $product_permalink ) : $product_permalink;
            
            $extra_cards = '';
            
            for ( $i = 1; $i < count( $variation_ids ); $i++ ) {
                $variation_id = $variation_ids[ $i ];
                $variation = $this->get_cached_variation( $variation_id );
                if ( ! $variation ) {
                    continue;
                }
                
                $cloned_card = $original_card;
                
                $image_id = $variation->get_image_id();
                if ( $image_id ) {
                    $img_src = wp_get_attachment_image_src( $image_id, 'woocommerce_thumbnail' );
                    if ( $img_src ) {
                        $cloned_card = preg_replace(
                            '/(<img[^>]*?\s)src\s*=\s*"[^"]*"/',
                            '$1src="' . esc_url( $img_src[0] ) . '"',
                            $cloned_card,
                            1
                        );
                        
                        $srcset = wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' );
                        if ( $srcset ) {
                            $cloned_card = preg_replace( '/srcset\s*=\s*"[^"]*"/', 'srcset="' . esc_attr( $srcset ) . '"', $cloned_card, 1 );
                        } else {
                            $cloned_card = preg_replace( '/\s*srcset\s*=\s*"[^"]*"/', '', $cloned_card, 1 );
                        }
                        
                        $cloned_card = preg_replace( '/data-src\s*=\s*"[^"]*"/', 'data-src="' . esc_url( $img_src[0] ) . '"', $cloned_card );
                        
                        if ( $srcset ) {
                            $cloned_card = preg_replace( '/data-srcset\s*=\s*"[^"]*"/', 'data-srcset="' . esc_attr( $srcset ) . '"', $cloned_card );
                        }
                    }
                }
                
                $query_args = $this->build_variation_query_args( $variation );
                if ( ! empty( $query_args ) ) {
                    $new_url = add_query_arg( $query_args, $product_permalink );
                    
                    $urls_to_replace = array(
                        esc_url( $first_url ),
                        esc_attr( $first_url ),
                        $first_url,
                        esc_url( $product_permalink ),
                        esc_attr( $product_permalink ),
                        $product_permalink,
                    );
                    $urls_to_replace = array_unique( $urls_to_replace );
                    
                    foreach ( $urls_to_replace as $old_url ) {
                        $escaped_old = preg_quote( $old_url, '/' );
                        $cloned_card = preg_replace(
                            '/href\s*=\s*"' . $escaped_old . '[^"]*"/',
                            'href="' . esc_url( $new_url ) . '"',
                            $cloned_card
                        );
                    }
                }
                
                $extra_cards .= $cloned_card;
            }
            
            if ( ! empty( $extra_cards ) ) {
                $html = substr_replace(
                    $html,
                    $original_card . $extra_cards,
                    $card_info['start'],
                    $card_info['end'] - $card_info['start']
                );
            }
        }
        
        return $html;
    }
    
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
    
    private function get_current_variation_for_product( $product_id ) {
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
    
    private function get_variation_image_url( $variation_id ) {
        $variation = $this->get_cached_variation( $variation_id );
        
        if ( ! $variation || ! $variation->get_image_id() ) {
            return '';
        }
        
        $image_data = wp_get_attachment_image_src( $variation->get_image_id(), 'woocommerce_thumbnail' );
        
        return $image_data ? $image_data[0] : '';
    }
    
    private function get_matched_variations( $search ) {
        global $wpdb;
        
        if ( empty( $search ) ) {
            return array();
        }
        
        $search_lower = mb_strtolower( $search );
        $search_sanitized = sanitize_title( remove_accents( $search ) );
        
        $search_patterns = array(
            '%' . $wpdb->esc_like( $search_lower ) . '%',
            '%' . $wpdb->esc_like( $search_sanitized ) . '%'
        );
        
        $matched = array();
        
        $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $terms_table = $wpdb->prefix . 'terms';
        
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$lookup_table}'" );
        
        if ( $table_exists ) {
            $results = $wpdb->get_results( $wpdb->prepare(
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
            
            if ( $results ) {
                foreach ( $results as $row ) {
                    if ( ! isset( $matched[ $row->parent_id ] ) ) {
                        $matched[ $row->parent_id ] = (int) $row->variation_id;
                    }
                }
            }
        } else {
            $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
            
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.post_parent as parent_id, p.ID as variation_id
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'attribute_pa_cores-de-tecidos'
                INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
                INNER JOIN {$terms_table} t ON pm.meta_value = t.slug OR pm.meta_value = t.name
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
            
            if ( $results ) {
                foreach ( $results as $row ) {
                    if ( ! isset( $matched[ $row->parent_id ] ) ) {
                        $matched[ $row->parent_id ] = (int) $row->variation_id;
                    }
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
        
        $search_escaped = '%' . $wpdb->esc_like( $search ) . '%';
        
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT tr.object_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'product_tag'
            AND t.name LIKE %s",
            $search_escaped
        ) );
    }
    
    public function custom_ajax_search() {
        $search = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
        
        if ( empty( $search ) ) {
            wp_send_json( array( 'suggestions' => array() ) );
        }
        
        self::$is_our_search = true;
        
        $matched_variations = $this->get_matched_variations( $search );
        $variation_product_ids = ! empty( $matched_variations ) ? array_keys( $matched_variations ) : array();
        
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
        
        $all_product_ids = array_unique( array_merge( $title_matches, $variation_product_ids, $tag_matches ) );
        
        if ( empty( $all_product_ids ) ) {
            wp_send_json( array( 'suggestions' => array() ) );
        }
        
        $in_stock_ids = $this->filter_in_stock_products( $all_product_ids );
        
        if ( empty( $in_stock_ids ) ) {
            wp_send_json( array( 'suggestions' => array() ) );
        }
        
        $suggestions = array();
        
        foreach ( $in_stock_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;
            
            $img_url = '';
            $permalink = $product->get_permalink();
            
            if ( isset( $matched_variations[ $product_id ] ) ) {
                $variation_id = $matched_variations[ $product_id ];
                $variation_img = $this->get_variation_image_url( $variation_id );
                
                if ( ! empty( $variation_img ) ) {
                    $img_url = $variation_img;
                }
                
                $variation = $this->get_cached_variation( $variation_id );
                $query_args = $this->build_variation_query_args( $variation );
                if ( ! empty( $query_args ) ) {
                    $permalink = add_query_arg( $query_args, $permalink );
                }
            }
            
            if ( empty( $img_url ) ) {
                $thumb_id = $product->get_image_id();
                if ( $thumb_id ) {
                    $img_data = wp_get_attachment_image_src( $thumb_id, 'woocommerce_thumbnail' );
                    if ( $img_data ) {
                        $img_url = $img_data[0];
                    }
                }
            }
            
            if ( empty( $img_url ) ) {
                $img_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
            }
            
            $suggestions[] = array(
                'value' => $product->get_name(),
                'url'   => $permalink,
                'img'   => $img_url,
                'price' => $product->get_price_html(),
            );
        }
        
        wp_send_json( array( 'suggestions' => $suggestions ) );
    }
}

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    WooVariationSearch::get_instance();
}, 100 );
