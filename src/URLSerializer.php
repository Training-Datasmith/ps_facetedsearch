<?php
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

namespace PrestaShop\Module\FacetedSearch;

use PrestaShop\Module\FacetedSearch\Filters\Converter;
use PrestaShop\PrestaShop\Core\Product\Search\Facet;
use PrestaShop\PrestaShop\Core\Product\Search\Filter;

class URLSerializer
{
    /**
     * Add filter
     *
     *
     */
    public function addFilterToFacetFilters(array $facetFilters, Filter $facetFilter, Facet $facet): array
    {
        $facetLabel = $this->getFacetLabel($facet);
        $filterLabel = $this->getFilterLabel($facetFilter);

        if ($facet->getProperty('range')) {
            $facetValue = $facet->getProperty('values');
            $facetFilters[$facetLabel] = [
                $facetFilter->getProperty('symbol'),
                $facetValue[0] ?? $facet->getProperty('min'),
                $facetValue[1] ?? $facet->getProperty('max'),
            ];
        } else {
            $facetFilters[$facetLabel][$filterLabel] = $filterLabel;
        }

        return $facetFilters;
    }

    /**
     * Remove filter
     *
     * @param Facet $facet
     *
     */
    public function removeFilterFromFacetFilters(array $facetFilters, Filter $facetFilter, $facet): array
    {
        $facetLabel = $this->getFacetLabel($facet);

        if ($facet->getProperty('range')) {
            unset($facetFilters[$facetLabel]);
        } else {
            $filterLabel = $this->getFilterLabel($facetFilter);
            unset($facetFilters[$facetLabel][$filterLabel]);
            if (empty($facetFilters[$facetLabel])) {
                unset($facetFilters[$facetLabel]);
            }
        }

        return $facetFilters;
    }

    /**
     * Get active facet filters
     */
    public function getActiveFacetFiltersFromFacets(array $facets): array
    {
        $facetFilters = [];
        foreach ($facets as $facet) {
            foreach ($facet->getFilters() as $facetFilter) {
                if (!$facetFilter->isActive()) {
                    // Filter is not active
                    continue;
                }

                $facetLabel = $this->getFacetLabel($facet);
                $filterLabel = $this->getFilterLabel($facetFilter);
                if (!$facet->getProperty('range')) {
                    $facetFilters[$facetLabel][$filterLabel] = $filterLabel;
                    continue;
                }

                $facetValue = $facetFilter->getValue();
                $facetFilters[$facetLabel] = [
                    $facetFilter->getProperty('symbol'),
                    $facetValue[0],
                    $facetValue[1],
                ];
            }
        }

        return $facetFilters;
    }

    /**
     * Get Facet label
     *
     *
     * @return string
     */
    private function getFacetLabel(Facet $facet)
    {
        if ($facet->getProperty(Converter::PROPERTY_URL_NAME) !== null) {
            return $facet->getProperty(Converter::PROPERTY_URL_NAME);
        }

        return $facet->getLabel();
    }

    /**
     * Get Facet Filter label
     *
     *
     * @return string
     */
    private function getFilterLabel(Filter $facetFilter)
    {
        if ($facetFilter->getProperty(Converter::PROPERTY_URL_NAME) !== null) {
            return $facetFilter->getProperty(Converter::PROPERTY_URL_NAME);
        }

        return $facetFilter->getLabel();
    }

    /**
     * @return string
     */
    public function serialize(array $fragment)
    {
        $parts = [];
        foreach ($fragment as $key => $values) {
            array_unshift($values, $key);
            $parts[] = $this->serializeListOfStrings($values, '-');
        }

        return $this->serializeListOfStrings($parts, '/');
    }

    /**
     * @param string $string
     */
    public function unserialize($string): array
    {
        $fragment = [];
        $parts = $this->unserializeListOfStrings($string, '/');
        foreach ($parts as $part) {
            $values = $this->unserializeListOfStrings($part, '-');
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
    private function serializeListOfStrings($list, string $separator, $escape = '\\'): string
    {
        return implode($separator, array_map(function ($item) use ($separator, $escape): string {
            return strtr(
                $item,
                [
                    $separator => $escape . $separator,
                ]
            );
        }, $list));
    }

    /**
     * @param string $separator the string separator
     * @param string $escape the string escape
     * @param string $string the UTF8 string
     */
    private function unserializeListOfStrings($string, string $separator, $escape = '\\'): array
    {
        $list = [];
        $currentString = '';
        $escaping = false;

        // get UTF-8 chars, inspired from http://stackoverflow.com/questions/9438158/split-utf8-string-into-array-of-chars
        $arrayOfCharacters = [];
        preg_match_all('/./u', $string, $arrayOfCharacters);
        $characters = $arrayOfCharacters[0];

        foreach ($characters as $index => $character) {
            if ($character === $escape
                && isset($characters[$index + 1])
                && $characters[$index + 1] === $separator
            ) {
                $escaping = true;
                continue;
            }

            if ($character === $separator && $escaping === false) {
                $list[] = $currentString;
                $currentString = '';
                continue;
            }

            $currentString .= $character;
            $escaping = false;
        }

        if ('' !== $currentString) {
            $list[] = $currentString;
        }

        return $list;
    }
}
