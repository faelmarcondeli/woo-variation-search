<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 1.3
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
    private $matched_variations = array();
    private $current_search_term = '';
    private $is_active_search = false;
    
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter( 'posts_where', array( $this, 'filter_search_where' ), 10, 2 );
        add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image' ), 20, 2 );
        add_action( 'loop_start', array( $this, 'on_loop_start' ) );
        add_action( 'loop_end', array( $this, 'on_loop_end' ) );
    }
    
    public function on_loop_start( $query ) {
        if ( $query->is_main_query() && $query->is_search() ) {
            $this->is_active_search = true;
        }
    }
    
    public function on_loop_end( $query ) {
        if ( $query->is_main_query() && $query->is_search() ) {
            $this->is_active_search = false;
            $this->matched_variations = array();
        }
    }
    
    public function filter_search_where( $where, $query ) {
        global $wpdb;

        if ( is_admin() || ! $query->is_search() ) return $where;
        if ( $query->get('post_type') !== 'product' ) return $where;
        if ( ! $query->is_main_query() ) return $where;

        $search = $query->get('s');
        if ( empty( $search ) ) return $where;
        
        $this->current_search_term = sanitize_title( remove_accents( $search ) );
        
        $search_escaped = $wpdb->esc_like( $search );
        $search_escaped = esc_sql( $search_escaped );

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
            '%' . $wpdb->esc_like( $this->current_search_term ) . '%',
            '%' . $wpdb->esc_like( $this->current_search_term ) . '%'
        ) );
        
        $this->matched_variations = array();
        
        if ( $color_products ) {
            foreach ( $color_products as $row ) {
                if ( ! isset( $this->matched_variations[ $row->parent_id ] ) ) {
                    $this->matched_variations[ $row->parent_id ] = (int) $row->variation_id;
                }
            }
        }

        $where = "
        AND (
            {$wpdb->posts}.post_title LIKE '%{$search_escaped}%'
            OR {$wpdb->posts}.ID IN (
                SELECT DISTINCT pal.product_or_parent_id
                FROM {$lookup_table} pal
                INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
                WHERE pal.taxonomy = 'pa_cores-de-tecidos'
                AND (
                    t.name LIKE '%{$search_escaped}%'
                    OR t.slug LIKE '%{$search_escaped}%'
                )
            )
        )
        ";

        return $where;
    }
    
    public function filter_product_image( $image_id, $product ) {
        if ( ! $this->is_active_search ) {
            return $image_id;
        }
        
        if ( empty( $this->matched_variations ) ) {
            return $image_id;
        }
        
        if ( ! $product || ! is_object( $product ) ) {
            return $image_id;
        }
        
        if ( ! method_exists( $product, 'is_type' ) || ! method_exists( $product, 'get_id' ) ) {
            return $image_id;
        }
        
        if ( ! $product->is_type('variable') ) {
            return $image_id;
        }
        
        $product_id = $product->get_id();
        
        if ( ! $product_id || $product_id <= 0 ) {
            return $image_id;
        }
        
        if ( ! isset( $this->matched_variations[ $product_id ] ) ) {
            return $image_id;
        }
        
        $variation_id = $this->matched_variations[ $product_id ];
        
        if ( ! $variation_id || $variation_id <= 0 ) {
            return $image_id;
        }
        
        $variation_image = get_post_thumbnail_id( $variation_id );
        
        if ( $variation_image && $variation_image > 0 ) {
            return $variation_image;
        }
        
        return $image_id;
    }
}

WooVariationSearch::get_instance();
