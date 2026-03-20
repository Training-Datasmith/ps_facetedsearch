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
use Presta_Shop\Module\Faceted_Search\Form\Attribute\Form_Data_Provider;
use Presta_Shop\Module\Faceted_Search\Form\Attribute\Form_Modifier;
use Tools;
class Attribute extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = [
        'actionAttributeGroupDelete',
        'actionAttributeSave',
        'displayAttributeForm',
        'actionAttributePostProcess',
        // Hooks for migrated page
        'actionAttributeFormBuilderModifier',
        'actionAttributeFormDataProviderData',
        'actionAfterCreateAttributeFormHandler',
        'actionAfterUpdateAttributeFormHandler',
    ];
    /**
     * Hook for modifying attribute form formBuilder
     *
     * @since PrestaShop 9.0.0
     */
    public function action_attribute_form_builder_modifier(array $params): void
    {
        $form_modifier = new Form_Modifier($this->context->get_translator());
        $form_modifier->modify($params['form_builder']);
    }
    /**
     * Hook that provides extra data in the form.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_attribute_form_data_provider_data(array $params): void
    {
        $form_data_provider = new Form_Data_Provider($this->database);
        $attribute_data = $form_data_provider->get_data($params);
        // Update data field in params which is passed by reference
        $params['data'] = array_merge($params['data'], $attribute_data);
    }
    /**
     * Hook after creation form is handled in migrated page.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_after_create_attribute_form_handler(array $params): void
    {
        $this->save(array_merge(['id_attribute' => $params['id']], $params['form_data']));
    }
    /**
     * Hook after edition form is handled in migrated page.
     *
     * @since PrestaShop 9.0.0
     */
    public function action_after_update_attribute_form_handler(array $params): void
    {
        $this->save(array_merge(['id_attribute' => $params['id']], $params['form_data']));
    }
    /**
     * After save attribute
     */
    public function action_attribute_save(array $params): void
    {
        if (empty($params['id_attribute'])) {
            return;
        }
        $form_data = ['id_attribute' => (int) $params['id_attribute']];
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
     * After delete attribute
     */
    public function action_attribute_group_delete(array $params): void
    {
        if (empty($params['id_attribute'])) {
            return;
        }
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . (int) $params['id_attribute']);
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Post process attribute
     */
    public function action_attribute_post_process(array $params): void
    {
        $this->module->check_links_rewrite($params);
    }
    /**
     * Attribute form
     */
    public function display_attribute_form(array $params)
    {
        $values = [];
        if ($result = $this->database->execute_s('SELECT `url_name`, `meta_title`, `id_lang`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . (int) $params['id_attribute'])) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }
        $this->context->smarty->assign(['languages' => Language::get_languages(false), 'default_form_language' => (int) $this->context->controller->default_form_language, 'values' => $values]);
        return $this->module->render('attribute_form.tpl');
    }
    /**
     * This is the common save method, the calling methods just need to format the form data appropriately
     * depending on the page being migrated or not.
     */
    private function save(array $form_data): void
    {
        if (empty($form_data['id_attribute'])) {
            return;
        }
        $attribute_id = (int) $form_data['id_attribute'];
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
            WHERE `id_attribute` = ' . $attribute_id);
        $land_ids = array_unique(array_merge(array_keys($form_data['meta_title'] ?? []), array_keys($form_data['url_name'] ?? [])));
        foreach ($land_ids as $lang_id) {
            $seo_url = $form_data['url_name'][$lang_id] ?? null;
            $meta_title = $form_data['meta_title'][$lang_id] ?? null;
            if (empty($seo_url) && empty($meta_title)) {
                continue;
            }
            $this->database->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value
                (`id_attribute`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . $attribute_id . ', ' . $lang_id . ',
                \'' . p_sql(Tools::str2url($seo_url)) . '\',
                \'' . p_sql($meta_title, true) . '\')');
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
}