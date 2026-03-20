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
use Manufacturer;
use Presta_Shop\Module\Faceted_Search\Definition\Availability;
use Presta_Shop\Module\Faceted_Search\Filters;
use Presta_Shop\Module\Faceted_Search\Url_Serializer;
use Presta_Shop\Presta_Shop\Core\Product\Search\Facet;
use Presta_Shop\Presta_Shop\Core\Product\Search\Filter;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
class Converter
{
    public const WIDGET_TYPE_CHECKBOX = 0;
    public const WIDGET_TYPE_RADIO = 1;
    public const WIDGET_TYPE_DROPDOWN = 2;
    public const WIDGET_TYPE_SLIDER = 3;
    public const TYPE_ATTRIBUTE_GROUP = 'id_attribute_group';
    public const TYPE_AVAILABILITY = 'availability';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_FEATURE = 'id_feature';
    public const TYPE_MANUFACTURER = 'manufacturer';
    public const TYPE_PRICE = 'price';
    public const TYPE_WEIGHT = 'weight';
    public const TYPE_EXTRAS = 'extras';
    public const PROPERTY_URL_NAME = 'url_name';
    public const PROPERTY_COLOR = 'color';
    public const PROPERTY_TEXTURE = 'texture';
    /**
     * @var array
     */
    public const RANGE_FILTERS = [self::TYPE_PRICE, self::TYPE_WEIGHT];
    /**
     * @var Context
     */
    protected $context;
    /**
     * @var Db
     */
    protected $database;
    /**
     * @var URLSerializer
     */
    protected $url_serializer;
    /**
     * @var Filters\DataAccessor
     */
    private $data_accessor;
    /**
     * @var Filters\Provider
     */
    private $provider;
    public function __construct(Context $context, Db $database, Url_Serializer $url_serializer, Filters\Data_Accessor $data_accessor, Filters\Provider $provider)
    {
        $this->context = $context;
        $this->database = $database;
        $this->url_serializer = $url_serializer;
        $this->data_accessor = $data_accessor;
        $this->provider = $provider;
    }
    /**
     * @return \PrestaShop\PrestaShop\Core\Product\Search\Facet[]
     */
    public function get_facets_from_filter_blocks(array $filter_blocks): array
    {
        $facets = [];
        foreach ($filter_blocks as $filter_block) {
            if (empty($filter_block)) {
                // Empty filter, let's continue
                continue;
            }
            $facet = new Facet();
            $facet->set_label($filter_block['name'])->set_property('filter_show_limit', $filter_block['filter_show_limit'])->set_multiple_selection_allowed(true);
            switch ($filter_block['type']) {
                case self::TYPE_CATEGORY:
                case self::TYPE_CONDITION:
                case self::TYPE_EXTRAS:
                case self::TYPE_MANUFACTURER:
                case self::TYPE_AVAILABILITY:
                case self::TYPE_ATTRIBUTE_GROUP:
                case self::TYPE_FEATURE:
                    $type = $filter_block['type'];
                    if ($filter_block['type'] == self::TYPE_ATTRIBUTE_GROUP) {
                        $type = 'attribute_group';
                        $facet->set_property(self::TYPE_ATTRIBUTE_GROUP, $filter_block['id_key']);
                        if (isset($filter_block['url_name'])) {
                            $facet->set_property(self::PROPERTY_URL_NAME, $filter_block['url_name']);
                        }
                    } elseif ($filter_block['type'] == self::TYPE_FEATURE) {
                        $type = 'feature';
                        $facet->set_property(self::TYPE_FEATURE, $filter_block['id_key']);
                        if (isset($filter_block['url_name'])) {
                            $facet->set_property(self::PROPERTY_URL_NAME, $filter_block['url_name']);
                        }
                    }
                    $facet->set_type($type);
                    $filters = [];
                    foreach ($filter_block['values'] as $id => $filter_array) {
                        $filter = new Filter();
                        $filter->set_type($type)->set_label($filter_array['name'])->set_magnitude($filter_array['nbr'])->set_value($id);
                        if (isset($filter_array['url_name'])) {
                            $filter->set_property(self::PROPERTY_URL_NAME, $filter_array['url_name']);
                        }
                        if (array_key_exists('checked', $filter_array)) {
                            $filter->set_active($filter_array['checked']);
                        }
                        if (isset($filter_array['color'])) {
                            if (file_exists(_PS_COL_IMG_DIR_ . $id . '.jpg')) {
                                $filter->set_property(self::PROPERTY_TEXTURE, _THEME_COL_DIR_ . $id . '.jpg');
                            } elseif ($filter_array['color'] != '') {
                                $filter->set_property(self::PROPERTY_COLOR, $filter_array['color']);
                            }
                        }
                        $filters[] = $filter;
                    }
                    if ((int) $filter_block['filter_show_limit'] !== 0) {
                        usort($filters, [$this, 'sortFiltersByMagnitude']);
                    }
                    $this->hide_zero_values_and_show_limit($filters, (int) $filter_block['filter_show_limit']);
                    if ((int) $filter_block['filter_show_limit'] !== 0 || $filter_block['type'] !== self::TYPE_ATTRIBUTE_GROUP && $filter_block['type'] !== self::TYPE_AVAILABILITY) {
                        usort($filters, [$this, 'sortFiltersByLabel']);
                    }
                    // No method available to add all filters
                    foreach ($filters as $filter) {
                        $facet->add_filter($filter);
                    }
                    break;
                case self::TYPE_WEIGHT:
                case self::TYPE_PRICE:
                    $facet->set_type($filter_block['type'])->set_property('min', $filter_block['min'])->set_property('max', $filter_block['max'])->set_property('unit', $filter_block['unit'])->set_property('specifications', $filter_block['specifications'])->set_multiple_selection_allowed(false)->set_property('range', true);
                    $filter = new Filter();
                    $filter->set_active($filter_block['value'] !== null)->set_type($filter_block['type'])->set_magnitude($filter_block['nbr'])->set_property('symbol', $filter_block['unit'])->set_value($filter_block['value']);
                    $facet->add_filter($filter);
                    break;
            }
            switch ((int) $filter_block['filter_type']) {
                case self::WIDGET_TYPE_CHECKBOX:
                    $facet->set_multiple_selection_allowed(true);
                    $facet->set_widget_type('checkbox');
                    break;
                case self::WIDGET_TYPE_RADIO:
                    $facet->set_multiple_selection_allowed(false);
                    $facet->set_widget_type('radio');
                    break;
                case self::WIDGET_TYPE_DROPDOWN:
                    $facet->set_multiple_selection_allowed(false);
                    $facet->set_widget_type('dropdown');
                    break;
                case self::WIDGET_TYPE_SLIDER:
                    $facet->set_multiple_selection_allowed(false);
                    $facet->set_widget_type('slider');
                    break;
            }
            $facets[] = $facet;
        }
        return $facets;
    }
    /**
     * This method is responsible of parsing the search filters sent in the query.
     * These filters come from the URL in 99 % of cases.
     *
     * It will unserialize it and convert it to actual unique and valid values that
     * we will later use to construct the database query. All invalid filters in the
     * query (unknown value, deleted in shop etc.) are ignored.
     *
     * Filters that are found (if any) will be later used in initSearch method, along
     * with some predefined ones related the the controller we are on.
     *
     *
     */
    public function create_faceted_search_filters_from_query(Product_Search_Query $query): array
    {
        $id_shop = (int) $this->context->shop->id;
        $id_lang = (int) $this->context->language->id;
        // Get category ID from the query or home category as a fallback
        $id_category = (int) $query->get_id_category();
        if (empty($id_category)) {
            $id_category = (int) Configuration::get('PS_HOME_CATEGORY');
        }
        $search_filters = [];
        // Get filters configured in module settings for the current query
        $configured_filters = $this->provider->get_filters_for_query($query, $id_shop);
        /*
         * Parses submitted encoded facets from (URL) string into a nice array.
         *
         * Facets are set to the URL with a textual representation. This unfortunately does not
         * work very well, because there could be duplicate values for both facet and filter.
         * For example, if there are two features, feature values or categories with the same name.
         */
        $received_filters = $this->url_serializer->unserialize($query->get_encoded_facets());
        // Go through filters that are configured and find out which should be activated,
        // depending on what was provided in the encodedFacets.
        foreach ($configured_filters as $filter) {
            $filter_label = $this->convert_filter_type_to_label($filter['type']);
            switch ($filter['type']) {
                case self::TYPE_MANUFACTURER:
                    if (!isset($received_filters[$filter_label])) {
                        // No need to filter if no information
                        continue 2;
                    }
                    $manufacturers = Manufacturer::get_manufacturers(false, $id_lang);
                    $search_filters[$filter['type']] = [];
                    foreach ($manufacturers as $manufacturer) {
                        if (in_array($manufacturer['name'], $received_filters[$filter_label])) {
                            $search_filters[$filter['type']][$manufacturer['name']] = $manufacturer['id_manufacturer'];
                        }
                    }
                    break;
                case self::TYPE_AVAILABILITY:
                    if (!isset($received_filters[$filter_label])) {
                        // No need to filter if no information
                        continue 2;
                    }
                    $quantity_array = [$this->context->get_translator()->trans('Not available', [], 'Modules.Facetedsearch.Shop') => Availability::NOT_AVAILABLE, $this->context->get_translator()->trans('Available', [], 'Modules.Facetedsearch.Shop') => Availability::AVAILABLE, $this->context->get_translator()->trans('In stock', [], 'Modules.Facetedsearch.Shop') => Availability::IN_STOCK];
                    $search_filters[$filter['type']] = [];
                    foreach ($quantity_array as $quantity_name => $quantity_id) {
                        if (isset($received_filters[$filter_label]) && in_array($quantity_name, $received_filters[$filter_label])) {
                            $search_filters[$filter['type']][] = $quantity_id;
                        }
                    }
                    break;
                case self::TYPE_CONDITION:
                    if (!isset($received_filters[$filter_label])) {
                        // No need to filter if no information
                        continue 2;
                    }
                    $condition_array = [$this->context->get_translator()->trans('New', [], 'Modules.Facetedsearch.Shop') => 'new', $this->context->get_translator()->trans('Used', [], 'Modules.Facetedsearch.Shop') => 'used', $this->context->get_translator()->trans('Refurbished', [], 'Modules.Facetedsearch.Shop') => 'refurbished'];
                    $search_filters[$filter['type']] = [];
                    foreach ($condition_array as $condition_name => $condition_id) {
                        if (isset($received_filters[$filter_label]) && in_array($condition_name, $received_filters[$filter_label])) {
                            $search_filters[$filter['type']][] = $condition_id;
                        }
                    }
                    break;
                case self::TYPE_EXTRAS:
                    if (!isset($received_filters[$filter_label])) {
                        // No need to filter if no information
                        continue 2;
                    }
                    $extras_options = [$this->context->get_translator()->trans('New product', [], 'Modules.Facetedsearch.Shop') => 'new', $this->context->get_translator()->trans('On sale', [], 'Modules.Facetedsearch.Shop') => 'sale', $this->context->get_translator()->trans('Discounted', [], 'Modules.Facetedsearch.Shop') => 'discount'];
                    $search_filters[$filter['type']] = [];
                    foreach ($extras_options as $extras_option => $option_id) {
                        if (isset($received_filters[$filter_label]) && in_array($extras_option, $received_filters[$filter_label])) {
                            $search_filters[$filter['type']][] = $option_id;
                        }
                    }
                    break;
                case self::TYPE_FEATURE:
                    $features = $this->data_accessor->get_features($id_lang);
                    foreach ($features as $feature) {
                        if ($filter['id_value'] != $feature['id_feature']) {
                            continue;
                        }
                        if (isset($received_filters[$feature['url_name']])) {
                            $feature_value_labels = $received_filters[$feature['url_name']];
                        } elseif (isset($received_filters[$feature['name']])) {
                            $feature_value_labels = $received_filters[$feature['name']];
                        } else {
                            continue;
                        }
                        $feature_values = $this->data_accessor->get_feature_values($feature['id_feature'], $id_lang);
                        foreach ($feature_values as $feature_value) {
                            if (in_array($feature_value['url_name'], $feature_value_labels) || in_array($feature_value['value'], $feature_value_labels)) {
                                $search_filters['id_feature'][$feature['id_feature']][] = $feature_value['id_feature_value'];
                            }
                        }
                    }
                    break;
                case self::TYPE_ATTRIBUTE_GROUP:
                    $attributes_group = $this->data_accessor->get_attributes_groups($id_lang);
                    foreach ($attributes_group as $attribute_group) {
                        if ($filter['id_value'] != $attribute_group['id_attribute_group']) {
                            continue;
                        }
                        if (isset($received_filters[$attribute_group['url_name']])) {
                            $attribute_labels = $received_filters[$attribute_group['url_name']];
                        } elseif (isset($received_filters[$attribute_group['attribute_group_name']])) {
                            $attribute_labels = $received_filters[$attribute_group['attribute_group_name']];
                        } else {
                            continue;
                        }
                        $attributes = $this->data_accessor->get_attributes($id_lang, $attribute_group['id_attribute_group']);
                        foreach ($attributes as $attribute) {
                            if (in_array($attribute['url_name'], $attribute_labels) || in_array($attribute['name'], $attribute_labels)) {
                                $search_filters['id_attribute_group'][$attribute_group['id_attribute_group']][] = $attribute['id_attribute'];
                            }
                        }
                    }
                    break;
                case self::TYPE_PRICE:
                case self::TYPE_WEIGHT:
                    if (isset($received_filters[$filter_label])) {
                        $filters = $received_filters[$filter_label];
                        if (isset($filters[1]) && isset($filters[2])) {
                            $from = $filters[1];
                            $to = $filters[2];
                            $search_filters[$filter['type']][0] = $from;
                            $search_filters[$filter['type']][1] = $to;
                        }
                    }
                    break;
                case self::TYPE_CATEGORY:
                    if (isset($received_filters[$filter_label])) {
                        foreach ($received_filters[$filter_label] as $query_filter) {
                            /*
                             * This works only for categories that are child of the category we are browsing (or home category).
                             * Categories deeper in the tree will never be found. This could be fixed by providing a unique ID
                             * to the URL.
                             */
                            $categories = Category::search_by_name_and_parent_category_id($id_lang, $query_filter, $id_category);
                            if ($categories) {
                                $search_filters[$filter['type']][] = $categories['id_category'];
                            }
                        }
                    }
                    break;
                default:
                    if (isset($received_filters[$filter_label])) {
                        foreach ($received_filters[$filter_label] as $query_filter) {
                            $search_filters[$filter['type']][] = $query_filter;
                        }
                    }
            }
        }
        // Remove all empty selected filters
        foreach ($search_filters as $key => $value) {
            switch ($key) {
                case self::TYPE_PRICE:
                case self::TYPE_WEIGHT:
                    if ($value[0] === '' && $value[1] === '') {
                        unset($search_filters[$key]);
                    }
                    break;
                default:
                    if ($value == '' || $value == []) {
                        unset($search_filters[$key]);
                    }
                    break;
            }
        }
        return $search_filters;
    }
    /**
     * Convert filter type to label
     *
     * @param string $filterType
     */
    private function convert_filter_type_to_label($filter_type)
    {
        switch ($filter_type) {
            case self::TYPE_PRICE:
                return $this->context->get_translator()->trans('Price', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_WEIGHT:
                return $this->context->get_translator()->trans('Weight', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_CONDITION:
                return $this->context->get_translator()->trans('Condition', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_EXTRAS:
                return $this->context->get_translator()->trans('Selections', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_AVAILABILITY:
                return $this->context->get_translator()->trans('Availability', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_MANUFACTURER:
                return $this->context->get_translator()->trans('Brand', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_CATEGORY:
                return $this->context->get_translator()->trans('Categories', [], 'Modules.Facetedsearch.Shop');
            case self::TYPE_FEATURE:
            case self::TYPE_ATTRIBUTE_GROUP:
            default:
                return null;
        }
    }
    /**
     * Hide entries with 0 results
     * Hide depending of show limit parameter
     *
     *
     */
    private function hide_zero_values_and_show_limit(array $filters, int $show_limit): array
    {
        $count = 0;
        foreach ($filters as $filter) {
            if ($filter->get_magnitude() === 0 || $show_limit > 0 && $count >= $show_limit) {
                $filter->set_displayed(false);
                continue;
            }
            ++$count;
        }
        return $filters;
    }
    /**
     * Sort filters by magnitude
     *
     *
     * @return int
     */
    private function sort_filters_by_magnitude(Filter $a, Filter $b)
    {
        $a_magnitude = $a->get_magnitude();
        $b_magnitude = $b->get_magnitude();
        if ($a_magnitude == $b_magnitude) {
            // Same magnitude, sort by label
            return $this->sort_filters_by_label($a, $b);
        }
        return $a_magnitude > $b_magnitude ? -1 : +1;
    }
    /**
     * Sort filters by label
     *
     *
     */
    private function sort_filters_by_label(Filter $a, Filter $b): int
    {
        return strnatcasecmp($a->get_label(), $b->get_label());
    }
}