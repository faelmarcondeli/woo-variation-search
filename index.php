<?php
$plugin_info = file_get_contents('woo-variation-search.php');
$readme = file_get_contents('README.md');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WooCommerce Variation Search Plugin</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
            background: #f5f5f5;
            color: #333;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #7f54b3;
            border-bottom: 3px solid #7f54b3;
            padding-bottom: 15px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .info-box {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .code-block {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 5px;
            overflow-x: auto;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            line-height: 1.5;
        }
        .badge {
            display: inline-block;
            background: #7f54b3;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        .requirements {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>WooCommerce Variation Search Plugin</h1>
        
        <div class="info-box">
            <p><strong>Description:</strong> This WordPress plugin integrates the Flatsome theme AJAX search with WooCommerce product variations, allowing customers to search products by attribute values.</p>
        </div>

        <h2>Requirements</h2>
        <div class="requirements">
            <span class="badge">PHP 7.4+</span>
            <span class="badge">WordPress 4.0+</span>
            <span class="badge">WooCommerce 4.0+</span>
            <span class="badge">Flatsome Theme</span>
        </div>

        <h2>Features</h2>
        <ul>
            <li>Search products by variation attributes (e.g., color, size)</li>
            <li>Display variation-specific images in search results</li>
            <li>Compatible with Flatsome theme AJAX search</li>
            <li>Uses WooCommerce Product Attribute Lookup Table</li>
        </ul>

        <h2>Installation</h2>
        <ol>
            <li>Upload <code>woo-variation-search.php</code> to your WordPress <code>/wp-content/plugins/</code> directory</li>
            <li>Activate the plugin through the 'Plugins' menu in WordPress</li>
            <li>The plugin will automatically integrate with WooCommerce search</li>
        </ol>

        <h2>Plugin Code</h2>
        <pre class="code-block"><?php echo htmlspecialchars($plugin_info); ?></pre>

        <h2>Environment Info</h2>
        <div class="info-box">
            <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
            <p><strong>Server:</strong> <?php echo php_sapi_name(); ?></p>
        </div>
    </div>
</body>
</html>
