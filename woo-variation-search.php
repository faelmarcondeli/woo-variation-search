<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 1.8
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
    
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'template_redirect', array( $this, 'redirect_product_search' ) );
        add_action( 'woocommerce_product_query', array( $this, 'modify_shop_search_query' ), 10, 2 );
        
        remove_action( 'wp_ajax_flatsome_ajax_search_products', 'flatsome_ajax_search' );
        remove_action( 'wp_ajax_nopriv_flatsome_ajax_search_products', 'flatsome_ajax_search' );
        add_action( 'wp_ajax_flatsome_ajax_search_products', array( $this, 'custom_ajax_search' ), 5 );
        add_action( 'wp_ajax_nopriv_flatsome_ajax_search_products', array( $this, 'custom_ajax_search' ), 5 );
    }
    
    public function modify_shop_search_query( $query, $wc_query ) {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }
        
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        
        if ( empty( $search ) ) {
            return;
        }
        
        $matched_variations = $this->get_matched_variations( $search );
        
        if ( empty( $matched_variations ) ) {
            return;
        }
        
        $color_product_ids = array_keys( $matched_variations );
        
        $current_post_in = $query->get( 'post__in' );
        
        if ( ! empty( $current_post_in ) ) {
            $merged_ids = array_unique( array_merge( $current_post_in, $color_product_ids ) );
        } else {
            global $wpdb;
            $search_escaped = '%' . $wpdb->esc_like( $search ) . '%';
            
            $title_matches = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = 'publish' 
                AND post_title LIKE %s",
                $search_escaped
            ) );
            
            $merged_ids = array_unique( array_merge( $title_matches, $color_product_ids ) );
        }
        
        if ( ! empty( $merged_ids ) ) {
            $query->set( 'post__in', $merged_ids );
            $query->set( 's', '' );
        }
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
        
        $search_term = sanitize_title( remove_accents( $search ) );
        
        if ( empty( $search_term ) ) {
            return array();
        }
        
        $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $terms_table = $wpdb->prefix . 'terms';
        
        $color_products = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT pal.product_or_parent_id as parent_id, pal.product_id as variation_id
            FROM {$lookup_table} pal
            INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
            WHERE pal.taxonomy = 'pa_cores-de-tecidos'
            AND pal.is_variation_attribute = 1
            AND (
                t.name LIKE %s
                OR t.slug LIKE %s
            )",
            '%' . $wpdb->esc_like( $search_term ) . '%',
            '%' . $wpdb->esc_like( $search_term ) . '%'
        ) );
        
        $matched = array();
        
        if ( $color_products ) {
            foreach ( $color_products as $row ) {
                if ( ! isset( $matched[ $row->parent_id ] ) ) {
                    $matched[ $row->parent_id ] = (int) $row->variation_id;
                }
            }
        }
        
        return $matched;
    }
    
    private function get_variation_image_url( $product_id, $matched_variations ) {
        $image_id = 0;
        
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $image_id = $product->get_image_id();
        }
        
        if ( isset( $matched_variations[ $product_id ] ) ) {
            $variation_id = $matched_variations[ $product_id ];
            $variation_image_id = get_post_meta( $variation_id, '_thumbnail_id', true );
            if ( $variation_image_id && (int) $variation_image_id > 0 ) {
                $image_id = (int) $variation_image_id;
            }
        }
        
        if ( $image_id ) {
            $product_image = wp_get_attachment_image_src( $image_id );
            return $product_image ? $product_image[0] : '';
        }
        
        return '';
    }
    
    public function custom_ajax_search() {
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
        $products     = array();
        $posts        = array();
        $sku_products = array();
        $tag_products = array();
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

        $matched_variations = $this->get_matched_variations( $query );

        if ( $wc_activated ) {
            if ( function_exists( 'flatsome_ajax_search_get_products' ) ) {
                $products     = flatsome_ajax_search_get_products( 'product', $args );
                wp_reset_postdata();
                
                $sku_products = get_theme_mod( 'search_by_sku', 0 ) ? flatsome_ajax_search_get_products( 'sku', $args ) : array();
                wp_reset_postdata();
                
                $tag_products = get_theme_mod( 'search_by_product_tag', 0 ) ? flatsome_ajax_search_get_products( 'tag', $args ) : array();
                wp_reset_postdata();
            }
            
            if ( ! empty( $matched_variations ) ) {
                $variation_parent_ids = array_keys( $matched_variations );
                $variation_args = array(
                    'post_type'           => 'product',
                    'post_status'         => 'publish',
                    'posts_per_page'      => 50,
                    'post__in'            => $variation_parent_ids,
                    'ignore_sticky_posts' => 1,
                    'suppress_filters'    => true,
                );
                $variation_products = get_posts( $variation_args );
                wp_reset_postdata();
                
                if ( $variation_products ) {
                    $products = array_merge( $products, $variation_products );
                }
            }
        }

        if ( ( ! $wc_activated || get_theme_mod( 'search_result', 1 ) ) && ! isset( $_REQUEST['product_cat'] ) ) {
            if ( function_exists( 'flatsome_ajax_search_posts' ) ) {
                $posts = flatsome_ajax_search_posts( $args );
                wp_reset_postdata();
            }
        }

        $results = array_merge( $products, $sku_products, $tag_products, $posts );
        $added_ids = array();

        foreach ( $results as $key => $result_post ) {
            if ( $wc_activated && ( $result_post->post_type === 'product' || $result_post->post_type === 'product_variation' ) ) {
                $product = wc_get_product( $result_post->ID );
                
                if ( ! $product ) {
                    continue;
                }

                if ( $product->get_parent_id() ) {
                    $parent_product = wc_get_product( $product->get_parent_id() );
                    if ( $parent_product ) {
                        $visible = $parent_product->get_catalog_visibility() === 'visible' || $parent_product->get_catalog_visibility() === 'search';
                        if ( $parent_product->get_status() !== 'publish' || ! $visible ) {
                            continue;
                        }
                    }
                }

                $product_id = $product->get_id();
                
                if ( in_array( $product_id, $added_ids, true ) ) {
                    continue;
                }
                $added_ids[] = $product_id;
                
                $img_url = $this->get_variation_image_url( $product_id, $matched_variations );

                $suggestions[] = array(
                    'type'  => 'Product',
                    'id'    => $product_id,
                    'value' => $product->get_title(),
                    'url'   => $product->get_permalink(),
                    'img'   => $img_url,
                    'price' => $product->get_price_html(),
                );
            } else {
                if ( in_array( $result_post->ID, $added_ids, true ) ) {
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

        if ( empty( $suggestions ) ) {
            $no_results = $wc_activated ? __( 'No products found.', 'woocommerce' ) : __( 'No matches found', 'flatsome' );

            $suggestions[] = array(
                'id'    => -1,
                'value' => $no_results,
                'url'   => '',
            );
        }

        if ( function_exists( 'flatsome_unique_suggestions' ) ) {
            $suggestions = flatsome_unique_suggestions( array( $products, $sku_products, $tag_products ), $suggestions );
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
