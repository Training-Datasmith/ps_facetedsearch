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
use Presta_Shop\Module\Faceted_Search\Form\Feature_Value\Form_Data_Provider;
use Presta_Shop\Module\Faceted_Search\Form\Feature_Value\Form_Modifier;
use Ps_Facetedsearch;
use Tools;
class Feature_Value extends Abstract_Hook
{
    public const AVAILABLE_HOOKS = ['actionFeatureValueSave', 'actionFeatureValueDelete', 'displayFeatureValueForm', 'displayFeatureValuePostProcess', 'actionFeatureValueFormBuilderModifier', 'actionAfterCreateFeatureValueFormHandler', 'actionAfterUpdateFeatureValueFormHandler'];
    /**
     * @var FormModifier
     */
    private $form_modifier;
    /**
     * @var FormDataProvider
     */
    private $data_provider;
    public function __construct(Ps_Facetedsearch $module)
    {
        parent::__construct($module);
        $this->form_modifier = new Form_Modifier($module->get_context());
        $this->data_provider = new Form_Data_Provider($module->get_database());
    }
    /**
     * Hook for modifying feature form formBuilder
     *
     * @since PrestaShop 9.0
     */
    public function action_feature_value_form_builder_modifier(array $params): void
    {
        $this->form_modifier->modify($params['form_builder'], $this->data_provider->get_data($params));
    }
    /**
     * Hook after create feature.
     *
     * @since PrestaShop 9.0
     */
    public function action_after_create_feature_value_form_handler(array $params): void
    {
        $this->save($params['id'], $params['form_data']);
    }
    /**
     * Hook after update feature.
     *
     * @since PrestaShop 9.0
     */
    public function action_after_update_feature_value_form_handler(array $params): void
    {
        $this->save($params['id'], $params['form_data']);
    }
    /**
     * After save feature value
     */
    public function action_feature_value_save(array $params): void
    {
        if (empty($params['id_feature_value'])) {
            return;
        }
        //Removing all indexed language data for this attribute value id
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value']);
        foreach (Language::get_languages(false) as $language) {
            $seo_url = Tools::get_value('url_name_' . (int) $language['id_lang']);
            $meta_title = Tools::get_value('meta_title_' . (int) $language['id_lang']);
            if (empty($seo_url) && empty($meta_title)) {
                continue;
            }
            $this->database->execute('INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
                (`id_feature_value`, `id_lang`, `url_name`, `meta_title`)
                VALUES (
                ' . (int) $params['id_feature_value'] . ', ' . (int) $language['id_lang'] . ',
                \'' . p_sql(Tools::str2url($seo_url)) . '\',
                \'' . p_sql($meta_title, true) . '\')');
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * After delete Feature value
     */
    public function action_feature_value_delete(array $params): void
    {
        if (empty($params['id_feature_value'])) {
            return;
        }
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value']);
        $this->module->invalidate_layered_filter_block_cache();
    }
    /**
     * Post process feature value
     */
    public function display_feature_value_post_process(array $params): void
    {
        $this->module->check_links_rewrite($params);
    }
    /**
     * Display feature value form
     *
     *
     * @return string
     */
    public function display_feature_value_form(array $params)
    {
        $values = [];
        if ($result = $this->database->execute_s('SELECT `url_name`, `meta_title`, `id_lang`
            FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . (int) $params['id_feature_value'])) {
            foreach ($result as $data) {
                $values[$data['id_lang']] = ['url_name' => $data['url_name'], 'meta_title' => $data['meta_title']];
            }
        }
        $this->context->smarty->assign(['languages' => Language::get_languages(false), 'default_form_language' => (int) $this->context->controller->default_form_language, 'values' => $values]);
        return $this->module->render('feature_value_form.tpl');
    }
    private function save($feature_value_id, array $form_data): void
    {
        $feature_value_id = (int) $feature_value_id;
        $this->database->execute('DELETE FROM ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value
            WHERE `id_feature_value` = ' . $feature_value_id);
        $query = 'INSERT INTO ' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value ' . '(`id_feature_value`, `id_lang`, `url_name`, `meta_title`) ' . 'VALUES (%d, %d, \'%s\', \'%s\')';
        foreach (Language::get_languages(false) as $language) {
            $lang_id = (int) $language['id_lang'];
            $meta_title = p_sql($form_data['meta_title'][$lang_id]);
            $seo_url = $form_data['url_name'][$lang_id];
            if (!empty($seo_url)) {
                $seo_url = p_sql(Tools::str2url($seo_url));
            }
            $this->database->execute(sprintf($query, $feature_value_id, $lang_id, $seo_url, $meta_title));
        }
        $this->module->invalidate_layered_filter_block_cache();
    }
}