# Architecture: ps_facetedsearch

## Purpose

A PrestaShop module that adds faceted (layered) search/navigation to category and search
result pages. Customers can filter products by attributes, features, price range, brand,
availability, and condition using checkboxes and sliders.

## Directory Structure

```
ps_facetedsearch.php   # Main module class; registers hooks and services
src/
  Product/
    Search/              # Search providers, filters, and result processing
  Adapter/               # Adapters for different PS search implementations
  Form/                  # Back-office configuration forms for filter display
  Hook/                  # Hook callbacks for search result page integration
views/templates/         # Smarty/Twig templates for filter sidebar
upgrade/                 # Database migration scripts
translations/            # Translation files
tests/                   # PHPStan, unit, and integration tests
```

## Key Design Decisions

### ProductSearchProvider Integration

Implements PrestaShop's `ProductSearchProvider` interface to integrate cleanly with the
core product search framework. Filters are applied as additional SQL constraints on the
product query.

### Per-Category Filter Configuration

Each category can have a custom set of active filters configured in the back office.
Filter configuration is stored in the `ps_layered_filter` table and loaded per category.

### AJAX-Driven Filtering

Filter changes trigger AJAX requests to update only the product list and URL hash,
without full-page reload. The URL fragment encodes the current filter state for
shareable URLs.

## Extension Points

- Register custom filter types by extending the filter processing pipeline in `src/Product/Search/`.
- Hook into `actionProductSearchComplete` for custom result post-processing.
