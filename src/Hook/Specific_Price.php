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

class Specific_Price extends Abstract_Hook
{
    /**
     * @var array
     */
    protected $products_before;
    public const AVAILABLE_HOOKS = ['actionObjectSpecificPriceRuleUpdateBefore', 'actionAdminSpecificPriceRuleControllerSaveAfter'];
    /**
     * Before saving a specific price rule
     */
    public function action_object_specific_price_rule_update_before(array $params): void
    {
        if (empty($params['object']->id)) {
            return;
        }
        /** @var \SpecificPriceRule */
        $specific_price = $params['object'];
        $this->products_before = $specific_price->get_affected_products();
    }
    /**
     * After saving a specific price rule
     */
    public function action_admin_specific_price_rule_controller_save_after(array $params): void
    {
        if (empty($params['return']->id) || empty($this->products_before)) {
            return;
        }
        /** @var \SpecificPriceRule */
        $specific_price = $params['return'];
        $affected_products = array_merge($this->products_before, $specific_price->get_affected_products());
        foreach ($affected_products as $product) {
            $this->module->index_product_prices($product['id_product']);
            $this->module->index_attributes($product['id_product']);
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
}