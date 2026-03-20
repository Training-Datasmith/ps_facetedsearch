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
namespace Presta_Shop\Module\Faceted_Search\Filters;

use Category;
use Configuration;
use Context;
use Db;
use Feature;
use Group;
use Manufacturer;
use Presta_Shop\Module\Faceted_Search\Adapter\Interface_Adapter;
use Presta_Shop\Module\Faceted_Search\Definition\Availability;
use Presta_Shop\Module\Faceted_Search\Product\Search;
use Presta_Shop\Presta_Shop\Core\Localization\Locale;
use Presta_Shop\Presta_Shop\Core\Localization\Specification\Number_Symbol_List;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
use Presta_Shop_Database_Exception;
/**
 * Display filters block on navigation
 */
class Block
{
    /**
     * @var InterfaceAdapter
     */
    private $search_adapter;
    /**
     * @var bool
     */
    private $ps_stock_management;
    /**
     * @var bool
     */
    private $ps_order_out_of_stock;
    /**
     * @var Context
     */
    private $context;
    /**
     * @var Db
     */
    private $database;
    /**
     * @var DataAccessor
     */
    private $data_accessor;
    /**
     * @var Provider
     */
    private $provider;
    /**
     * @var ProductSearchQuery
     */
    private $query;
    public function __construct(Interface_Adapter $search_adapter, Context $context, Db $database, Data_Accessor $data_accessor, Product_Search_Query $query, Provider $provider)
    {
        $this->search_adapter = $search_adapter;
        $this->context = $context;
        $this->database = $database;
        $this->data_accessor = $data_accessor;
        $this->query = $query;
        $this->provider = $provider;
    }
    /**
     * @param int $nbProducts
     * @param array $selectedFilters
     */
    public function get_filter_block($nb_products, $selected_filters): array
    {
        $id_lang = (int) $this->context->language->id;
        $id_shop = (int) $this->context->shop->id;
        // Get category ID from the query or home category as a fallback
        $id_category = (int) $this->query->get_id_category();
        if (empty($id_category)) {
            $id_category = (int) Configuration::get('PS_HOME_CATEGORY');
        }
        // Get filters configured for the current query
        $filters = $this->provider->get_filters_for_query($this->query, $id_shop);
        $filter_blocks = [];
        // iterate through each filter, and the get corresponding filter block
        foreach ($filters as $filter) {
            switch ($filter['type']) {
                case 'price':
                    $filter_blocks[] = $this->get_price_range_block($filter, $selected_filters, $nb_products);
                    break;
                case 'weight':
                    $filter_blocks[] = $this->get_weight_range_block($filter, $selected_filters, $nb_products);
                    break;
                case 'condition':
                    $filter_blocks[] = $this->get_conditions_block($filter, $selected_filters);
                    break;
                case 'availability':
                    $filter_blocks[] = $this->get_availabilities_block($filter, $selected_filters);
                    break;
                case 'extras':
                    $filter_blocks[] = $this->get_highlights_block($filter, $selected_filters);
                    break;
                case 'manufacturer':
                    $filter_blocks[] = $this->get_manufacturers_block($filter, $selected_filters, $id_lang);
                    break;
                case 'id_attribute_group':
                    $filter_blocks = array_merge($filter_blocks, $this->get_attributes_block($filter, $selected_filters, $id_lang));
                    break;
                case 'id_feature':
                    $filter_blocks = array_merge($filter_blocks, $this->get_features_block($filter, $selected_filters, $id_lang));
                    break;
                case 'category':
                    $parent = new Category($id_category, $id_lang);
                    $filter_blocks[] = $this->get_categories_block($filter, $selected_filters, $id_lang, $parent);
            }
        }
        return ['filters' => $filter_blocks];
    }
    protected function show_price_filter()
    {
        return Group::get_current()->show_prices;
    }
    /**
     * Get the filter block from the cache table
     *
     * @param string $filterHash
     *
     * @return array|null
     */
    public function get_from_cache($filter_hash)
    {
        if (!Configuration::get('PS_LAYERED_CACHE_ENABLED')) {
            return null;
        }
        $row = $this->database->get_row('SELECT data FROM ' . _DB_PREFIX_ . 'layered_filter_block WHERE hash="' . p_sql($filter_hash) . '"');
        if (!empty($row)) {
            return unserialize(current($row));
        }
        return null;
    }
    /**
     * Insert the filter block into the cache table
     *
     * @param array $data
     */
    public function insert_into_cache(string $filter_hash, $data): void
    {
        if (!Configuration::get('PS_LAYERED_CACHE_ENABLED')) {
            return;
        }
        try {
            $this->database->execute('REPLACE INTO ' . _DB_PREFIX_ . 'layered_filter_block (hash, data) ' . 'VALUES ("' . $filter_hash . '", "' . p_sql(serialize($data)) . '")');
        } catch (Presta_Shop_Database_Exception $e) {
            // Don't worry if the cache have invalid or duplicate hash
        }
    }
    /**
     * @param int $nbProducts
     *
     */
    private function get_price_range_block(array $filter, array $selected_filters, $nb_products): array
    {
        if (!$this->show_price_filter()) {
            return [];
        }
        $price_specifications = $this->prepare_price_specifications();
        $price_block = ['type_lite' => 'price', 'type' => 'price', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Price', [], 'Modules.Facetedsearch.Shop'), 'max' => '0', 'min' => null, 'unit' => $this->context->currency->sign, 'specifications' => $price_specifications, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => Converter::WIDGET_TYPE_SLIDER, 'nbr' => $nb_products];
        [$price_min_filter, $price_max_filter, $weight_filter] = $this->ignore_price_and_weight_filters($this->search_adapter->get_initial_population());
        [$price_block['min'], $price_block['max']] = $this->search_adapter->get_initial_population()->get_min_max_price_value();
        $price_block['value'] = !empty($selected_filters['price']) ? $selected_filters['price'] : null;
        $this->restore_price_and_weight_filters($this->search_adapter->get_initial_population(), $price_min_filter, $price_max_filter, $weight_filter);
        return $price_block;
    }
    /**
     * Price / weight filter block should not apply their own filters
     * otherwise they will always disappear if we filter on price / weight
     * because only one choice will remain
     *
     *
     */
    private function ignore_price_and_weight_filters(Interface_Adapter $filtered_search_adapter): array
    {
        // disable the current price and weight filters to compute ranges
        $price_min_filter = $filtered_search_adapter->get_filter('price_min');
        $price_max_filter = $filtered_search_adapter->get_filter('price_max');
        $weight_filter = $filtered_search_adapter->get_filter('weight');
        $filtered_search_adapter->reset_filter('price_min');
        $filtered_search_adapter->reset_filter('price_max');
        $filtered_search_adapter->reset_filter('weight');
        return [$price_min_filter, $price_max_filter, $weight_filter];
    }
    /**
     * Restore price and weight filters
     *
     * @param InterfaceAdapter $filteredSearchAdapter
     * @param int $priceMinFilter
     * @param int $priceMaxFilter
     * @param int $weightFilter
     */
    private function restore_price_and_weight_filters($filtered_search_adapter, $price_min_filter, $price_max_filter, $weight_filter): void
    {
        // put back the price and weight filters
        $filtered_search_adapter->set_filter('price_min', $price_min_filter);
        $filtered_search_adapter->set_filter('price_max', $price_max_filter);
        $filtered_search_adapter->set_filter('weight', $weight_filter);
    }
    /**
     * Get the weight filter block
     *
     * @param int $nbProducts
     *
     */
    private function get_weight_range_block(array $filter, array $selected_filters, $nb_products): array
    {
        $weight_block = ['type_lite' => 'weight', 'type' => 'weight', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Weight', [], 'Modules.Facetedsearch.Shop'), 'max' => '0', 'min' => null, 'unit' => Configuration::get('PS_WEIGHT_UNIT'), 'specifications' => null, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => Converter::WIDGET_TYPE_SLIDER, 'value' => null, 'nbr' => $nb_products];
        [$price_min_filter, $price_max_filter, $weight_filter] = $this->ignore_price_and_weight_filters($this->search_adapter->get_initial_population());
        [$weight_block['min'], $weight_block['max']] = $this->search_adapter->get_initial_population()->get_min_max_value('p.weight');
        if (empty($weight_block['min']) && empty($weight_block['max'])) {
            // We don't need to continue, no filter available
            return [];
        }
        $weight_block['value'] = !empty($selected_filters['weight']) ? $selected_filters['weight'] : null;
        $this->restore_price_and_weight_filters($this->search_adapter->get_initial_population(), $price_min_filter, $price_max_filter, $weight_filter);
        return $weight_block;
    }
    /**
     * Get the condition filter block
     *
     *
     */
    private function get_conditions_block(array $filter, array $selected_filters): array
    {
        $condition_array = ['new' => ['name' => $this->context->get_translator()->trans('New', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0], 'used' => ['name' => $this->context->get_translator()->trans('Used', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0], 'refurbished' => ['name' => $this->context->get_translator()->trans('Refurbished', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0]];
        $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('condition');
        $results = $filtered_search_adapter->value_count('condition');
        foreach ($results as $values) {
            $condition = $values['condition'];
            $count = $values['c'];
            $condition_array[$condition]['nbr'] = $count;
            if (isset($selected_filters['condition']) && in_array($condition, $selected_filters['condition'])) {
                $condition_array[$condition]['checked'] = true;
            }
        }
        return ['type_lite' => 'condition', 'type' => 'condition', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Condition', [], 'Modules.Facetedsearch.Shop'), 'values' => $condition_array, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
    }
    /**
     * Get the quantities filter block
     *
     *
     */
    private function get_availabilities_block(array $filter, array $selected_filters): array
    {
        if ($this->ps_stock_management === null) {
            $this->ps_stock_management = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
        }
        if ($this->ps_order_out_of_stock === null) {
            $this->ps_order_out_of_stock = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }
        // We only initialize the options if stock management is activated
        $availability_options = [];
        if ($this->ps_stock_management) {
            $availability_options = [Availability::IN_STOCK => ['name' => $this->context->get_translator()->trans('In stock', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0], Availability::AVAILABLE => ['name' => $this->context->get_translator()->trans('Available', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0], Availability::NOT_AVAILABLE => ['name' => $this->context->get_translator()->trans('Not available', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0]];
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter(Search::STOCK_MANAGEMENT_FILTER);
            // Products without quantity in stock, with out-of-stock ordering disabled
            $filtered_search_adapter->add_operations_filter(Search::STOCK_MANAGEMENT_FILTER, [[['quantity', [0], '<='], ['out_of_stock', !$this->ps_order_out_of_stock ? [0, 2] : [0], '=']]]);
            $availability_options[Availability::NOT_AVAILABLE]['nbr'] = $filtered_search_adapter->count();
            // Products in stock, or with out-of-stock ordering enabled
            $filtered_search_adapter->add_operations_filter(Search::STOCK_MANAGEMENT_FILTER, [[['out_of_stock', $this->ps_order_out_of_stock ? [1, 2] : [1], '=']], [['quantity', [0], '>']]]);
            $availability_options[Availability::AVAILABLE]['nbr'] = $filtered_search_adapter->count();
            // Products in stock
            $filtered_search_adapter->add_operations_filter(Search::STOCK_MANAGEMENT_FILTER, [[['quantity', [0], '>']]]);
            $availability_options[Availability::IN_STOCK]['nbr'] = $filtered_search_adapter->count();
            // If some filter was selected, we want to show only this single filter, it does not make sense to show others
            if (isset($selected_filters['availability'])) {
                // We loop through selected filters and assign it to our options and remove the rest
                foreach ($availability_options as $key => $values) {
                    if (in_array($key, $selected_filters['availability'], true)) {
                        $availability_options[$key]['checked'] = true;
                    }
                }
            }
            // Hide Available option if the count is the same as In stock, it doesn't make no sense
            // Product count is a reliable indicator here, because there can never be product IN STOCK that is not AVAILABLE
            // So if the counts match, it MUST BE the same products
            if ($availability_options[Availability::AVAILABLE]['nbr'] == $availability_options[Availability::IN_STOCK]['nbr']) {
                unset($availability_options[Availability::AVAILABLE]);
            }
        }
        return ['type_lite' => 'availability', 'type' => 'availability', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Availability', [], 'Modules.Facetedsearch.Shop'), 'values' => $availability_options, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
    }
    /**
     * Gets block for extra product properties like "new", "on sale" and "discounted"
     *
     *
     */
    private function get_highlights_block(array $filter, array $selected_filters): array
    {
        // Prepare array with options
        $extras_options = [];
        // Products on sale - available everywhere
        $extras_options['sale'] = ['name' => $this->context->get_translator()->trans('On sale', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0];
        $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter(Search::HIGHLIGHTS_FILTER);
        $filtered_search_adapter->add_operations_filter(Search::HIGHLIGHTS_FILTER, [[['on_sale', [1], '=']]]);
        $extras_options['sale']['nbr'] = $filtered_search_adapter->count();
        // New products - available everywhere except that page
        if ($this->query->get_query_type() != 'new-products') {
            $extras_options['new'] = ['name' => $this->context->get_translator()->trans('New product', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0];
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('date_add');
            $time_condition = date('Y-m-d 00:00:00', strtotime((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') > 0 ? '-' . ((int) Configuration::get('PS_NB_DAYS_NEW_PRODUCT') - 1) . ' days' : '+ 1 days'));
            $filtered_search_adapter->add_filter('date_add', ["'" . $time_condition . "'"], '>');
            $extras_options['new']['nbr'] = $filtered_search_adapter->count();
        }
        // Discounted products - available everywhere except that page
        if ($this->query->get_query_type() != 'prices-drop') {
            $extras_options['discount'] = ['name' => $this->context->get_translator()->trans('Discounted', [], 'Modules.Facetedsearch.Shop'), 'nbr' => 0];
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter(Search::HIGHLIGHTS_FILTER);
            $filtered_search_adapter->add_operations_filter(Search::HIGHLIGHTS_FILTER, [[['reduction', [0], '>']]]);
            $extras_options['discount']['nbr'] = $filtered_search_adapter->count();
        }
        // If some filters are selected, we mark them as such
        if (isset($selected_filters['extras'])) {
            // We loop through selected filters and assign it to our options and remove the rest
            foreach ($extras_options as $key => $values) {
                if (in_array($key, $selected_filters['extras'], true)) {
                    $extras_options[$key]['checked'] = true;
                }
            }
        }
        return ['type_lite' => 'extras', 'type' => 'extras', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Selections', [], 'Modules.Facetedsearch.Shop'), 'values' => $extras_options, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
    }
    /**
     * Get the manufacturers filter block
     *
     *
     */
    private function get_manufacturers_block(array $filter, array $selected_filters, int $id_lang): array
    {
        $manufacturers_array = $manufacturers = [];
        // TODO - Needed to make manufacturer filter work (=disappear) on manufacturer page, not sure how it works.
        // (Manufacturer's page is the only page having id_manufacturer as the initial filter, that's why.)
        if ($this->query->get_query_type() == 'manufacturer') {
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter();
        } else {
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('id_manufacturer');
        }
        $temp_manufacturers = Manufacturer::get_manufacturers(false, $id_lang);
        if (empty($temp_manufacturers)) {
            return $manufacturers_array;
        }
        foreach ($temp_manufacturers as $manufacturer) {
            $manufacturers[$manufacturer['id_manufacturer']] = $manufacturer;
        }
        $results = $filtered_search_adapter->value_count('id_manufacturer');
        foreach ($results as $values) {
            if (!isset($values['id_manufacturer'])) {
                continue;
            }
            $id_manufacturer = $values['id_manufacturer'];
            if (empty($manufacturers[$id_manufacturer]['name'])) {
                continue;
            }
            $count = $values['c'];
            $manufacturers_array[$id_manufacturer] = ['name' => $manufacturers[$id_manufacturer]['name'], 'nbr' => $count];
            if (isset($selected_filters['manufacturer']) && in_array($id_manufacturer, $selected_filters['manufacturer'])) {
                $manufacturers_array[$id_manufacturer]['checked'] = true;
            }
        }
        return ['type_lite' => 'manufacturer', 'type' => 'manufacturer', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Brand', [], 'Modules.Facetedsearch.Shop'), 'values' => $manufacturers_array, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
    }
    /**
     * Get the attributes filter block
     *
     *
     * @return array
     */
    private function get_attributes_block(array $filter, array $selected_filters, int $id_lang)
    {
        $attributes_block = [];
        $filtered_search_adapter = null;
        $id_attribute_group = $filter['id_value'];
        if (!empty($selected_filters['id_attribute_group'])) {
            foreach ($selected_filters['id_attribute_group'] as $key => $selected_filter) {
                if ($key == $id_attribute_group) {
                    $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('with_attributes_' . $id_attribute_group);
                    break;
                }
            }
        }
        if (!$filtered_search_adapter) {
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter();
        }
        $attributes_group = $this->data_accessor->get_attributes_groups($id_lang);
        if ($attributes_group === []) {
            return $attributes_block;
        }
        $attributes = $this->data_accessor->get_attributes($id_lang, $id_attribute_group);
        $filtered_search_adapter->add_operations_filter('id_attribute_group_' . $id_attribute_group, [[['id_attribute_group', [(int) $id_attribute_group]]]]);
        $results = $filtered_search_adapter->value_count('id_attribute');
        foreach ($results as $values) {
            $id_attribute = $values['id_attribute'];
            if (!isset($attributes[$id_attribute])) {
                continue;
            }
            $count = $values['c'];
            $attribute = $attributes[$id_attribute];
            $id_attribute_group = $attribute['id_attribute_group'];
            if (!isset($attributes_block[$id_attribute_group])) {
                $attribute_group = $attributes_group[$id_attribute_group];
                $attributes_block[$id_attribute_group] = ['type_lite' => 'id_attribute_group', 'type' => 'id_attribute_group', 'id_key' => $id_attribute_group, 'name' => $attribute_group['attribute_group_name'], 'is_color_group' => (bool) $attribute_group['is_color_group'], 'values' => [], 'url_name' => $attribute_group['url_name'], 'meta_title' => $attribute_group['meta_title'], 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
            }
            $attributes_block[$id_attribute_group]['values'][$id_attribute] = ['name' => $attribute['name'], 'nbr' => $count, 'url_name' => $attribute['url_name'], 'meta_title' => $attribute['meta_title']];
            if ($attributes_block[$id_attribute_group]['is_color_group'] !== false) {
                $attributes_block[$id_attribute_group]['values'][$id_attribute]['color'] = $attribute['color'];
            }
            if (array_key_exists('id_attribute_group', $selected_filters)) {
                foreach ($selected_filters['id_attribute_group'] as $selected_attribute) {
                    if (in_array($id_attribute, $selected_attribute)) {
                        $attributes_block[$id_attribute_group]['values'][$id_attribute]['checked'] = true;
                    }
                }
            }
        }
        foreach ($attributes_block as $id_attribute_group => $value) {
            $attributes_block[$id_attribute_group]['values'] = $this->sort_by_key($attributes, $value['values']);
        }
        return $this->sort_by_key($attributes_group, $attributes_block);
    }
    /**
     * Sort an array using the same key order than the sortedReferenceArray
     *
     *
     */
    private function sort_by_key(array $sorted_reference_array, array $array): array
    {
        $sorted_array = [];
        // iterate in the original order
        foreach ($sorted_reference_array as $key => $value) {
            if (array_key_exists($key, $array)) {
                $sorted_array[$key] = $array[$key];
            }
        }
        return $sorted_array;
    }
    /**
     * Get the features filter block
     *
     *
     * @return array
     */
    private function get_features_block(array $filter, array $selected_filters, int $id_lang)
    {
        $feature_block = [];
        $id_feature = $filter['id_value'];
        $filtered_search_adapter = null;
        if (!empty($selected_filters['id_feature'])) {
            foreach ($selected_filters['id_feature'] as $key => $selected_filter) {
                if ($key == $id_feature) {
                    $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('with_features_' . $id_feature);
                    break;
                }
            }
        }
        if (!$filtered_search_adapter) {
            $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter();
        }
        $features = $this->data_accessor->get_features($id_lang);
        if (empty($features)) {
            return [];
        }
        $filtered_search_adapter->add_operations_filter('id_feature_' . $id_feature, [[['id_feature', [(int) $id_feature]]]]);
        $filtered_search_adapter->add_select_field('id_feature');
        $results = $filtered_search_adapter->value_count('id_feature_value');
        foreach ($results as $values) {
            $id_feature_value = $values['id_feature_value'];
            $id_feature = $values['id_feature'];
            $count = $values['c'];
            $feature = $features[$id_feature];
            if (!isset($feature_block[$id_feature])) {
                $features[$id_feature]['featureValues'] = $this->data_accessor->get_feature_values($id_feature, $id_lang);
                $feature_block[$id_feature] = ['type_lite' => 'id_feature', 'type' => 'id_feature', 'id_key' => $id_feature, 'values' => [], 'name' => $feature['name'], 'url_name' => $feature['url_name'], 'meta_title' => $feature['meta_title'], 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
            }
            $feature_values = $features[$id_feature]['featureValues'];
            if (!isset($feature_values[$id_feature_value]['value'])) {
                continue;
            }
            $feature_block[$id_feature]['values'][$id_feature_value] = ['nbr' => $count, 'name' => $feature_values[$id_feature_value]['value'], 'url_name' => $feature_values[$id_feature_value]['url_name'], 'meta_title' => $feature_values[$id_feature_value]['meta_title']];
            if (array_key_exists('id_feature', $selected_filters)) {
                foreach ($selected_filters['id_feature'] as $selected_feature) {
                    if (in_array($id_feature_value, $selected_feature)) {
                        $feature_block[$feature['id_feature']]['values'][$id_feature_value]['checked'] = true;
                    }
                }
            }
        }
        return $this->sort_feature_block($feature_block);
    }
    /**
     * Natural sort multi-dimensional feature array
     *
     *
     */
    private function sort_feature_block(array $feature_block): array
    {
        //Natural sort
        foreach ($feature_block as $key => $value) {
            $temp = [];
            foreach ($feature_block[$key]['values'] as $id_feature_value => $feature_value_infos) {
                $temp[$id_feature_value] = $feature_value_infos['name'];
            }
            natcasesort($temp);
            $temp2 = [];
            foreach ($temp as $keytemp => $valuetemp) {
                $temp2[$keytemp] = $feature_block[$key]['values'][$keytemp];
            }
            $feature_block[$key]['values'] = $temp2;
        }
        return $feature_block;
    }
    /**
     * Add the categories filter condition based on the parent and config variables
     *
     * @param Category $parent
     */
    private function add_categories_block_filters(Interface_Adapter $filtered_search_adapter, $parent): void
    {
        if (Group::is_feature_active()) {
            $user_groups = $this->context->customer->is_logged() ? $this->context->customer->get_groups() : [Configuration::get('PS_UNIDENTIFIED_GROUP')];
            $filtered_search_adapter->add_filter('id_group', $user_groups);
        }
        $depth = (int) Configuration::get('PS_LAYERED_FILTER_CATEGORY_DEPTH', null, null, null, 1);
        if ($depth) {
            $level_depth = $parent->level_depth;
            $filtered_search_adapter->add_filter('level_depth', [$depth + $level_depth], '<=');
        }
        $filtered_search_adapter->add_filter('nleft', [$parent->nleft], '>');
        $filtered_search_adapter->add_filter('nright', [$parent->nright], '<');
    }
    /**
     * Get the categories filter block
     *
     * @param Category $parent
     *
     */
    private function get_categories_block(array $filter, array $selected_filters, int $id_lang, $parent): array
    {
        $filtered_search_adapter = $this->search_adapter->get_filtered_search_adapter('id_category');
        $this->add_categories_block_filters($filtered_search_adapter, $parent);
        $category_array = [];
        $categories = Category::get_all_categories_name(null, $id_lang, true, null, true, '', 'ORDER BY c.nleft, c.position');
        foreach ($categories as $value) {
            $categories[$value['id_category']] = $value;
        }
        $results = $filtered_search_adapter->value_count('id_category');
        foreach ($results as $values) {
            $id_category = $values['id_category'];
            if (!isset($categories[$id_category])) {
                // Category can sometimes not be found in case of multistore
                // plus waiting for indexation
                continue;
            }
            $count = $values['c'];
            $category_array[$id_category] = ['name' => $categories[$id_category]['name'], 'nbr' => $count];
            if (isset($selected_filters['category']) && in_array($id_category, $selected_filters['category'])) {
                $category_array[$id_category]['checked'] = true;
            }
        }
        return ['type_lite' => 'category', 'type' => 'category', 'id_key' => 0, 'name' => $this->context->get_translator()->trans('Categories', [], 'Modules.Facetedsearch.Shop'), 'values' => $category_array, 'filter_show_limit' => (int) $filter['filter_show_limit'], 'filter_type' => $filter['filter_type']];
    }
    /**
     * Prepare price specifications to display cldr prices.
     */
    private function prepare_price_specifications(): array
    {
        /* @var PriceSpecification */
        $price_specification = $this->context->current_locale->get_price_specification($this->context->currency->iso_code);
        /* @var NumberSymbolList */
        $symbol_list = $price_specification->get_symbols_by_numbering_system(Locale::NUMBERING_SYSTEM_LATIN);
        $symbol = [$symbol_list->get_decimal(), $symbol_list->get_group(), $symbol_list->get_list(), $symbol_list->get_percent_sign(), $symbol_list->get_minus_sign(), $symbol_list->get_plus_sign(), $symbol_list->get_exponential(), $symbol_list->get_superscripting_exponent(), $symbol_list->get_per_mille(), $symbol_list->get_infinity(), $symbol_list->get_na_n()];
        return array_merge(['symbol' => $symbol], $price_specification->to_array());
    }
}