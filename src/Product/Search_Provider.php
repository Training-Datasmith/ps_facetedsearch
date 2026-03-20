<?php

declare (strict_types=1);
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
namespace Presta_Shop\Module\Faceted_Search\Product;

use Configuration;
use Hook;
use Presta_Shop\Module\Faceted_Search\Filters;
use Presta_Shop\Module\Faceted_Search\Url_Serializer;
use Presta_Shop\Presta_Shop\Core\Product\Search\Facet;
use Presta_Shop\Presta_Shop\Core\Product\Search\Facet_Collection;
use Presta_Shop\Presta_Shop\Core\Product\Search\Facets_Renderer_Interface;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Context;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Provider_Interface;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Result;
use Presta_Shop\Presta_Shop\Core\Product\Search\Sort_Order;
use Ps_Facetedsearch;
use Tools;
class Search_Provider implements Facets_Renderer_Interface, Product_Search_Provider_Interface
{
    /**
     * @var Ps_Facetedsearch
     */
    private $module;
    /**
     * @var Filters\Converter
     */
    private $filters_converter;
    /**
     * @var Filters\DataAccessor
     */
    private $data_accessor;
    /**
     * @var URLSerializer
     */
    private $url_serializer;
    /**
     * @var SearchFactory
     */
    private $search_factory;
    /**
     * @var Filters\Provider
     */
    private $provider;
    public function __construct(Ps_Facetedsearch $module, Filters\Converter $converter, Url_Serializer $serializer, Filters\Data_Accessor $data_accessor, Search_Factory $search_factory, Filters\Provider $provider)
    {
        $this->module = $module;
        $this->filters_converter = $converter;
        $this->url_serializer = $serializer;
        $this->data_accessor = $data_accessor;
        $this->search_factory = $search_factory;
        $this->provider = $provider;
    }
    /**
     * @param ProductSearchQuery $query
     */
    private function get_available_sort_orders($query): array
    {
        $sort_sales_desc = new Sort_Order('product', 'sales', 'desc');
        // If the query is a search, we want to sort by position in descending order = relevance
        // If the query is a category, manufacturer or supplier, we want to sort by position in ascending order
        $sort_pos_asc = new Sort_Order('product', 'position', $query->get_query_type() == 'search' ? 'desc' : 'asc');
        $sort_name_asc = new Sort_Order('product', 'name', 'asc');
        $sort_name_desc = new Sort_Order('product', 'name', 'desc');
        $sort_price_asc = new Sort_Order('product', 'price', 'asc');
        $sort_price_desc = new Sort_Order('product', 'price', 'desc');
        $sort_date_asc = new Sort_Order('product', 'date_add', 'asc');
        $sort_date_desc = new Sort_Order('product', 'date_add', 'desc');
        $sort_ref_asc = new Sort_Order('product', 'reference', 'asc');
        $sort_ref_desc = new Sort_Order('product', 'reference', 'desc');
        $translator = $this->module->get_translator();
        $sort_orders = [$sort_sales_desc->set_label($translator->trans('Sales, highest to lowest', [], 'Shop.Theme.Catalog')), $sort_pos_asc->set_label($translator->trans('Relevance', [], 'Shop.Theme.Catalog')), $sort_name_asc->set_label($translator->trans('Name, A to Z', [], 'Shop.Theme.Catalog')), $sort_name_desc->set_label($translator->trans('Name, Z to A', [], 'Shop.Theme.Catalog')), $sort_price_asc->set_label($translator->trans('Price, low to high', [], 'Shop.Theme.Catalog')), $sort_price_desc->set_label($translator->trans('Price, high to low', [], 'Shop.Theme.Catalog')), $sort_ref_asc->set_label($translator->trans('Reference, A to Z', [], 'Shop.Theme.Catalog')), $sort_ref_desc->set_label($translator->trans('Reference, Z to A', [], 'Shop.Theme.Catalog'))];
        if ($query->get_query_type() == 'new-products') {
            $sort_orders[] = $sort_date_asc->set_label($translator->trans('Date added, oldest to newest', [], 'Shop.Theme.Catalog'));
            $sort_orders[] = $sort_date_desc->set_label($translator->trans('Date added, newest to oldest', [], 'Shop.Theme.Catalog'));
        }
        return $sort_orders;
    }
    /**
     * Instance of this class was previously passed to frontend controller, so we are now
     * ready to accept runQuery requests. The query object contains all the important information
     * about what we should get.
     *
     *
     */
    public function run_query(Product_Search_Context $context, Product_Search_Query $query): \Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Result
    {
        $result = new Product_Search_Result();
        /**
         * Get currently selected filters. In the query, it's passed as encoded URL string,
         * we make it an array. All filters in the URL that are no longer valid are removed.
         */
        $faceted_search_filters = $this->filters_converter->create_faceted_search_filters_from_query($query);
        // Initialize the search mechanism
        $context = $this->module->get_context();
        $faceted_search = $this->search_factory->build($context);
        // Add query information into Search
        $faceted_search->set_query($query);
        // Init the search with the initial population associated with the current filters
        $faceted_search->init_search($faceted_search_filters);
        // Request combination IDs if we have some attributes to search by.
        // If not, we won't use this to let the core select the default combination.
        if ($this->should_pass_combination_ids($faceted_search_filters)) {
            $faceted_search->get_search_adapter()->get_initial_population()->add_select_field('id_product_attribute');
            $faceted_search->get_search_adapter()->add_select_field('id_product_attribute');
        }
        // Load the product searcher, it gets the Adapter through Search object
        $filter_product_search = new Filters\Products($faceted_search);
        // Get the product associated with the current filter
        $products_and_count = $filter_product_search->get_product_by_filters($query, $faceted_search_filters);
        $result->set_products($products_and_count['products'])->set_total_products_count($products_and_count['count'])->set_available_sort_orders($this->get_available_sort_orders($query));
        // Now let's get the filter blocks associated with the current search.
        // This will allow user to further filter this list we found.
        $filter_block_search = new Filters\Block($faceted_search->get_search_adapter(), $context, $this->module->get_database(), $this->data_accessor, $query, $this->provider);
        // Let's try to get filters from cache, if the controller is supported
        $filter_hash = $this->generate_cache_key_for_query($query, $faceted_search_filters);
        if ($this->module->should_cache_controller($query->get_query_type())) {
            $filter_block = $filter_block_search->get_from_cache($filter_hash);
        }
        // If not, we regenerate it and cache it
        if (empty($filter_block)) {
            $filter_block = $filter_block_search->get_filter_block($products_and_count['count'], $faceted_search_filters);
            if ($this->module->should_cache_controller($query->get_query_type())) {
                $filter_block_search->insert_into_cache($filter_hash, $filter_block);
            }
        }
        $facets = $this->filters_converter->get_facets_from_filter_blocks($filter_block['filters']);
        $this->label_range_filters($facets);
        $this->add_encoded_facets_to_filters($facets);
        $this->hide_useless_facets($facets, (int) $result->get_total_products_count());
        $facet_collection = new Facet_Collection();
        $next_menu = $facet_collection->set_facets($facets);
        $result->set_facet_collection($next_menu);
        $facet_filters = $this->url_serializer->get_active_facet_filters_from_facets($facets);
        $result->set_encoded_facets($this->url_serializer->serialize($facet_filters));
        return $result;
    }
    /**
     * Generate unique cache hash to store blocks in cache
     *
     *
     */
    private function generate_cache_key_for_query(Product_Search_Query $query, array $faceted_search_filters): string
    {
        $context = $this->module->get_context();
        $filter_key = $query->get_query_type();
        if ($query->get_query_type() == 'category') {
            $filter_key .= $query->get_id_category();
        } elseif ($query->get_query_type() == 'manufacturer') {
            $filter_key .= $query->get_id_manufacturer();
        } elseif ($query->get_query_type() == 'supplier') {
            $filter_key .= $query->get_id_supplier();
        }
        Hook::exec('actionFacetedSearchCacheKeyGeneration', ['filterKey' => &$filter_key, 'query' => $query, 'facetedSearchFilters' => &$faceted_search_filters]);
        return md5(sprintf('%d-%d-%d-%s-%d-%s', (int) $context->shop->id, (int) $context->currency->id, (int) $context->language->id, $filter_key, (int) $context->country->id, serialize($faceted_search_filters)));
    }
    /**
     * Renders an product search result.
     *
     *
     * @return string the HTML of the facets
     */
    public function render_facets(Product_Search_Context $context, Product_Search_Result $result)
    {
        [$active_filters, $displayed_facets, $facets_var] = $this->prepare_active_filters_for_render($result);
        // No need to render without facets
        if (empty($facets_var)) {
            return '';
        }
        $this->module->get_context()->smarty->assign(['show_quantities' => Configuration::get('PS_LAYERED_SHOW_QTIES'), 'facets' => $facets_var, 'js_enabled' => $this->module->is_ajax(), 'displayedFacets' => $displayed_facets, 'activeFilters' => $active_filters, 'sort_order' => $result->get_current_sort_order()->to_string(), 'clear_all_link' => $this->update_query_string(['q' => null, 'page' => null])]);
        return $this->module->fetch('module:ps_facetedsearch/views/templates/front/catalog/facets.tpl');
    }
    /**
     * Renders an product search result of active filters.
     *
     *
     * @return string the HTML of the facets
     */
    public function render_active_filters(Product_Search_Context $context, Product_Search_Result $result)
    {
        [$active_filters] = $this->prepare_active_filters_for_render($result);
        $this->module->get_context()->smarty->assign(['activeFilters' => $active_filters, 'clear_all_link' => $this->update_query_string(['q' => null, 'page' => null])]);
        return $this->module->fetch('module:ps_facetedsearch/views/templates/front/catalog/active-filters.tpl');
    }
    /**
     * Prepare active filters for renderer.
     *
     *
     */
    private function prepare_active_filters_for_render(Product_Search_Result $result): ?array
    {
        $facet_collection = $result->get_facet_collection();
        // not all search providers generate menus
        if (empty($facet_collection)) {
            return null;
        }
        $facets_var = array_map([$this, 'prepareFacetForTemplate'], $facet_collection->get_facets());
        $displayed_facets = [];
        $active_filters = [];
        foreach ($facets_var as $facet) {
            // Remove undisplayed facets
            if (!empty($facet['displayed'])) {
                $displayed_facets[] = $facet;
            }
            // Check if a filter is active
            foreach ($facet['filters'] as $filter) {
                if ($filter['active']) {
                    $active_filters[] = $filter;
                }
            }
        }
        return [$active_filters, $displayed_facets, $facets_var];
    }
    /**
     * Converts a Facet to an array with all necessary
     * information for templating.
     *
     *
     * @return array ready for templating
     */
    protected function prepare_facet_for_template(Facet $facet)
    {
        $facets_array = $facet->to_array();
        foreach ($facets_array['filters'] as &$filter) {
            $filter['facetLabel'] = $facet->get_label();
            if ($filter['nextEncodedFacets'] || $facet->get_widget_type() === 'slider') {
                $filter['nextEncodedFacetsURL'] = $this->update_query_string(['q' => $filter['nextEncodedFacets'], 'page' => null]);
            } else {
                $filter['nextEncodedFacetsURL'] = $this->update_query_string(['q' => null]);
            }
        }
        unset($filter);
        return $facets_array;
    }
    /**
     * Add a label associated with the facets
     */
    private function label_range_filters(array $facets): void
    {
        $context = $this->module->get_context();
        foreach ($facets as $facet) {
            if (!in_array($facet->get_type(), Filters\Converter::RANGE_FILTERS)) {
                continue;
            }
            foreach ($facet->get_filters() as $filter) {
                $filter_value = $filter->get_value();
                $min = empty($filter_value[0]) ? $facet->get_property('min') : $filter_value[0];
                $max = empty($filter_value[1]) ? $facet->get_property('max') : $filter_value[1];
                if ($facet->get_type() === 'weight') {
                    $unit = Configuration::get('PS_WEIGHT_UNIT');
                    $filter->set_label(sprintf('%1$s %2$s - %3$s %4$s', $context->get_current_locale()->format_number($min), $unit, $context->get_current_locale()->format_number($max), $unit));
                } elseif ($facet->get_type() === 'price') {
                    $filter->set_label(sprintf('%1$s - %2$s', $context->get_current_locale()->format_price($min, $context->currency->iso_code), $context->get_current_locale()->format_price($max, $context->currency->iso_code)));
                }
            }
        }
    }
    /**
     * This method generates a URL stub for each filter inside the given facets
     * and assigns this stub to the filters.
     * The URL stub is called 'nextEncodedFacets' because it is used
     * to generate the URL of the search once a filter is activated.
     */
    private function add_encoded_facets_to_filters(array $facets): void
    {
        // first get the currently active facetFilter in an array
        $original_facet_filters = $this->url_serializer->get_active_facet_filters_from_facets($facets);
        foreach ($facets as $facet) {
            $active_facet_filters = $original_facet_filters;
            // If only one filter can be selected, we keep track of
            // the current active filter to disable it before generating the url stub
            // and not select two filters in a facet that can have only one active filter.
            if (!$facet->is_multiple_selection_allowed() && !$facet->get_property('range')) {
                foreach ($facet->get_filters() as $filter) {
                    if ($filter->is_active()) {
                        // we have a currently active filter is the facet, remove it from the facetFilter array
                        $active_facet_filters = $this->url_serializer->remove_filter_from_facet_filters($original_facet_filters, $filter, $facet);
                        break;
                    }
                }
            }
            foreach ($facet->get_filters() as $filter) {
                // toggle the current filter
                if ($filter->is_active() || $facet->get_property('range')) {
                    $facet_filters = $this->url_serializer->remove_filter_from_facet_filters($active_facet_filters, $filter, $facet);
                } else {
                    $facet_filters = $this->url_serializer->add_filter_to_facet_filters($active_facet_filters, $filter, $facet);
                }
                // We've toggled the filter, so the call to serialize
                // returns the "URL" for the search when user has toggled
                // the filter.
                $filter->set_next_encoded_facets($this->url_serializer->serialize($facet_filters));
            }
        }
    }
    /**
     * Remove the facet when there's only 1 result.
     * Keep facet status when it's a slider.
     * Keep facet status if it's a availability or extras facet.
     */
    private function hide_useless_facets(array $facets, int $total_products): void
    {
        foreach ($facets as $facet) {
            // If the facet is a slider type, we hide it ONLY if the MIN and MAX value match
            if ($facet->get_widget_type() === 'slider') {
                $facet->set_displayed($facet->get_property('min') != $facet->get_property('max'));
                continue;
            }
            // Now the rest of facets - we apply this logic
            $total_facet_products = 0;
            $useful_filters_count = 0;
            foreach ($facet->get_filters() as $filter) {
                if ($filter->get_magnitude() > 0 && $filter->is_displayed()) {
                    $total_facet_products += $filter->get_magnitude();
                    ++$useful_filters_count;
                }
            }
            // We display the facet in several cases
            $facet->set_displayed(
                // If there are two filters available
                $useful_filters_count > 1 || count($facet->get_filters()) === 1 && $total_facet_products < $total_products && $useful_filters_count > 0 || $useful_filters_count === 1 && ($facet->get_type() == 'availability' || $facet->get_type() == 'extras')
            );
            // Other cases - hidden by default
        }
    }
    /**
     * Generate a URL corresponding to the current page but
     * with the query string altered.
     *
     * Params from $extraParams that have a null value are stripped,
     * and other params are added. Params not in $extraParams are unchanged.
     */
    private function update_query_string(array $extra_params = []): string
    {
        $uri_without_params = explode('?', $_SERVER['REQUEST_URI'])[0];
        $url = Tools::get_current_url_protocol_prefix() . $_SERVER['HTTP_HOST'] . $uri_without_params;
        $params = [];
        $params_from_uri = '';
        if (strpos($_SERVER['REQUEST_URI'], '?') !== false) {
            $params_from_uri = explode('?', $_SERVER['REQUEST_URI'])[1];
        }
        parse_str($params_from_uri, $params);
        foreach ($extra_params as $key => $value) {
            if (null === $value) {
                // Force clear param if null value is passed
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }
        foreach ($params as $key => $param) {
            if (null === $param || '' === $param) {
                unset($params[$key]);
            }
        }
        $query_string = str_replace('%2F', '/', http_build_query($params, '', '&'));
        return $url . ($query_string ? "?{$query_string}" : '');
    }
    /**
     * Checks if we should return information about combinations to the core
     *
     * @param array $facetedSearchFilters filters passed in the query and parsed by our module
     *
     * @return bool if should add attributes to the select
     */
    private function should_pass_combination_ids(array $faceted_search_filters): bool
    {
        return !empty($faceted_search_filters['id_attribute_group']);
    }
}