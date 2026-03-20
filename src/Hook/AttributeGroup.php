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

use Language;
use Presta_Shop\Module\Faceted_Search\Form\Attribute_Group\Form_Data_Provider;
use Presta_Shop\Module\Faceted_Search\Form\Attribute_Group\Form_Modifier;
use Tools;
class Attribute_Group extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = [
        'actionAttributeGroupDelete',
        'actionAttributeGroupSave',
        'displayAttributeGroupForm',
        'displayAttributeGroupPostProcess',
        // Hooks for migrated page
        'actionAttributeGroupFormBuilderModifier',
        'actionAttributeGroupFormDataProviderData',
        'actionAfterCreateAttributeGroupFormHandler',
        'actionAfterUpdateAttributeGroupFormHandler',
    ];
    /**
     * Hook for modifying attribute group form formBuilder
     *
     * @since PrestaShop 9.0.0
     */
    public function action_attribute_group_form_builder_modifier(array $params): void
    {
        $form_modifier = new Form_Modifier($this->context->get_translator());
        $form_modifier->modify($params['form_builder']);
    }
    /**
     * Hook that provides extra data in the form.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_attribute_group_form_data_provider_data(array $params): void
    {
        $form_data_provider = new Form_Data_Provider($this->database);
        $attribute_group_data = $form_data_provider->get_data($params);
        // Update data field in params which is passed by reference
        $params['data'] = array_merge($params['data'], $attribute_group_data);
    }
    /**
     * Hook after creation form is handled in migrated page.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_after_create_attribute_group_form_handler(array $params): void
    {
        $this->save(array_merge(['id_attribute_group' => $params['id']], $params['form_data']));
    }
    /**
     * Hook after edition form is handled in migrated page.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_after_update_attribute_group_form_handler(array $params): void
    {
        $this->save(array_merge(['id_attribute_group' => $params['id']], $params['form_data']));
    }
    /**
     * After save Attributes group
     */
    public function action_attribute_group_save(array $params): void
    {
        if (empty($params['id_attribute_group']) || Tools::get_value('layered_indexable') === false) {
            return;
        }
        $form_data = ['id_attribute_group' => (int) $params['id_attribute_group'], 'is_indexable' => (int) Tools::get_value('layered_indexable')];
        foreach (Language::get_languages(false) as $language) {
            $lang_id = (int) $language['id_lang'];
            $seo_url = Tools::get_value('url_name_' . $lang_id);
            if (!empty($seo_url)) {
                $form_data['url_name'][$lang_id] = $seo_url;
            }
            $meta_title = Tools::get_value('meta_title_' . $lang_id);
            if (!empty($meta_title)) {
                $form_data['meta_title'][$lang_id] = $meta_title;
            }
        }
        $this->save($form_data);
    }
    /**
     * After delete attribute group
     */
    public function action_attribute_group_delete(array $params): void
    {
        if (empty($params['id_attribute_group'])) {
            return;
        }
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']);
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']);
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Post process attribute group
     */
    public function display_attribute_group_post_process(array $params): void
    {
        $this->module->check_links_rewrite($params);
    }
    /**
     * Attribute group form
     *
     *
     * @return string
     */
    public function display_attribute_group_form(array $params)
    {
        $values = [];
        $is_indexable = $this->database->get_value('SELECT `indexable`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group']);
        if ($result = $this->database->execute_s('SELECT `url_name`, `meta_title`, `id_lang` FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . (int) $params['id_attribute_group'])) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }
        $this->context->smarty->assign(['languages' => Language::get_languages(false), 'default_form_language' => (int) $this->context->controller->default_form_language, 'values' => $values, 'is_indexable' => (bool) $is_indexable]);
        return $this->module->render('attribute_group_form.tpl');
    }
    /**
     * This is the common save method, the calling methods just need to format the form data appropriately
     * depending on the page being migrated or not.
     */
    private function save(array $form_data): void
    {
        if (empty($form_data['id_attribute_group'])) {
            return;
        }
        $attribute_group_id = $form_data['id_attribute_group'];
        // First clean all existing data
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group
            WHERE `id_attribute_group` = ' . $attribute_group_id);
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
            WHERE `id_attribute_group` = ' . $attribute_group_id);
        $this->database->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_group (`id_attribute_group`, `indexable`)
VALUES (' . $attribute_group_id . ', ' . (int) $form_data['is_indexable'] . ')');
        $land_ids = array_unique(array_merge(array_keys($form_data['meta_title'] ?? []), array_keys($form_data['url_name'] ?? [])));
        foreach ($land_ids as $lang_id) {
            $seo_url = $form_data['url_name'][$lang_id] ?? null;
            $meta_title = $form_data['meta_title'][$lang_id] ?? null;
            if (empty($seo_url) && empty($meta_title)) {
                continue;
            }
            $this->database->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value
                (`id_attribute_group`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . $attribute_group_id . ', ' . $lang_id . ',
                \'' . p_sql(Tools::str2url($seo_url)) . '\',
                \'' . p_sql($meta_title, true) . '\')');
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
}