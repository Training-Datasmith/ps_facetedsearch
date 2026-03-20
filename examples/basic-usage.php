<?php

declare(strict_types=1);

/**
 * Example: Working with the ps_facetedsearch PrestaShop module.
 *
 * ps_facetedsearch adds faceted (layered) navigation to category and search
 * result pages. Customers filter products by attributes, features, price
 * range, brand, availability, and condition. Filter state is AJAX-driven
 * and reflected in the URL hash for shareable filtered URLs.
 *
 * This file documents common integration patterns.
 */

// --- The module integrates via ProductSearchProvider ---
// It hooks into PrestaShop's search framework automatically on category pages.
// No direct PHP call is needed for standard use.

// --- Hook: moduleRoutes ---
// The module registers custom SEO-friendly URL patterns for filtered pages.
// e.g.: /women/dresses/color-blue/price-20-50

// --- Programmatic product search with filters ---
// The module exposes its search provider as a service. Use it in custom code:
//
// $searchProvider = $this->get('ps_facetedsearch.product_search_provider');
// $context        = new ProductSearchContext(Context::getContext());
//
// $query = new ProductSearchQuery();
// $query->setQueryType('category');
// $query->setIdCategory(3);
//
// $result = $searchProvider->runQuery($context, $query);
//
// foreach ($result->getProducts() as $product) {
//     echo $product['name'] . "\n";
// }

// --- AJAX filter update endpoint ---
// Filtering triggers an AJAX call to the module's front controller:
//   GET /module/ps_facetedsearch/search?id_category=3&q=color-blue
// Response is JSON containing updated product list HTML and available filters.

// --- Back Office configuration ---
// Modules > Faceted Search:
//   Per-category filter configuration:
//     - Which attributes to show as filters (color, size, material, etc.)
//     - Which features to show as filters
//     - Price range slider
//     - Brand (manufacturer) filter
//     - Availability (in stock) filter
//     - Condition filter
//   Global settings:
//     - Cache duration
//     - SEO URL generation

// --- Extending filter types ---
// Register custom filter types by extending the filter pipeline in:
//   src/Product/Search/ (Filter_Converter, Filter_Block, etc.)
