# WooCommerce Variation Search Plugin

## Overview
This is a WordPress plugin that integrates the Flatsome theme AJAX search with WooCommerce product variations. It allows customers to search products by attribute values (like color or size).

## Recent Changes
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
