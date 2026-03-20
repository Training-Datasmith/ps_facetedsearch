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
use Tools;
class Category extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = ['actionCategoryAdd', 'actionCategoryDelete'];
    /**
     * Category addition
     */
    public function action_category_add(array $params): void
    {
        $this->add_category_to_default_filter((int) $params['category']->id);
        // Flush filter block cache in all cases, so a new category shows up
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Category deletion
     */
    public function action_category_delete(array $params): void
    {
        $this->remove_category_from_filter_templates((int) $params['category']->id);
    }
    /**
     * Clean and rebuild category filters
     */
    private function remove_category_from_filter_templates(int $id_category): void
    {
        // Get all filter templates
        $filter_templates = $this->database->execute_s('SELECT * FROM ' . _DB_PREFIX_ . 'layered_filter');
        $rebuild_needed = false;
        // Go through each template, check if our category is set for this template.
        // If yes, remove it and update the template.
        foreach ($filter_templates as $template) {
            $filters = Tools::un_serialize($template['filters']);
            if (!in_array($id_category, $filters['categories'])) {
                continue;
            }
            unset($filters['categories'][array_search($id_category, $filters['categories'])]);
            $rebuild_needed = true;
            $this->database->execute('UPDATE `' . _DB_PREFIX_ . 'layered_filter` 
                SET `filters` = "' . p_sql(serialize($filters)) . '", 
                n_categories = ' . count($filters['categories']) . ' 
                WHERE `id_layered_filter` = ' . (int) $template['id_layered_filter']);
        }
        // Rebuild filter table only if a category was removed from a filter
        if ($rebuild_needed) {
            $this->module->build_layered_categories();
        }
        // Flush cache all the time, because the category could be cached in a category filter block
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Checks if module is configured to automatically add some filter to new categories.
     * If so, it adds the new category.
     *
     * @param int $idCategory ID of category being created
     */
    public function add_category_to_default_filter(int $id_category): void
    {
        // Get default template
        $default_filter_template_id = (int) Configuration::get('PS_LAYERED_DEFAULT_CATEGORY_TEMPLATE');
        if (empty($default_filter_template_id)) {
            return;
        }
        // Try to get it's data
        $template = $this->module->get_filter_template($default_filter_template_id);
        if (empty($template)) {
            return;
        }
        // Unserialize filters, add our category
        $filters = Tools::un_serialize($template['filters']);
        $filters['categories'][] = $id_category;
        // Update it in database
        $this->database->execute('UPDATE `' . _DB_PREFIX_ . 'layered_filter` 
            SET `filters` = "' . p_sql(serialize($filters)) . '", 
            n_categories = ' . count($filters['categories']) . ' 
            WHERE `id_layered_filter` = ' . $default_filter_template_id);
        $this->module->build_layered_categories();
    }
}