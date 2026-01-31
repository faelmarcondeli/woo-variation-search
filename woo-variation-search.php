<?php
/**
 * Plugin Name: WooCommerce Variation Search
 * Description: Integra a busca AJAX do tema Flatsome com variações de produtos WooCommerce
 * Version: 1.1
 * Author: Rafael Moreno
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 6.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BUSCAR ATRIBUTO DE VARIAÇÃO USANDO A TABELA DE LOOKUP DO WOOCOMMERCE
 * Usa wp_wc_product_attributes_lookup para melhor performance
 */
add_filter( 'posts_where', function( $where, $query ) {
    global $wpdb;

    if ( is_admin() || ! $query->is_search() ) return $where;
    if ( $query->get('post_type') !== 'product' ) return $where;

    $search = $wpdb->esc_like( $query->get('s') );
    $search = esc_sql( $search );

    $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
    $terms_table = $wpdb->prefix . 'terms';

    $where = "
    AND (
        {$wpdb->posts}.post_title LIKE '%{$search}%'
        OR {$wpdb->posts}.ID IN (
            SELECT DISTINCT pal.product_or_parent_id
            FROM {$lookup_table} pal
            INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
            WHERE pal.taxonomy = 'pa_cores-de-tecidos'
            AND (
                t.name LIKE '%{$search}%'
                OR t.slug LIKE '%{$search}%'
            )
        )
    )
    ";

    return $where;
}, 10, 2 );


/**
 * USAR IMAGEM DA VARIAÇÃO ENCONTRADA
 * Busca a imagem da variação que corresponde ao termo pesquisado
 */
add_filter( 'woocommerce_product_get_image_id', function( $image_id, $product ) {
    global $wpdb;

    if ( ! is_search() ) return $image_id;
    if ( ! $product->is_type('variable') ) return $image_id;

    $search_term = sanitize_title( remove_accents( get_search_query() ) );
    $product_id = $product->get_id();

    $lookup_table = $wpdb->prefix . 'wc_product_attributes_lookup';
    $terms_table = $wpdb->prefix . 'terms';

    $variation_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT pal.product_id
        FROM {$lookup_table} pal
        INNER JOIN {$terms_table} t ON pal.term_id = t.term_id
        WHERE pal.product_or_parent_id = %d
        AND pal.taxonomy = 'pa_cores-de-tecidos'
        AND pal.is_variation_attribute = 1
        AND (
            t.name LIKE %s
            OR t.slug LIKE %s
        )
        LIMIT 1",
        $product_id,
        '%' . $wpdb->esc_like( $search_term ) . '%',
        '%' . $wpdb->esc_like( $search_term ) . '%'
    ) );

    if ( $variation_id ) {
        $variation_image = get_post_thumbnail_id( $variation_id );
        if ( $variation_image ) {
            return $variation_image;
        }
    }

    return $image_id;
}, 20, 2 );
