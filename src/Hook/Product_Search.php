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
namespace Presta_Shop\Module\Faceted_Search\Hook;

use Configuration;
use Presta_Shop\Module\Faceted_Search\Filters\Converter;
use Presta_Shop\Module\Faceted_Search\Filters\Data_Accessor;
use Presta_Shop\Module\Faceted_Search\Filters\Provider;
use Presta_Shop\Module\Faceted_Search\Product\Search_Factory;
use Presta_Shop\Module\Faceted_Search\Product\Search_Provider;
use Presta_Shop\Module\Faceted_Search\Url_Serializer;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
use Presta_Shop\Presta_Shop\Core\Product\Search\Sort_Order;
class Product_Search extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = ['productSearchProvider'];
    /**
     * This method returns the search provider to the controller who requested it.
     *
     *
     */
    public function product_search_provider(array $params): ?\Presta_Shop\Module\Faceted_Search\Product\Search_Provider
    {
        /*
         * Backward compatibility, required for versions < 8.0
         * We need to assign missing queryType to some controllers, which don't report it.
         * Remove when module minimum compatibility reaches 8.0.
         */
        if (empty($params['query']->get_query_type())) {
            $params['query'] = $this->assign_missing_query_type($params['query']);
        }
        /*
         * Check if the type of query (controller) is supported by our module. If not, we
         * let the core do the search.
         */
        if ($this->module->is_controller_supported($params['query']->get_query_type()) === false) {
            return null;
        }
        // Initialize provider, we will need it right away to check if there are filters setup
        $provider = new Provider($this->module->get_database());
        /*
         * If search controller is not specifically enabled, we don't return the instance.
         * This condition will be removed when search controller support is fully implemented.
         */
        if ($params['query']->get_query_type() === 'search' && empty($provider->get_filters_for_query($params['query'], (int) $this->context->shop->id))) {
            return null;
        }
        /*
         * Fix wrong reporting of desired best sales order. BestSalesProductSearchProvider overrides
         * the sort set on the query in BestSalesControllerCore.
         */
        if ($params['query']->get_query_type() == 'best-sales') {
            $params['query']->set_sort_order(new Sort_Order('product', 'sales', 'desc'));
        }
        // Assign assets
        if ((bool) Configuration::get('PS_USE_JQUERY_UI_SLIDER')) {
            $this->context->controller->add_jquery_ui('ui.slider');
        }
        $this->context->controller->register_stylesheet('facetedsearch_front', '/modules/ps_facetedsearch/views/dist/front.css');
        $this->context->controller->register_javascript('facetedsearch_front', '/modules/ps_facetedsearch/views/dist/front.js', ['position' => 'bottom', 'priority' => 100]);
        $url_serializer = new Url_Serializer();
        $data_accessor = new Data_Accessor($this->module->get_database());
        // Return an instance of our searcher, ready to accept requests
        return new Search_Provider($this->module, new Converter($this->module->get_context(), $this->module->get_database(), $url_serializer, $data_accessor, $provider), $url_serializer, $data_accessor, new Search_Factory(), $provider);
    }
    /**
     * Assign missing queryType, required for PS versions < 8.0
     *
     *
     */
    private function assign_missing_query_type(Product_Search_Query $query): Product_Search_Query
    {
        if (!empty($query->get_id_category())) {
            $query->set_query_type('category');
        } elseif (!empty($query->get_id_manufacturer())) {
            $query->set_query_type('manufacturer');
        } elseif (!empty($query->get_id_supplier())) {
            $query->set_query_type('supplier');
        } elseif (!empty($query->get_search_string()) || !empty($query->get_search_tag())) {
            $query->set_query_type('search');
        }
        return $query;
    }
}