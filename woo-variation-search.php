/**
 * BUSCAR ATRIBUTO DE VARIAÇÃO (ex: COR) NA PESQUISA
 */
add_filter( 'posts_where', function( $where, $query ) {
	global $wpdb;

	if ( is_admin() || ! $query->is_search() ) return $where;
	if ( $query->get('post_type') !== 'product' ) return $where;

	$search = $wpdb->esc_like( $query->get('s') );
	$search = esc_sql( $search );

	$where = "
	AND (
		{$wpdb->posts}.post_title LIKE '%{$search}%'
		OR {$wpdb->posts}.ID IN (
			SELECT post_parent
			FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->postmeta} pm
				ON v.ID = pm.post_id
			WHERE v.post_type = 'product_variation'
			AND pm.meta_key = 'attribute_pa_cores-de-tecidos'
			AND pm.meta_value LIKE '%{$search}%'
		)
	)
	";

	return $where;
}, 10, 2 );


/**
 * USAR IMAGEM DA VARIAÇÃO ENCONTRADA
 */
add_filter( 'woocommerce_product_get_image_id', function( $image_id, $product ) {

	if ( ! is_search() ) return $image_id;
	if ( ! $product->is_type('variable') ) return $image_id;

	$term = sanitize_title( remove_accents( get_search_query() ) );

	foreach ( $product->get_children() as $variation_id ) {

		$color = get_post_meta( $variation_id, 'attribute_pa_cores-de-tecidos', true );

		if ( $color && stripos( $color, $term ) !== false ) {

			$img = get_post_thumbnail_id( $variation_id );

			if ( $img ) return $img;
		}
	}

	return $image_id;

}, 20, 2 );
