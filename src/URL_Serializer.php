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
namespace Presta_Shop\Module\Faceted_Search;

use Presta_Shop\Module\Faceted_Search\Filters\Converter;
use Presta_Shop\Presta_Shop\Core\Product\Search\Facet;
use Presta_Shop\Presta_Shop\Core\Product\Search\Filter;
class Url_Serializer
{
    /**
     * Add filter
     *
     *
     */
    public function add_filter_to_facet_filters(array $facet_filters, Filter $facet_filter, Facet $facet): array
    {
        $facet_label = $this->get_facet_label($facet);
        $filter_label = $this->get_filter_label($facet_filter);
        if ($facet->get_property('range')) {
            $facet_value = $facet->get_property('values');
            $facet_filters[$facet_label] = [$facet_filter->get_property('symbol'), $facet_value[0] ?? $facet->get_property('min'), $facet_value[1] ?? $facet->get_property('max')];
        } else {
            $facet_filters[$facet_label][$filter_label] = $filter_label;
        }
        return $facet_filters;
    }
    /**
     * Remove filter
     *
     * @param Facet $facet
     *
     */
    public function remove_filter_from_facet_filters(array $facet_filters, Filter $facet_filter, $facet): array
    {
        $facet_label = $this->get_facet_label($facet);
        if ($facet->get_property('range')) {
            unset($facet_filters[$facet_label]);
        } else {
            $filter_label = $this->get_filter_label($facet_filter);
            unset($facet_filters[$facet_label][$filter_label]);
            if (empty($facet_filters[$facet_label])) {
                unset($facet_filters[$facet_label]);
            }
        }
        return $facet_filters;
    }
    /**
     * Get active facet filters
     */
    public function get_active_facet_filters_from_facets(array $facets): array
    {
        $facet_filters = [];
        foreach ($facets as $facet) {
            foreach ($facet->get_filters() as $facet_filter) {
                if (!$facet_filter->is_active()) {
                    // Filter is not active
                    continue;
                }
                $facet_label = $this->get_facet_label($facet);
                $filter_label = $this->get_filter_label($facet_filter);
                if (!$facet->get_property('range')) {
                    $facet_filters[$facet_label][$filter_label] = $filter_label;
                    continue;
                }
                $facet_value = $facet_filter->get_value();
                $facet_filters[$facet_label] = [$facet_filter->get_property('symbol'), $facet_value[0], $facet_value[1]];
            }
        }
        return $facet_filters;
    }
    /**
     * Get Facet label
     *
     *
     * @return string
     */
    private function get_facet_label(Facet $facet)
    {
        if ($facet->get_property(Converter::PROPERTY_URL_NAME) !== null) {
            return $facet->get_property(Converter::PROPERTY_URL_NAME);
        }
        return $facet->get_label();
    }
    /**
     * Get Facet Filter label
     *
     *
     * @return string
     */
    private function get_filter_label(Filter $facet_filter)
    {
        if ($facet_filter->get_property(Converter::PROPERTY_URL_NAME) !== null) {
            return $facet_filter->get_property(Converter::PROPERTY_URL_NAME);
        }
        return $facet_filter->get_label();
    }
    /**
     * @return string
     */
    public function serialize(array $fragment)
    {
        $parts = [];
        foreach ($fragment as $key => $values) {
            array_unshift($values, $key);
            $parts[] = $this->serialize_list_of_strings($values, '-');
        }
        return $this->serialize_list_of_strings($parts, '/');
    }
    /**
     * @param string $string
     */
    public function unserialize($string): array
    {
        $fragment = [];
        $parts = $this->unserialize_list_of_strings($string, '/');
        foreach ($parts as $part) {
            $values = $this->unserialize_list_of_strings($part, '-');
            $key = array_shift($values);
            $fragment[$key] = $values;
        }
        return $fragment;
    }
    /**
     * @param string $separator the string separator
     * @param string $escape the string escape
     * @param array $list
     */
    private function serialize_list_of_strings($list, string $separator, $escape = '\\'): string
    {
        return implode($separator, array_map(function ($item) use ($separator, $escape): string {
            return strtr($item, [$separator => $escape . $separator]);
        }, $list));
    }
    /**
     * @param string $separator the string separator
     * @param string $escape the string escape
     * @param string $string the UTF8 string
     */
    private function unserialize_list_of_strings($string, string $separator, $escape = '\\'): array
    {
        $list = [];
        $current_string = '';
        $escaping = false;
        // get UTF-8 chars, inspired from http://stackoverflow.com/questions/9438158/split-utf8-string-into-array-of-chars
        $array_of_characters = [];
        preg_match_all('/./u', $string, $array_of_characters);
        $characters = $array_of_characters[0];
        foreach ($characters as $index => $character) {
            if ($character === $escape && isset($characters[$index + 1]) && $characters[$index + 1] === $separator) {
                $escaping = true;
                continue;
            }
            if ($character === $separator && $escaping === false) {
                $list[] = $current_string;
                $current_string = '';
                continue;
            }
            $current_string .= $character;
            $escaping = false;
        }
        if ('' !== $current_string) {
            $list[] = $current_string;
        }
        return $list;
    }
}