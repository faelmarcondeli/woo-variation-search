<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 1.4
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
    private $is_variation_search = false;
    private $processed_products = array();
    
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter( 'posts_where', array( $this, 'filter_search_where' ), 10, 2 );
        
        add_action( 'wp_ajax_flatsome_ajax_search_products', array( $this, 'before_flatsome_search' ), 1 );
        add_action( 'wp_ajax_nopriv_flatsome_ajax_search_products', array( $this, 'before_flatsome_search' ), 1 );
        
        add_filter( 'flatsome_ajax_search_products_args', array( $this, 'modify_search_args' ), 10, 2 );
    }
    
    public function before_flatsome_search() {
        $search = isset( $_REQUEST['query'] ) ? sanitize_text_field( $_REQUEST['query'] ) : '';
        
        if ( empty( $search ) ) {
            return;
        }
        
        $this->prepare_variation_search( $search );
        
        if ( ! empty( $this->matched_variations ) ) {
            $this->is_variation_search = true;
            add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image' ), 20, 2 );
        }
    }
    
    private function prepare_variation_search( $search ) {
        global $wpdb;
        
        $search_term = sanitize_title( remove_accents( $search ) );
        
        if ( empty( $search_term ) ) {
            return;
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
        
        $this->matched_variations = array();
        
        if ( $color_products ) {
            foreach ( $color_products as $row ) {
                if ( ! isset( $this->matched_variations[ $row->parent_id ] ) ) {
                    $this->matched_variations[ $row->parent_id ] = (int) $row->variation_id;
                }
            }
        }
    }
    
    public function modify_search_args( $args, $search_query ) {
        global $wpdb;
        
        if ( empty( $search_query ) ) {
            return $args;
        }
        
        $this->prepare_variation_search( $search_query );
        
        if ( ! empty( $this->matched_variations ) ) {
            $parent_ids = array_keys( $this->matched_variations );
            
            if ( isset( $args['post__in'] ) && ! empty( $args['post__in'] ) ) {
                $args['post__in'] = array_unique( array_merge( $args['post__in'], $parent_ids ) );
            } else {
                if ( ! isset( $args['post__in'] ) ) {
                    $args['post__in'] = $parent_ids;
                }
            }
            
            $this->is_variation_search = true;
            add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image' ), 20, 2 );
        }
        
        return $args;
    }
    
    public function filter_search_where( $where, $query ) {
        global $wpdb;

        if ( is_admin() && ! wp_doing_ajax() ) return $where;
        if ( ! $query->is_search() ) return $where;
        if ( $query->get('post_type') !== 'product' ) return $where;

        $search = $query->get('s');
        if ( empty( $search ) ) return $where;
        
        $this->prepare_variation_search( $search );
        
        $search_escaped = $wpdb->esc_like( $search );
        $search_escaped = esc_sql( $search_escaped );

        $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
        $terms_table = $wpdb->prefix . 'terms';

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

        if ( ! empty( $this->matched_variations ) ) {
            $this->is_variation_search = true;
            add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image' ), 20, 2 );
        }

        return $where;
    }
    
    public function filter_product_image( $image_id, $product ) {
        if ( ! $this->is_variation_search ) {
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
        
        if ( isset( $this->processed_products[ $product_id ] ) ) {
            return $this->processed_products[ $product_id ];
        }
        
        if ( ! isset( $this->matched_variations[ $product_id ] ) ) {
            return $image_id;
        }
        
        $variation_id = $this->matched_variations[ $product_id ];
        $variation_image = get_post_thumbnail_id( $variation_id );
        
        if ( $variation_image && $variation_image > 0 ) {
            $this->processed_products[ $product_id ] = $variation_image;
            return $variation_image;
        }
        
        $this->processed_products[ $product_id ] = $image_id;
        return $image_id;
    }
}

WooVariationSearch::get_instance();
