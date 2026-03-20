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

use Doctrine\Common\Collections\Array_Collection;
abstract class Abstract_Adapter implements Interface_Adapter
{
    /**
     * @var ArrayCollection
     */
    protected $filters;
    /**
     * @var ArrayCollection
     */
    protected $operations_filters;
    /**
     * @var ArrayCollection
     */
    protected $select_fields;
    /**
     * @var ArrayCollection
     */
    protected $group_fields;
    protected $order_field = 'id_product';
    protected $order_direction = 'DESC';
    /** @var InterfaceAdapter */
    protected $initial_population;
    public function __construct()
    {
        $this->group_fields = new Array_Collection();
        $this->select_fields = new Array_Collection();
        $this->filters = new Array_Collection();
        $this->operations_filters = new Array_Collection();
    }
    public function __clone()
    {
        $this->filters = clone $this->filters;
        $this->operations_filters = clone $this->operations_filters;
        $this->group_fields = clone $this->group_fields;
        $this->select_fields = clone $this->select_fields;
    }
    /**
     * {@inheritdoc}
     */
    public function get_initial_population()
    {
        return $this->initial_population;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_filter($filter_name)
    {
        if ($this->filters->offsetExists($filter_name)) {
            $this->filters->offsetUnset($filter_name);
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_operations_filter($filter_name)
    {
        if ($this->operations_filters->offsetExists($filter_name)) {
            $this->operations_filters->offsetUnset($filter_name);
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_operations_filters()
    {
        $this->operations_filters = new Array_Collection();
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_all()
    {
        $this->select_fields = new Array_Collection();
        $this->group_fields = new Array_Collection();
        $this->filters = new Array_Collection();
        $this->operations_filters = new Array_Collection();
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function get_filter($filter_name)
    {
        return $this->filters[$filter_name] ?? null;
    }
    /**
     * {@inheritdoc}
     */
    public function get_order_direction()
    {
        return $this->order_direction;
    }
    /**
     * {@inheritdoc}
     */
    public function get_order_field()
    {
        return $this->order_field;
    }
    /**
     * {@inheritdoc}
     */
    public function get_group_fields()
    {
        return $this->group_fields;
    }
    /**
     * {@inheritdoc}
     */
    public function get_select_fields()
    {
        return $this->select_fields;
    }
    /**
     * {@inheritdoc}
     */
    public function get_filters()
    {
        return $this->filters;
    }
    /**
     * {@inheritdoc}
     */
    public function get_operations_filters()
    {
        return $this->operations_filters;
    }
    /**
     * {@inheritdoc}
     */
    public function copy_filters(Interface_Adapter $adapter): void
    {
        $this->filters = clone $adapter->get_filters();
        $this->operations_filters = clone $adapter->get_operations_filters();
    }
    /**
     * {@inheritdoc}
     */
    public function add_filter($filter_name, $values, $operator = '=')
    {
        $filters = $this->filters->get($filter_name);
        if (!isset($filters[$operator])) {
            $filters[$operator] = [];
        }
        $filters[$operator][] = $values;
        $this->filters->set($filter_name, $filters);
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function add_operations_filter($filter_name, array $operations = [])
    {
        $this->operations_filters->set($filter_name, $operations);
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function add_select_field($field_name)
    {
        if (!$this->select_fields->contains($field_name)) {
            $this->select_fields->add($field_name);
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function set_select_fields($select_fields)
    {
        $this->select_fields = new Array_Collection($select_fields);
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_select_field()
    {
        $this->select_fields = new Array_Collection();
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function add_group_by($group_field)
    {
        $this->group_fields->add($group_field);
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function set_group_fields($group_fields)
    {
        $this->group_fields = new Array_Collection($group_fields);
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function reset_group_by()
    {
        $this->group_fields = new Array_Collection();
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function set_filter($filter_name, $value)
    {
        if ($value !== null) {
            $this->filters->set($filter_name, $value);
        }
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function set_order_field($field_name)
    {
        $this->order_field = $field_name;
        return $this;
    }
    /**
     * {@inheritdoc}
     */
    public function set_order_direction($direction)
    {
        $this->order_direction = $direction === 'desc' ? 'desc' : 'asc';
        return $this;
    }
}