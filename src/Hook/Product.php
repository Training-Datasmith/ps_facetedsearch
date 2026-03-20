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

class Product extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = ['actionProductSave'];
    /**
     * After save product
     */
    public function action_product_save(array $params): void
    {
        if (empty($params['id_product'])) {
            return;
        }
        $this->module->index_product_prices((int) $params['id_product']);
        $this->module->index_attributes((int) $params['id_product']);
        $this->module->invalidate_layered_filter_block_cache();
    }
}