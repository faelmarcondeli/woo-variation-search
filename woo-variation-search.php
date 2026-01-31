<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 1.5
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
    
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter( 'posts_where', array( $this, 'filter_search_where' ), 10, 2 );
        add_filter( 'flatsome_ajax_search_function', array( $this, 'custom_flatsome_search' ), 10, 4 );
    }
    
    public function custom_flatsome_search( $query_function, $search_query, $args, $defaults ) {
        if ( ! isset( $args['post_type'] ) || $args['post_type'] !== 'product' ) {
            return $query_function;
        }
        
        $search = isset( $args['s'] ) ? $args['s'] : '';
        if ( empty( $search ) ) {
            return $query_function;
        }
        
        $this->prepare_variation_search( $search );
        
        if ( ! empty( $this->matched_variations ) ) {
            add_filter( 'post_thumbnail_id', array( $this, 'filter_thumbnail_id' ), 20, 2 );
        }
        
        return $query_function;
    }
    
    private function prepare_variation_search( $search ) {
        global $wpdb;
        
        $this->current_search_term = sanitize_title( remove_accents( $search ) );
        
        if ( empty( $this->current_search_term ) ) {
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
    }
    
    public function filter_thumbnail_id( $thumbnail_id, $post_id ) {
        if ( empty( $this->matched_variations ) ) {
            return $thumbnail_id;
        }
        
        $post_id = (int) $post_id;
        
        if ( ! isset( $this->matched_variations[ $post_id ] ) ) {
            return $thumbnail_id;
        }
        
        $variation_id = $this->matched_variations[ $post_id ];
        $variation_thumbnail = get_post_meta( $variation_id, '_thumbnail_id', true );
        
        if ( $variation_thumbnail && $variation_thumbnail > 0 ) {
            return (int) $variation_thumbnail;
        }
        
        return $thumbnail_id;
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
            add_filter( 'post_thumbnail_id', array( $this, 'filter_thumbnail_id' ), 20, 2 );
        }

        return $where;
    }
}

WooVariationSearch::get_instance();
