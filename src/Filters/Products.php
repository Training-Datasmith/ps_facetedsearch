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

use Configuration;
use Presta_Shop\Module\Faceted_Search\Adapter\Abstract_Adapter;
use Presta_Shop\Module\Faceted_Search\Product\Search;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
use Product;
use Validate;
class Products
{
    /**
     * Use price tax filter
     *
     * @var bool
     */
    private $ps_layered_filter_price_usetax;
    /**
     * Use price rounding
     *
     * @var bool
     */
    private $ps_layered_filter_price_rounding;
    /**
     * @var AbstractAdapter
     */
    private $search_adapter;
    public function __construct(Search $product_search)
    {
        $this->search_adapter = $product_search->get_search_adapter();
    }
    /**
     * Get the products associated with the current filters.
     *
     *
     */
    public function get_product_by_filters(Product_Search_Query $query, array $selected_filters = []): array
    {
        // Load sorting type and direction, validate it and apply fallback if needed
        $order_by = $query->get_sort_order()->to_legacy_order_by(false);
        $order_way = $query->get_sort_order()->to_legacy_order_way();
        $order_way = Validate::is_order_way($order_way) ? $order_way : 'ASC';
        $order_by = Validate::is_order_by($order_by) ? $order_by : 'position';
        // Apply it to the filter
        $this->search_adapter->set_order_field($order_by);
        $this->search_adapter->set_order_direction($order_way);
        $this->search_adapter->add_group_by('id_product');
        if (isset($selected_filters['price']) || $order_by === 'price') {
            $this->search_adapter->add_select_field('id_product');
            $this->search_adapter->add_select_field('price');
            $this->search_adapter->add_select_field('price_min');
            $this->search_adapter->add_select_field('price_max');
        }
        // Get full list of matching products
        $full_product_list = $this->search_adapter->execute();
        // Count them
        $total_product_count = count($full_product_list);
        // Get pagination
        $products_per_page = (int) $query->get_results_per_page();
        $page = (int) $query->get_page();
        // Cut them down by pagination
        $final_product_list = array_slice($full_product_list, ($page - 1) * $products_per_page, $products_per_page);
        // And run post filter
        $this->price_post_filtering($final_product_list, $selected_filters);
        return ['products' => $final_product_list, 'count' => $total_product_count];
    }
    /**
     * Post filter product depending on the price and a few extra config variables
     */
    private function price_post_filtering(array &$matching_product_list, array $selected_filters): void
    {
        if (!isset($selected_filters['price'])) {
            return;
        }
        $price_filter['min'] = (float) $selected_filters['price'][0];
        $price_filter['max'] = (float) $selected_filters['price'][1];
        if ($this->ps_layered_filter_price_usetax === null) {
            $this->ps_layered_filter_price_usetax = (bool) Configuration::get('PS_LAYERED_FILTER_PRICE_USETAX');
        }
        if ($this->ps_layered_filter_price_rounding === null) {
            $this->ps_layered_filter_price_rounding = (bool) Configuration::get('PS_LAYERED_FILTER_PRICE_ROUNDING');
        }
        if ($this->ps_layered_filter_price_usetax || $this->ps_layered_filter_price_rounding) {
            $this->filter_price($matching_product_list, $this->ps_layered_filter_price_usetax, $this->ps_layered_filter_price_rounding, $price_filter);
        }
    }
    /**
     * Remove products from the product list in case of price postFiltering
     *
     * @param bool $psLayeredFilterPriceUsetax
     * @param bool $psLayeredFilterPriceRounding
     */
    private function filter_price(array &$matching_product_list, $ps_layered_filter_price_usetax, $ps_layered_filter_price_rounding, array $price_filter): void
    {
        /* for this case, price could be out of range, so we need to compute the real price */
        foreach ($matching_product_list as $key => $product) {
            if ($product['price_min'] < (int) $price_filter['min'] && $product['price_max'] > (int) $price_filter['min'] || $product['price_max'] > (int) $price_filter['max'] && $product['price_min'] < (int) $price_filter['max']) {
                $price = Product::get_price_static($product['id_product'], $ps_layered_filter_price_usetax);
                if ($ps_layered_filter_price_rounding) {
                    $price = (int) $price;
                }
                if ($price < $price_filter['min'] || $price > $price_filter['max']) {
                    // out of range price, exclude the product
                    unset($matching_product_list[$key]);
                }
            }
        }
    }
}