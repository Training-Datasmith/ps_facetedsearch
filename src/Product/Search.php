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

use Category;
use Configuration;
use Context;
use Front_Controller;
use Group;
use Hook;
use Presta_Shop\Module\Faceted_Search\Adapter\Abstract_Adapter;
use Presta_Shop\Module\Faceted_Search\Adapter\My_Sql as MySQLAdapter;
use Presta_Shop\Module\Faceted_Search\Definition\Availability;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
class Search
{
    public const STOCK_MANAGEMENT_FILTER = 'with_stock_management';
    public const HIGHLIGHTS_FILTER = 'extras';
    /**
     * @var bool
     */
    protected $ps_stock_management;
    /**
     * @var bool
     */
    protected $ps_order_out_of_stock;
    /**
     * @var AbstractAdapter
     */
    protected $search_adapter;
    /**
     * @var Context
     */
    protected $context;
    /**
     * @var ProductSearchQuery
     */
    protected $query;
    /**
     * Search constructor.
     *
     * @param string $adapterType
     */
    public function __construct(Context $context, $adapter_type = My_Sql_Adapter::TYPE)
    {
        $this->context = $context;
        switch ($adapter_type) {
            case My_Sql_Adapter::TYPE:
            default:
                $this->search_adapter = new My_Sql_Adapter();
        }
        if ($this->ps_stock_management === null) {
            $this->ps_stock_management = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
        }
        if ($this->ps_order_out_of_stock === null) {
            $this->ps_order_out_of_stock = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }
    }
    /**
     * @return AbstractAdapter
     */
    public function get_search_adapter()
    {
        return $this->search_adapter;
    }
    /**
     * @return ProductSearchQuery
     */
    public function get_query()
    {
        return $this->query;
    }
    /**
     * @return $this
     */
    public function set_query(Product_Search_Query $query): self
    {
        $this->query = $query;
        return $this;
    }
    /**
     * Init the initial population of the search filter
     *
     * @param array $selectedFilters
     */
    public function init_search($selected_filters): void
    {
        // Adds basic filters that are common for every search, like shop and group limitations
        $this->add_common_filters();
        // Add filters that the user has selected for current query
        $this->add_search_filters($selected_filters);
        // Adds filters that specific for this controller
        $this->add_controller_specific_filters();
        // Add group by to remove duplicate values
        $this->get_search_adapter()->add_group_by('id_product');
        // Move the current search into the "initialPopulation"
        // This initialPopulation will be used to generate the base table in the final query
        $this->get_search_adapter()->use_filters_as_initial_population();
    }
    /**
     * Adds filters that the user has specifically selected for current query
     */
    private function add_search_filters(array $selected_filters): void
    {
        foreach ($selected_filters as $key => $filter_values) {
            if (!count($filter_values)) {
                continue;
            }
            switch ($key) {
                case 'id_feature':
                    $operations_filter = [];
                    foreach ($filter_values as $feature_id => $filter_value) {
                        $this->get_search_adapter()->add_operations_filter('with_features_' . $feature_id, [[['id_feature_value', $filter_value]]]);
                    }
                    break;
                case 'id_attribute_group':
                    $operations_filter = [];
                    foreach ($filter_values as $attribute_id => $filter_value) {
                        $this->get_search_adapter()->add_operations_filter('with_attributes_' . $attribute_id, [[['id_attribute', $filter_value]]]);
                    }
                    break;
                case 'category':
                    $this->add_filter('id_category', $filter_values);
                    break;
                case 'extras':
                    // Filter for new products
                    if (in_array('new', $filter_values)) {
                        $time_condition = date('Y-m-d 00:00:00', strtotime((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') > 0 ? '-' . ((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') - 1) . ' days' : '+ 1 days'));
                        // Reset filter to prevent two same filters if we are on new products page
                        $this->get_search_adapter()->add_filter('date_add', ["'" . $time_condition . "'"], '>');
                    }
                    // Filter for discounts - they must work as OR
                    $operations_filter = [];
                    if (in_array('discount', $filter_values)) {
                        $operations_filter[] = [['reduction', [0], '>']];
                    }
                    if (in_array('sale', $filter_values)) {
                        $operations_filter[] = [['on_sale', [1], '=']];
                    }
                    if (!empty($operations_filter)) {
                        $this->get_search_adapter()->add_operations_filter(self::HIGHLIGHTS_FILTER, $operations_filter);
                    }
                    break;
                case 'availability':
                    /*
                     * $filterValues options can have following values:
                     * 0 - Not available - 0 or less quantity and disabled backorders
                     * 1 - Available - Positive quantity or enabled backorders
                     * 2 - In stock - Positive quantity
                     */
                    // If all three values are checked, we show everything
                    if (count($filter_values) == 3) {
                        break;
                    }
                    // If stock management is deactivated, we show everything
                    if (!$this->ps_stock_management) {
                        break;
                    }
                    $operations_filter = [];
                    // Simple cases with 1 option selected
                    if (count($filter_values) == 1) {
                        // Not available
                        if ($filter_values[0] == Availability::NOT_AVAILABLE) {
                            $operations_filter[] = [['quantity', [0], '<='], ['out_of_stock', $this->ps_order_out_of_stock ? [0] : [0, 2], '=']];
                            // Available
                        } elseif ($filter_values[0] == Availability::AVAILABLE) {
                            $operations_filter[] = [['out_of_stock', $this->ps_order_out_of_stock ? [1, 2] : [1], '=']];
                            $operations_filter[] = [['quantity', [0], '>']];
                            // In stock
                        } elseif ($filter_values[0] == Availability::IN_STOCK) {
                            $operations_filter[] = [['quantity', [0], '>']];
                        }
                        // Cases with 2 options selected
                    } elseif (count($filter_values) == 2) {
                        // Not available and available, we show everything
                        if (in_array(Availability::NOT_AVAILABLE, $filter_values) && in_array(Availability::AVAILABLE, $filter_values)) {
                            break;
                            // Not available or in stock
                        } elseif (in_array(Availability::NOT_AVAILABLE, $filter_values) && in_array(Availability::IN_STOCK, $filter_values)) {
                            $operations_filter[] = [['quantity', [0], '<='], ['out_of_stock', $this->ps_order_out_of_stock ? [0] : [0, 2], '=']];
                            $operations_filter[] = [['quantity', [0], '>']];
                            // Available or in stock
                        } elseif (in_array(Availability::AVAILABLE, $filter_values) && in_array(Availability::IN_STOCK, $filter_values)) {
                            $operations_filter[] = [['out_of_stock', $this->ps_order_out_of_stock ? [1, 2] : [1], '=']];
                            $operations_filter[] = [['quantity', [0], '>']];
                        }
                    }
                    $this->get_search_adapter()->add_operations_filter(self::STOCK_MANAGEMENT_FILTER, $operations_filter);
                    break;
                case 'manufacturer':
                    $this->add_filter('id_manufacturer', $filter_values);
                    break;
                case 'condition':
                    if (count($selected_filters['condition']) == 3) {
                        break;
                    }
                    $this->add_filter('condition', $filter_values);
                    break;
                case 'weight':
                    if (!empty($selected_filters['weight'][0]) || !empty($selected_filters['weight'][1])) {
                        $this->get_search_adapter()->add_filter('weight', [(float) $selected_filters['weight'][0]], '>=');
                        $this->get_search_adapter()->add_filter('weight', [(float) $selected_filters['weight'][1]], '<=');
                    }
                    break;
                case 'price':
                    if (isset($selected_filters['price']) && ($selected_filters['price'][0] !== '' || $selected_filters['price'][1] !== '')) {
                        $this->add_price_filter((float) $selected_filters['price'][0], (float) $selected_filters['price'][1]);
                    }
                    break;
            }
        }
    }
    /**
     * Adds filters that are common for every search
     */
    private function add_common_filters(): void
    {
        // Setting proper shop
        $this->get_search_adapter()->add_filter('id_shop', [(int) $this->context->shop->id]);
        // Visibility of a product must be in catalog or both (search & catalog)
        $this->add_filter('visibility', ['both', 'catalog']);
        // User must belong to one of the groups that can access the product
        // (Actually it's categories that define access to a product, user must have access to at least
        // one category the product is assigned to.)
        if (Group::is_feature_active()) {
            $groups = Front_Controller::get_current_customer_groups();
            $this->add_filter('id_group', empty($groups) ? [Group::get_current()->id] : $groups);
        }
    }
    /**
     * Adds filters that specific for category page
     */
    private function add_controller_specific_filters(): void
    {
        // Category page
        if ($this->query->get_query_type() == 'category') {
            // We check if some specific filter of this type wasn't added before by the customer
            if (!empty($this->get_search_adapter()->get_filter('id_category'))) {
                return;
            }
            // Get category ID from the query or home category as a fallback
            $id_category = (int) $this->query->get_id_category();
            if (empty($id_category)) {
                $id_category = (int) Configuration::get('PS_HOME_CATEGORY');
            }
            $category = new Category($id_category);
            // If we want to display only products from this category AND not it's subcategories,
            // we add this one specific category ID, otherwise, we will add everything using nleft and nright
            if (Configuration::get('PS_LAYERED_FULL_TREE')) {
                $this->get_search_adapter()->add_filter('nleft', [$category->nleft], '>=');
                $this->get_search_adapter()->add_filter('nright', [$category->nright], '<=');
            } else {
                $this->add_filter('id_category', [$id_category]);
            }
            // If we want to display products, which have this category as their default category
            if (Configuration::get('PS_LAYERED_FILTER_BY_DEFAULT_CATEGORY')) {
                $this->add_filter('id_category_default', [$id_category]);
            }
        }
        // Manufacturer controller
        if ($this->query->get_query_type() == 'manufacturer') {
            $this->get_search_adapter()->add_filter('id_manufacturer', [$this->query->get_id_manufacturer()]);
        }
        // Supplier controller
        if ($this->query->get_query_type() == 'supplier') {
            $this->get_search_adapter()->add_filter('id_supplier', [$this->query->get_id_supplier()]);
        }
        /*
         * New products controller
         *
         * Comparsion works works on a day basis, not 24 hours.
         * If you set 1 day, only products created TODAY will be new.
         * If there is a zero set to disable this feature, it creates unreachable condition.
         */
        if ($this->query->get_query_type() == 'new-products') {
            // We check if some specific filter of this type wasn't added before
            if (!empty($this->get_search_adapter()->get_filter('date_add'))) {
                return;
            }
            $time_condition = date('Y-m-d 00:00:00', strtotime((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') > 0 ? '-' . ((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') - 1) . ' days' : '+ 1 days'));
            $this->get_search_adapter()->add_filter('date_add', ["'" . $time_condition . "'"], '>');
        }
        /*
         * Bestsellers controller
         *
         * We are selecting all products from product_sale table.
         */
        if ($this->query->get_query_type() == 'best-sales') {
            $this->get_search_adapter()->add_filter('sales', [0], '>');
        }
        /*
         * Prices drop controller
         *
         * We are selecting products that have a specific price created meeting certain conditions.
         */
        if ($this->query->get_query_type() == 'prices-drop') {
            // We check if some specific filter of this type wasn't added before
            if (!empty($this->get_search_adapter()->get_filter('reduction'))) {
                return;
            }
            $this->get_search_adapter()->add_filter('reduction', [0], '>');
        }
        /*
         * Search controller
         *
         * We are using a fast backport to get a product pool, which is then passed to the query.
         * Core search provider does simmilar thing. If nothing is found, we return a value
         * (NULL string) that will ensure empty result. It would be better to stop the search
         * sooner in the logic, in the future.
         */
        if ($this->query->get_query_type() == 'search') {
            $product_pool = (new Core_Search_Backport())->get_product_pool($this->query);
            $this->get_search_adapter()->add_filter('id_product', empty($product_pool) ? ['NULL'] : $product_pool);
        }
        Hook::exec('actionFacetedSearchFilters', ['search' => $this, 'query' => $this->query]);
    }
    /**
     * Add a filter with the filterValues extracted from the selectedFilters
     *
     * @param string $filterName
     */
    public function add_filter($filter_name, array $filter_values): void
    {
        $values = [];
        foreach ($filter_values as $filter_value) {
            if (is_array($filter_value)) {
                foreach ($filter_value as $sub_filter_value) {
                    $values[] = (int) $sub_filter_value;
                }
            } else {
                $values[] = $filter_value;
            }
        }
        if (!empty($values)) {
            $this->get_search_adapter()->add_filter($filter_name, $values);
        }
    }
    /**
     * Add a price filter
     */
    private function add_price_filter(float $min_price, float $max_price): void
    {
        $this->get_search_adapter()->add_filter('price_min', [$max_price], '<=');
        $this->get_search_adapter()->add_filter('price_max', [$min_price], '>=');
    }
}