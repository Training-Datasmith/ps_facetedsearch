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

use Configuration;
use Context;
use Db;
use Doctrine\Common\Collections\Array_Collection;
use Product;
use Stock_Available;
class My_Sql extends Abstract_Adapter
{
    /**
     * @var string
     */
    public const TYPE = 'MySQL';
    /**
     * @var string
     */
    public const LEFT_JOIN = 'LEFT JOIN';
    /**
     * @var string
     */
    public const INNER_JOIN = 'INNER JOIN';
    /**
     * {@inheritdoc}
     */
    public function get_min_max_price_value(): array
    {
        $mysql_adapter = $this->get_filtered_search_adapter();
        $mysql_adapter->copy_filters($this);
        $mysql_adapter->set_select_fields(['price_min', 'MIN(price_min) as min, MAX(price_max) as max']);
        $mysql_adapter->set_order_field('');
        $result = $mysql_adapter->execute();
        return [floor((float) $result[0]['min']), ceil((float) $result[0]['max'])];
    }
    /**
     * {@inheritdoc}
     */
    public function get_filtered_search_adapter($reset_filter = null, $skip_initial_population = false): self
    {
        $mysql_adapter = new self();
        if ($this->get_initial_population() !== null && !$skip_initial_population) {
            $mysql_adapter->initial_population = clone $this->get_initial_population();
            if ($reset_filter) {
                // Try to reset filter & operations filter
                $mysql_adapter->initial_population->reset_filter($reset_filter);
                $mysql_adapter->initial_population->reset_operations_filter($reset_filter);
            }
        }
        return $mysql_adapter;
    }
    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        return $this->get_database()->execute_s($this->get_query());
    }
    /**
     * Construct the final sql query
     */
    public function get_query(): string
    {
        // Prepare mapping for joined tables
        $filter_to_table_mapping = $this->get_field_mapping();
        // Process and generate all fields for the SQL query below
        $order_field = $this->compute_order_by_field($filter_to_table_mapping);
        $select_fields = $this->compute_select_fields($filter_to_table_mapping);
        $where_conditions = $this->compute_where_conditions($filter_to_table_mapping);
        $join_conditions = $this->compute_join_conditions($filter_to_table_mapping);
        $group_fields = $this->compute_group_by_fields($filter_to_table_mapping);
        // Now, let's build the query...
        // If this query IS the initial population (the base table), we are selecting from product table
        if ($this->get_initial_population() === null) {
            $reference_table = _DB_PREFIX_ . 'product';
            // If not, we will call this function again but for the initial population
        } else {
            $reference_table = '(' . $this->get_initial_population()->get_query() . ')';
        }
        // Construct the base query
        $query = 'SELECT ' . implode(', ', $select_fields) . ' FROM ' . $reference_table . ' p';
        // Add join conditions if any
        foreach ($join_conditions as $join_alias_infos) {
            foreach ($join_alias_infos as $table_alias => $join_infos) {
                $query .= ' ' . $join_infos['joinType'] . ' ' . _DB_PREFIX_ . $join_infos['tableName'] . ' ' . $table_alias . ' ON ' . $join_infos['joinCondition'];
            }
        }
        // Add where conditions if any
        if (!empty($where_conditions)) {
            $query .= ' WHERE ' . implode(' AND ', $where_conditions);
        }
        // Add groupping
        if (!empty($group_fields)) {
            $query .= ' GROUP BY ' . implode(', ', $group_fields);
        }
        // Add ordering
        if (!empty($order_field)) {
            $query .= ' ORDER BY ' . $order_field;
            /*
             * If the result is not ordered by id_product, we add it as a fallback order,
             * to avoid SQL returning it in random order.
             */
            if (strpos($order_field, 'p.id_product') === false) {
                $query .= ', p.id_product DESC';
            }
        }
        return $query;
    }
    /**
     * Define the mapping between fields and tables
     */
    protected function get_field_mapping(): array
    {
        $stock_condition = Stock_Available::add_sql_shop_restriction(null, null, 'sa');
        return ['id_product_attribute' => ['tableName' => 'product_attribute', 'tableAlias' => 'pa', 'joinCondition' => '(p.id_product = pa.id_product)', 'joinType' => self::LEFT_JOIN], 'id_attribute' => ['tableName' => 'product_attribute_combination', 'tableAlias' => 'pac', 'joinCondition' => '(pa.id_product_attribute = pac.id_product_attribute)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_product_attribute'], 'id_attribute_group' => ['tableName' => 'attribute', 'tableAlias' => 'a', 'joinCondition' => '(a.id_attribute = pac.id_attribute)', 'joinType' => self::INNER_JOIN, 'dependencyField' => 'id_attribute'], 'id_feature' => ['tableName' => 'feature_product', 'tableAlias' => 'fp', 'joinCondition' => '(p.id_product = fp.id_product)', 'joinType' => self::INNER_JOIN], 'id_shop' => ['tableName' => 'product_shop', 'tableAlias' => 'ps', 'joinCondition' => '(p.id_product = ps.id_product AND ps.id_shop = ' . $this->get_context()->shop->id . ' AND ps.active = TRUE)', 'joinType' => self::INNER_JOIN], 'visibility' => ['tableName' => 'product_shop', 'tableAlias' => 'ps', 'joinCondition' => '(p.id_product = ps.id_product AND ps.id_shop = ' . $this->get_context()->shop->id . ' AND ps.active = TRUE)', 'joinType' => self::INNER_JOIN], 'id_feature_value' => ['tableName' => 'feature_product', 'tableAlias' => 'fp', 'joinCondition' => '(p.id_product = fp.id_product)', 'joinType' => self::LEFT_JOIN], 'id_category' => ['tableName' => 'category_product', 'tableAlias' => 'cp', 'joinCondition' => '(p.id_product = cp.id_product)', 'joinType' => self::INNER_JOIN], 'position' => ['tableName' => 'category_product', 'tableAlias' => 'cp', 'joinCondition' => '(p.id_product = cp.id_product)', 'joinType' => self::INNER_JOIN], 'manufacturer_name' => ['tableName' => 'manufacturer', 'tableAlias' => 'm', 'fieldName' => 'name', 'joinCondition' => '(p.id_manufacturer = m.id_manufacturer)', 'joinType' => self::LEFT_JOIN], 'name' => ['tableName' => 'product_lang', 'tableAlias' => 'pl', 'joinCondition' => '(p.id_product = pl.id_product AND pl.id_shop = ' . $this->get_context()->shop->id . ' AND pl.id_lang = ' . $this->get_context()->language->id . ')', 'joinType' => self::INNER_JOIN], 'nleft' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::INNER_JOIN, 'dependencyField' => 'id_category'], 'nright' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::INNER_JOIN, 'dependencyField' => 'id_category'], 'level_depth' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::INNER_JOIN, 'dependencyField' => 'id_category'], 'out_of_stock' => ['tableName' => 'stock_available', 'tableAlias' => 'sa', 'joinCondition' => '(p.id_product = sa.id_product AND IFNULL(pac.id_product_attribute, 0) = sa.id_product_attribute' . $stock_condition . ')', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_attribute'], 'quantity' => ['tableName' => 'stock_available', 'tableAlias' => 'sa', 'joinCondition' => '(p.id_product = sa.id_product AND IFNULL(pac.id_product_attribute, 0) = sa.id_product_attribute' . $stock_condition . ')', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_attribute', 'aggregateFunction' => 'SUM', 'aggregateFieldName' => 'quantity'], 'price_min' => ['tableName' => 'layered_price_index', 'tableAlias' => 'psi', 'joinCondition' => '(psi.id_product = p.id_product AND psi.id_shop = ' . $this->get_context()->shop->id . ' AND psi.id_currency = ' . $this->get_context()->currency->id . ' AND psi.id_country = ' . $this->get_context()->country->id . ')', 'joinType' => self::INNER_JOIN], 'price_max' => ['tableName' => 'layered_price_index', 'tableAlias' => 'psi', 'joinCondition' => '(psi.id_product = p.id_product AND psi.id_shop = ' . $this->get_context()->shop->id . ' AND psi.id_currency = ' . $this->get_context()->currency->id . ' AND psi.id_country = ' . $this->get_context()->country->id . ')', 'joinType' => self::INNER_JOIN], 'range_start' => ['tableName' => 'layered_price_index', 'tableAlias' => 'psi', 'joinCondition' => '(psi.id_product = p.id_product AND psi.id_shop = ' . $this->get_context()->shop->id . ' AND psi.id_currency = ' . $this->get_context()->currency->id . ' AND psi.id_country = ' . $this->get_context()->country->id . ')', 'joinType' => self::INNER_JOIN], 'range_end' => ['tableName' => 'layered_price_index', 'tableAlias' => 'psi', 'joinCondition' => '(psi.id_product = p.id_product AND psi.id_shop = ' . $this->get_context()->shop->id . ' AND psi.id_currency = ' . $this->get_context()->currency->id . ' AND psi.id_country = ' . $this->get_context()->country->id . ')', 'joinType' => self::INNER_JOIN], 'id_group' => ['tableName' => 'category_group', 'tableAlias' => 'cg', 'joinCondition' => '(cg.id_category = c.id_category)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'nleft'], 'sales' => ['tableName' => 'product_sale', 'tableAlias' => 'psales', 'fieldName' => 'quantity', 'fieldAlias' => 'sales', 'joinCondition' => '(psales.id_product = p.id_product)', 'joinType' => self::LEFT_JOIN], 'reduction' => ['tableName' => 'specific_price', 'tableAlias' => 'sp', 'joinCondition' => '(
                    sp.id_product = p.id_product AND 
                    sp.id_shop IN (0, ' . $this->get_context()->shop->id . ') AND 
                    sp.id_currency IN (0, ' . $this->get_context()->currency->id . ') AND 
                    sp.id_country IN (0, ' . $this->get_context()->country->id . ') AND 
                    sp.id_group IN (0, ' . $this->get_context()->customer->id_default_group . ') AND 
                    sp.from_quantity = 1 AND
                    sp.reduction > 0 AND
                    sp.id_customer = 0 AND
                    sp.id_cart = 0 AND 
                    (sp.from = \'0000-00-00 00:00:00\' OR \'' . date('Y-m-d H:i:s') . '\' >= sp.from) AND 
                    (sp.to = \'0000-00-00 00:00:00\' OR \'' . date('Y-m-d H:i:s') . '\' <= sp.to) 
                )', 'joinType' => self::LEFT_JOIN]];
    }
    /**
     * Get the joined and escaped value from an multi-dimensional array
     *
     * @param string $separator
     *
     * @return string Escaped string value
     */
    protected function get_joined_escaped_value($separator, array $values): string
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->get_joined_escaped_value($separator, $value);
            } elseif (is_numeric($value)) {
                $values[$key] = p_sql($value);
            } else {
                $values[$key] = "'" . p_sql($value) . "'";
            }
        }
        return implode($separator, $values);
    }
    /**
     * Compute the orderby fields, adding the proper alias that will be added to the final query
     *
     *
     * @return string
     */
    protected function compute_order_by_field(array $filter_to_table_mapping)
    {
        // First, we get the order field from the current instance. That can be strings like 'price', 'name', 'position', etc.
        $order_field = $this->get_order_field();
        // If it's empty, we just return it as is, nothing to do. This is usually a case when getting products
        // for available filters, they reset the order field so we save performance
        if (empty($order_field)) {
            return $order_field;
        }
        // If we have an initial population, add the field into initial population selects, so we can use it in the outer query for sorting
        if ($this->get_initial_population() !== null) {
            $this->get_initial_population()->add_select_field($order_field);
        }
        // Alter order by field if it's a price column
        if ($order_field === 'price') {
            $order_field = $this->get_order_direction() === 'asc' ? 'price_min' : 'price_max';
        }
        // Do not try to process the orderField if it already has an alias, or if it's a group function
        // We just append the order direction and return it
        if (strpos($order_field, '.') !== false || strpos($order_field, '(') !== false) {
            return $order_field . ' ' . strtoupper($this->get_order_direction());
        }
        // In all other cases, add table mapping or p. prefix depending on field type
        $order_field = $this->compute_field_name($order_field, $filter_to_table_mapping, true);
        /*
         * Do not try to process the orderField if it's a search page. We will use manually constructed list
         * to order products by their position in the search results we got from the core, with inverted order
         */
        if ($order_field == 'p.position' && !empty($this->get_initial_population()->get_filters()['id_product']['='][0])) {
            return 'FIELD(p.id_product,' . implode(',', $this->get_initial_population()->get_filters()['id_product']['='][0]) . ') ' . ($this->get_order_direction() === 'asc' ? 'DESC' : 'ASC');
        }
        // Alter order by field and add some products to the end of the list, if required
        $order_field = $this->compute_show_last($order_field, $filter_to_table_mapping);
        // Add sort order
        $order_field .= ' ' . strtoupper($this->get_order_direction());
        // And return it
        return $order_field;
    }
    /**
     * Sort product list: InStock, OOPS with qty 0, OutOfStock
     *
     * @param array $filterToTableMapping
     *
     */
    protected function compute_show_last(string $order_field, $filter_to_table_mapping): string
    {
        // allow only if feature is enabled & it is main product list query (caller ensures $orderField is non-empty)
        if ($this->get_initial_population() === null || !Configuration::get('PS_LAYERED_FILTER_SHOW_OUT_OF_STOCK_LAST')) {
            return $order_field;
        }
        $this->add_select_field('out_of_stock');
        // order by out-of-stock last
        $computed_quantity_field = $this->compute_field_name('quantity', $filter_to_table_mapping);
        $by_out_of_stock_last = 'IFNULL(' . $computed_quantity_field . ', 0) <= 0';
        /**
         * Default behaviour when out of stock
         * 0 - when deny orders
         * 1 - when allow orders
         */
        $is_available_when_out_of_stock = (int) Product::is_available_when_out_of_stock(2);
        // computing values for order by 'allow to order last'
        $computed_field = $this->compute_field_name('out_of_stock', $filter_to_table_mapping);
        $computed_value = $is_available_when_out_of_stock ? 0 : 1;
        $computed_direction = $is_available_when_out_of_stock ? 'ASC' : 'DESC';
        // query: products with zero or less quantity and not available to order go to the end
        $by_oops = str_replace([':byOutOfStockLast', ':field', ':value', ':direction'], [$by_out_of_stock_last, $computed_field, $computed_value, $computed_direction], ':byOutOfStockLast AND FIELD(:field, :value) :direction');
        return $by_out_of_stock_last . ', ' . $by_oops . ', ' . $order_field;
    }
    /**
     * Add alias to table field name
     *
     * @param string $fieldName
     *
     * @return string Table Field name with an alias
     */
    protected function compute_field_name($field_name, array $filter_to_table_mapping, $sort_by_field = false): string
    {
        if (array_key_exists($field_name, $filter_to_table_mapping) && (isset($filter_to_table_mapping[$field_name]['fieldName']) || $this->get_initial_population() === null || !$this->get_initial_population()->get_select_fields()->contains($field_name))) {
            $join_mapping = $filter_to_table_mapping[$field_name];
            $field_name = $join_mapping['tableAlias'] . '.' . ($join_mapping['fieldName'] ?? $field_name);
            if ($sort_by_field === false) {
                $field_name .= isset($join_mapping['fieldAlias']) ? ' as ' . $join_mapping['fieldAlias'] : '';
            }
            if (isset($join_mapping['aggregateFunction'], $join_mapping['aggregateFieldName'])) {
                $field_name = $join_mapping['aggregateFunction'] . '(' . $field_name . ') as ' . $join_mapping['aggregateFieldName'];
            }
        } else if (strpos($field_name, '(') === false) {
            $field_name = 'p.' . $field_name;
        }
        return $field_name;
    }
    /**
     * Compute the select fields, adding the proper alias that will be added to the final query
     *
     *
     */
    protected function compute_select_fields(array $filter_to_table_mapping): array
    {
        // Add already added select fields to current query
        $select_fields = [];
        foreach ($this->get_select_fields() as $select_field) {
            $select_fields[] = $this->compute_field_name($select_field, $filter_to_table_mapping);
        }
        return $select_fields;
    }
    /**
     * Computer the where conditions that will be added to the final query
     *
     *
     */
    protected function compute_where_conditions(array $filter_to_table_mapping): array
    {
        $where_conditions = [];
        $operation_idx = 0;
        foreach ($this->get_operations_filters() as $filter_name => $filter_operations) {
            $operations_conditions = [];
            foreach ($filter_operations as $operations) {
                $conditions = [];
                foreach ($operations as $idx => $operation) {
                    $select_alias = 'p';
                    $values = $operation[1];
                    if (array_key_exists($operation[0], $filter_to_table_mapping)) {
                        $join_mapping = $filter_to_table_mapping[$operation[0]];
                        // If index is not the first, append to the table alias for
                        // multi join
                        $select_alias = $join_mapping['tableAlias'] . ($operation_idx === 0 ? '' : '_' . $operation_idx) . ($idx === 0 ? '' : '_' . $idx);
                        $operation[0] = $join_mapping['fieldName'] ?? $operation[0];
                    }
                    if (count($values) === 1) {
                        $operator = !empty($operation[2]) ? $operation[2] : '=';
                        $conditions[] = $select_alias . '.' . $operation[0] . $operator . current($values);
                    } else {
                        $conditions[] = $select_alias . '.' . $operation[0] . ' IN (' . $this->get_joined_escaped_value(', ', $values) . ')';
                    }
                }
                $operations_conditions[] = '(' . implode(' AND ', $conditions) . ')';
            }
            ++$operation_idx;
            if (!empty($operations_conditions)) {
                $where_conditions[] = '(' . implode(' OR ', $operations_conditions) . ')';
            }
        }
        foreach ($this->get_filters() as $filter_name => $filter_content) {
            $select_alias = 'p';
            if (array_key_exists($filter_name, $filter_to_table_mapping)) {
                $join_mapping = $filter_to_table_mapping[$filter_name];
                $select_alias = $join_mapping['tableAlias'];
                $filter_name = $join_mapping['fieldName'] ?? $filter_name;
            }
            foreach ($filter_content as $operator => $values) {
                if (count($values) == 1) {
                    $values = current($values);
                    if ($operator === '=') {
                        if (count($values) == 1) {
                            $where_conditions[] = $select_alias . '.' . $filter_name . $operator . "'" . current($values) . "'";
                        } else {
                            $where_conditions[] = $select_alias . '.' . $filter_name . ' IN (' . $this->get_joined_escaped_value(', ', $values) . ')';
                        }
                    } else {
                        $or_conditions = [];
                        foreach ($values as $value) {
                            $or_conditions[] = $select_alias . '.' . $filter_name . $operator . $value;
                        }
                        $where_conditions[] = implode(' OR ', $or_conditions);
                    }
                }
            }
        }
        // if we have several "groups" of the same filter, we need to use the intersect of the matching products
        // e.g. : mix of id_feature like Composition & Styles
        $id_filtered_products = null;
        foreach ($this->get_filters() as $filter_name => $filter_content) {
            foreach ($filter_content as $operator => $filter_values) {
                if (count($filter_values) <= 1) {
                    continue;
                }
                $id_tmp_filtered_products = [];
                $mysql_adapter = $this->get_filtered_search_adapter();
                $mysql_adapter->add_select_field('id_product');
                $mysql_adapter->set_order_field('');
                $mysql_adapter->add_filter($filter_name, $filter_values, $operator);
                $id_products = $mysql_adapter->execute();
                foreach ($id_products as $id_product) {
                    $id_tmp_filtered_products[] = $id_product['id_product'];
                }
                if ($id_filtered_products === null) {
                    $id_filtered_products = $id_tmp_filtered_products;
                } else {
                    $id_filtered_products += array_intersect($id_filtered_products, $id_tmp_filtered_products);
                }
                if (empty($id_filtered_products)) {
                    // set it to 0 to make sure no result will be returned
                    $id_filtered_products[] = 0;
                    break;
                }
                $where_conditions[] = 'p.id_product IN (' . implode(', ', $id_filtered_products) . ')';
            }
        }
        return $where_conditions;
    }
    /**
     * Compute the joinConditions needed depending on the fields required in select, where, groupby & orderby fields
     *
     *
     */
    protected function compute_join_conditions(array $filter_to_table_mapping): \Doctrine\Common\Collections\Array_Collection
    {
        $join_list = new Array_Collection();
        $this->add_join_list($join_list, $this->get_select_fields(), $filter_to_table_mapping);
        $this->add_join_list($join_list, $this->get_filters()->get_keys(), $filter_to_table_mapping);
        $operation_idx = 0;
        foreach ($this->get_operations_filters() as $filter_operations) {
            foreach ($filter_operations as $operations) {
                foreach ($operations as $idx => $operation) {
                    if (array_key_exists($operation[0], $filter_to_table_mapping)) {
                        $join_mapping = $filter_to_table_mapping[$operation[0]];
                        if ($idx !== 0 || $operation_idx !== 0) {
                            // Index is not the first, append index to tableAlias on joinCondition
                            $join_mapping['joinCondition'] = preg_replace('~([\(\s=]' . $join_mapping['tableAlias'] . ')\.~', '${1}' . ($operation_idx === 0 ? '' : '_' . $operation_idx) . ($idx === 0 ? '' : '_' . $idx) . '.', $join_mapping['joinCondition']);
                            $join_mapping['tableAlias'] .= ($operation_idx === 0 ? '' : '_' . $operation_idx) . ($idx === 0 ? '' : '_' . $idx);
                        }
                        $this->add_join_conditions($join_list, $join_mapping, $filter_to_table_mapping);
                    }
                }
            }
            ++$operation_idx;
        }
        $this->add_join_list($join_list, $this->get_group_fields()->get_keys(), $filter_to_table_mapping);
        if (array_key_exists($this->get_order_field(), $filter_to_table_mapping)) {
            $join_mapping = $filter_to_table_mapping[$this->get_order_field()];
            $this->add_join_conditions($join_list, $join_mapping, $filter_to_table_mapping);
        }
        return $join_list;
    }
    /**
     * Helper to add tables infos to the join list.
     *
     * @param array|ArrayCollection $list
     */
    private function add_join_list(Array_Collection $join_list, $list, array $filter_to_table_mapping): void
    {
        foreach ($list as $field) {
            if (array_key_exists($field, $filter_to_table_mapping)) {
                $join_mapping = $filter_to_table_mapping[$field];
                $this->add_join_conditions($join_list, $join_mapping, $filter_to_table_mapping);
            }
        }
    }
    /**
     * Add the required table infos to the join list, taking care of the dependent tables
     */
    private function add_join_conditions(Array_Collection $join_list, array $join_mapping, array $filter_to_table_mapping): void
    {
        if (array_key_exists('dependencyField', $join_mapping)) {
            $dependency_join_mapping = $filter_to_table_mapping[$join_mapping['dependencyField']];
            $this->add_join_conditions($join_list, $dependency_join_mapping, $filter_to_table_mapping);
        }
        $join_infos[$join_mapping['tableAlias']] = ['tableName' => $join_mapping['tableName'], 'joinCondition' => $join_mapping['joinCondition'], 'joinType' => $join_mapping['joinType']];
        $join_list->set($join_mapping['tableAlias'] . '_' . $join_mapping['tableName'], $join_infos);
    }
    /**
     * Compute the groupby condition, adding the proper alias that will be added to the final query
     *
     *
     */
    private function compute_group_by_fields(array $filter_to_table_mapping): array
    {
        $group_fields = [];
        if ($this->get_group_fields()->is_empty()) {
            return $group_fields;
        }
        foreach ($this->get_group_fields() as $key => $values) {
            if (strpos($values, '.') !== false || strpos($values, '(') !== false) {
                $group_fields[$key] = $values;
                continue;
            }
            if (array_key_exists($values, $filter_to_table_mapping)) {
                $join_mapping = $filter_to_table_mapping[$values];
                $group_fields[$key] = $join_mapping['tableAlias'] . '.' . $values;
            } else {
                $group_fields[$key] = 'p.' . $values;
            }
        }
        return $group_fields;
    }
    /**
     * {@inheritdoc}
     */
    public function get_min_max_value($field_name): array
    {
        $mysql_adapter = $this->get_filtered_search_adapter();
        $mysql_adapter->copy_filters($this);
        $mysql_adapter->set_select_fields(['MIN(' . $field_name . ') as min, MAX(' . $field_name . ') as max']);
        $mysql_adapter->set_order_field('');
        $result = $mysql_adapter->execute();
        return [(float) $result[0]['min'], (float) $result[0]['max']];
    }
    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        $mysql_adapter = $this->get_filtered_search_adapter();
        $mysql_adapter->copy_filters($this);
        $result = $mysql_adapter->value_count();
        return isset($result[0]['c']) ? (int) $result[0]['c'] : 0;
    }
    /**
     * {@inheritdoc}
     */
    public function value_count($field_name = null)
    {
        $this->reset_group_by();
        if ($field_name !== null) {
            $this->add_group_by($field_name);
            $this->add_select_field($field_name);
        }
        $this->add_select_field('COUNT(DISTINCT p.id_product) c');
        $this->set_order_field('');
        $this->copy_operations_filters();
        return $this->execute();
    }
    /**
     * {@inheritdoc}
     */
    public function use_filters_as_initial_population(): void
    {
        // Initial population has no ORDER BY
        $this->set_order_field('');
        // We add basic select fields we will need to matter what
        $this->set_select_fields(['id_product', 'id_manufacturer', 'quantity', 'condition', 'weight', 'price', 'sales', 'on_sale', 'date_add']);
        // Clone it, add it to initial population
        $this->initial_population = clone $this;
        // Reset all filters so we start clean and add only the base select, we don't need anything else
        $this->reset_all();
        $this->add_select_field('id_product');
    }
    /**
     * @return Context
     */
    protected function get_context()
    {
        return Context::get_context();
    }
    /**
     * @return Db
     */
    protected function get_database()
    {
        return Db::get_instance();
    }
    /**
     * Copy stock management operation filters
     * to make sure quantity is also used
     */
    protected function copy_operations_filters()
    {
        $initial_population = $this->get_initial_population();
        if (null === $initial_population) {
            return;
        }
        $operations_filters = clone $initial_population->get_operations_filters();
        foreach ($operations_filters as $operation_name => $operations) {
            $this->add_operations_filter($operation_name, $operations);
        }
    }
}