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
namespace Presta_Shop\Module\Faceted_Search\Adapter;

interface Interface_Adapter
{
    /**
     * Set order by field
     *
     * @param string $fieldName
     *
     * @return self
     */
    public function set_order_field($field_name);
    /**
     * Set the order by direction for the given field
     *
     * @param string $direction
     *
     * @return self
     */
    public function set_order_direction($direction);
    /**
     * Execute the search
     *
     * @return mixed
     */
    public function execute();
    /**
     * Get the current query
     *
     * @return string
     */
    public function get_query();
    /**
     * Get the min & max value of the field filedName associated with the current search
     *
     * @param string $fieldName
     *
     * @return mixed
     */
    public function get_min_max_value($field_name);
    /**
     * Get the min & max value of the price associated with the current search
     *
     * @return array
     */
    public function get_min_max_price_value();
    /**
     * Return order direction associated with the current search
     *
     * @return mixed
     */
    public function get_order_direction();
    /**
     * Return order field associated with the current search
     *
     * @return mixed
     */
    public function get_order_field();
    /**
     * Return all group fields associated with the current search
     *
     * @return mixed
     */
    public function get_group_fields();
    /**
     * Return all selected fields associated with the current search
     *
     * @return mixed
     */
    public function get_select_fields();
    /**
     * Return all the filters associated with the current search
     *
     * @return mixed
     */
    public function get_filters();
    /**
     * Return all the operations filters associated with the current search
     *
     * @return mixed
     */
    public function get_operations_filters();
    /**
     * Return the number of results associated for the current search
     *
     * @return int
     */
    public function count();
    /**
     * Move the current search into the "initialPopulation"
     * This initialPopulation will be used to generate the first derived table 'FROM (SELECT ...)' in the final query
     * e.g. : SELECT ... FROM (initialPopulation) p JOIN ....
     */
    public function use_filters_as_initial_population();
    /**
     * Create a new SearchAdapter, keeping the initialPopulation of the current Search
     *
     * @param string $resetFilter reset this filter inside the initialPopulation
     * @param bool $skipInitialPopulation if enable, do not copy the initialPopulation filter
     *
     * @return InterfaceAdapter
     */
    public function get_filtered_search_adapter($reset_filter = null, $skip_initial_population = false);
    /**
     * Add a new filter with filterName, operator & values to the current search
     * If several values are provided with the = operator, it's converted automatically to a IN () in the final query
     *
     * @param string $filterName
     * @param array $values
     * @param string $operator
     *
     * @return self
     */
    public function add_filter($filter_name, $values, $operator = '=');
    /**
     * Add a stack of operations with filterName. Operations must contains filterName, values and to the current search
     *
     * @param string $filterName
     *
     * @return self
     */
    public function add_operations_filter($filter_name, array $operations);
    /**
     * Add fieldName in the current search result. If the field already exists, it's skipped.
     *
     * @param string $fieldName
     *
     * @return self
     */
    public function add_select_field($field_name);
    /**
     * Returns the number of distinct products, group by fieldName values
     *
     * @param string $fieldName
     *
     * @return mixed
     */
    public function value_count($field_name = null);
    /**
     * Reset the operations filters
     *
     * @return self
     */
    public function reset_operations_filters();
    /**
     * Reset the operations filter for the given filterName
     *
     * @param string $filterName
     *
     * @return self
     */
    public function reset_operations_filter($filter_name);
    /**
     * Reset the filter for the given filterName
     *
     * @param string $filterName
     *
     * @return self
     */
    public function reset_filter($filter_name);
    /**
     * Return the filter associated with filterName
     *
     * @param string $filterName
     *
     * @return mixed
     */
    public function get_filter($filter_name);
    /**
     * Set the filterName to the given array value
     *
     * @param string $filterName
     * @param mixed $value
     *
     * @return mixed
     */
    public function set_filter($filter_name, $value);
    /**
     * Return the current initialPopulation
     *
     * @return self|null
     */
    public function get_initial_population();
    /**
     * Return all the filters / groupFields / selectFields
     *
     * @return self
     */
    public function reset_all();
    /**
     * Copy all the filters & operationsFilters from adapter to the current search
     */
    public function copy_filters(Interface_Adapter $adapter);
    /**
     * Set all the select fields
     *
     * @param array $selectFields
     *
     * @return self
     */
    public function set_select_fields($select_fields);
    /**
     * Reset all the select fields
     *
     * @return self
     */
    public function reset_select_field();
    /**
     * Add a group by field
     *
     * @param string $groupField
     *
     * @return self
     */
    public function add_group_by($group_field);
    /**
     * Set the group by fields
     *
     * @param array $groupFields
     *
     * @return self
     */
    public function set_group_fields($group_fields);
    /**
     * Reset the group by conditions
     *
     * @return self
     */
    public function reset_group_by();
}