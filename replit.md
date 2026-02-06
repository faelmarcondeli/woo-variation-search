# WooCommerce Variation Search Plugin

## Overview
This is a WordPress plugin that integrates the Flatsome theme AJAX search with WooCommerce product variations. It allows customers to search products by attribute values (like color or size).

## Recent Changes
- **2026-02-06**: Multi-variation server-side approach (Version 2.3)
  - Shows ALL matching variations per product as separate cards in the grid
  - E.g., "Laranja Claro" and "Laranja Escuro" both appear when filtering by "laranja"
  - Uses `the_posts` filter to duplicate posts with `_wvs_variation_id` tracking
  - Uses `the_post` action to update `matched_variations_cache` before each render
  - `get_current_variation_for_product()` reads variation ID from global `$post->_wvs_variation_id`
  - Removed JS-based card cloning (unreliable with AJAX filter updates)
  - LIKE-based matching on `pa_cores-de-tecidos` taxonomy
  - Removed page type restrictions (works on any page)

- **2026-02-05**: Added tonalidade filter support (Version 2.1)
  - Added support for `filter_tonalidades-de-tecidos` widget
  - When filtering by tonalidade (e.g., "Azul"), shows variation images matching that tone
  - Supports multiple tonalidades (comma-separated)
  - Works on shop, category, and tag pages

- **2026-02-05**: Optimized plugin code (Version 2.0)
  - Consolidated duplicate code into reusable helper methods
  - Added caching for matched variations and variation objects
  - Removed unused variables
  - Optimized get_variation_image_url() method
  - Improved overall code maintainability

## Project Structure
- `woo-variation-search.php` - The main WordPress plugin file
- `index.php` - Information page displaying plugin details
- `README.md` - Original documentation in Portuguese

## Requirements
- PHP 7.4+
- WordPress 4.0+
- WooCommerce 4.0+
- Flatsome Theme

## Running Locally
The project includes a simple PHP server that displays plugin information:
```bash
php -S 0.0.0.0:5000
```

## Installation (WordPress)
1. Upload `woo-variation-search.php` to `/wp-content/plugins/` directory
2. Activate through WordPress admin 'Plugins' menu
3. Plugin integrates automatically with WooCommerce search

## Features
- Search products by variation attributes
- Display variation-specific images in search results
- Compatible with Flatsome theme AJAX search
- Uses WooCommerce Product Attribute Lookup Table
