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
use Language;
use Presta_Shop\Module\Faceted_Search\Form\Feature\Form_Data_Provider;
use Presta_Shop\Module\Faceted_Search\Form\Feature\Form_Modifier;
use Presta_Shop_Database_Exception;
use Ps_Facetedsearch;
use Tools;
class Feature extends Abstract_Hook
{
    /**
     * @var FormModifier
     */
    private $form_modifier;
    /**
     * @var FormDataProvider
     */
    private $data_provider;
    /**
     * @var bool
     */
    private $is_migrated_page = false;
    public function __construct(Ps_Facetedsearch $module)
    {
        parent::__construct($module);
        $this->form_modifier = new Form_Modifier($module->get_context());
        $this->data_provider = new Form_Data_Provider($module->get_database());
    }
    public const AVAILABLE_HOOKS = ['actionFeatureSave', 'actionFeatureDelete', 'displayFeatureForm', 'displayFeaturePostProcess', 'actionFeatureFormBuilderModifier', 'actionAfterCreateFeatureFormHandler', 'actionAfterUpdateFeatureFormHandler'];
    /**
     * Hook for modifying feature form formBuilder
     *
     *
     * @throws PrestaShopDatabaseException
     */
    public function action_feature_form_builder_modifier(array $params): void
    {
        $this->is_migrated_page = true;
        $this->form_modifier->modify($params['form_builder'], $this->data_provider->get_data($params));
    }
    /**
     * Hook after create feature.
     *
     * @since PrestaShop 1.7.8.0
     */
    public function action_after_create_feature_form_handler(array $params): void
    {
        $this->save($params['id'], $params['form_data']);
    }
    /**
     * Hook after update feature.
     *
     * @since PrestaShop 1.7.8.0
     */
    public function action_after_update_feature_form_handler(array $params): void
    {
        $this->save($params['id'], $params['form_data']);
    }
    /**
     * Hook after delete a feature
     */
    public function action_feature_delete(array $params): void
    {
        if (empty($params['id_feature'])) {
            return;
        }
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature
            WHERE `id_feature` = ' . (int) $params['id_feature']);
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Hook post process feature
     */
    public function display_feature_post_process(array $params): void
    {
        $this->module->check_links_rewrite($params);
    }
    /**
     * Hook feature form
     */
    public function display_feature_form(array $params)
    {
        if ($this->is_migrated_page === true) {
            return;
        }
        $values = [];
        $is_indexable = $this->database->get_value('SELECT `indexable` ' . 'FROM ' . _DB_PREFIX_ . 'layered_indexable_feature ' . 'WHERE `id_feature` = ' . (int) $params['id_feature']);
        $result = $this->database->execute_s('SELECT `url_name`, `meta_title`, `id_lang` ' . 'FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value ' . 'WHERE `id_feature` = ' . (int) $params['id_feature']);
        if ($result) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }
        $this->context->smarty->assign(['languages' => Language::get_languages(false), 'default_form_language' => (int) $this->context->controller->default_form_language, 'values' => $values, 'is_indexable' => (bool) $is_indexable]);
        return $this->module->render('feature_form.tpl');
    }
    /**
     * After save feature
     */
    public function action_feature_save(array $params): void
    {
        if (empty($params['id_feature']) || Tools::get_value('layered_indexable') === false) {
            return;
        }
        $feature_id = (int) $params['id_feature'];
        $form_data = ['layered_indexable' => Tools::get_value('layered_indexable')];
        foreach (Language::get_languages(false) as $language) {
            $lang_id = (int) $language['id_lang'];
            $seo_url = Tools::get_value('url_name_' . $lang_id);
            $meta_title = Tools::get_value('meta_title_' . $lang_id);
            if (empty($seo_url) && empty($meta_title)) {
                continue;
            }
            $form_data['meta_title'][$lang_id] = $meta_title;
            $form_data['url_name'][$lang_id] = $seo_url;
        }
        $this->save($feature_id, $form_data);
    }
    /**
     * Saves feature form.
     *
     * @param int $featureId
     *
     * @since PrestaShop 1.7.8.0
     */
    private function save($feature_id, array $form_data): void
    {
        $this->clean_layered_indexable_tables($feature_id);
        $this->database->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature
            (`id_feature`, `indexable`)
            VALUES (' . (int) $feature_id . ', ' . (int) $form_data['layered_indexable'] . ')');
        $default_lang_id = (int) Configuration::get('PS_LANG_DEFAULT');
        $query = 'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value ' . '(`id_feature`, `id_lang`, `url_name`, `meta_title`) ' . 'VALUES (%d, %d, \'%s\', \'%s\')';
        foreach (Language::get_languages(false) as $language) {
            $lang_id = (int) $language['id_lang'];
            $meta_title = p_sql($form_data['meta_title'][$lang_id]);
            $seo_url = $form_data['url_name'][$lang_id];
            $name = $form_data['name'][$lang_id] ?: $form_data['name'][$default_lang_id];
            if (!empty($seo_url)) {
                $seo_url = p_sql(Tools::str2url($seo_url));
            }
            $this->database->execute(sprintf($query, $feature_id, $lang_id, $seo_url, $meta_title));
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Deletes from layered_indexable_feature and layered_indexable_feature_lang_value by feature id
     *
     * @param int $featureId
     */
    private function clean_layered_indexable_tables($feature_id): void
    {
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature
            WHERE `id_feature` = ' . $feature_id);
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value
            WHERE `id_feature` = ' . $feature_id);
    }
}