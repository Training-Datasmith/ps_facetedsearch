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
namespace Presta_Shop\Module\Faceted_Search\Product;

use Configuration;
use Context;
use Db;
use Front_Controller;
use Group;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
use Search;
use Shop;
use Tools;
/**
 * PrestaShop core does not provide a reasonable (fast) way to get product pool to to search
 * without extra performance overhead we don't need. This class contains fast backports of
 * Search::find method of every major for this purpose.
 *
 * This class will be removed when we are able to get product pool from Search class directly.
 */
class Core_Search_Backport
{
    /**
     * Returns a pool of product IDs to use when filtering products on search controller.
     *
     *
     * @return array Pool of product IDs
     */
    public function get_product_pool(Product_Search_Query $query)
    {
        // Get search expression from query
        $expression = Tools::replace_accented_chars(urldecode($query->get_search_string()));
        return $this->get176($expression);
    }
    /**
     * Backported from 1.7.6.9
     *
     * @param string $expr
     *
     * @return array Pool of product IDs
     */
    public function get176($expr): array
    {
        $context = Context::get_context();
        $db = Db::get_instance(_PS_USE_SQL_SLAVE_);
        $intersect_array = [];
        $words = Search::extract_key_words($expr, $context->language->id, false, $context->language->iso_code);
        foreach ($words as $key => $word) {
            if (!empty($word) && strlen($word) >= (int) Configuration::get('PS_SEARCH_MINWORDLEN')) {
                $sql_param_search = Search::get_search_param_from_word($word);
                $intersect_array[] = 'SELECT DISTINCT si.id_product
        FROM ' . _DB_PREFIX_ . 'search_word sw
        LEFT JOIN ' . _DB_PREFIX_ . 'search_index si ON sw.id_word = si.id_word
        WHERE sw.id_lang = ' . (int) $context->language->id . '
          AND sw.id_shop = ' . $context->shop->id . '
          AND sw.word LIKE
        \'' . $sql_param_search . '\'';
            } else {
                unset($words[$key]);
            }
        }
        if (!count($words)) {
            return [];
        }
        $sql_groups = '';
        if (Group::is_feature_active()) {
            $groups = Front_Controller::get_current_customer_groups();
            $sql_groups = 'AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '=' . (int) Configuration::get('PS_UNIDENTIFIED_GROUP'));
        }
        $results = $db->execute_s('
      SELECT DISTINCT cp.`id_product`
      FROM `' . _DB_PREFIX_ . 'category_product` cp
      ' . (Group::is_feature_active() ? 'INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cp.`id_category` = cg.`id_category`' : '') . '
      INNER JOIN `' . _DB_PREFIX_ . 'category` c ON cp.`id_category` = c.`id_category`
      INNER JOIN `' . _DB_PREFIX_ . 'product` p ON cp.`id_product` = p.`id_product`
      ' . Shop::add_sql_association('product', 'p', false) . '
      WHERE c.`active` = 1
      AND product_shop.`active` = 1
      AND product_shop.`visibility` IN ("both", "search")
      AND product_shop.indexed = 1
      ' . $sql_groups, true, false);
        $eligible_products = [];
        foreach ($results as $row) {
            $eligible_products[] = $row['id_product'];
        }
        $eligible_products2 = [];
        foreach ($intersect_array as $query) {
            foreach ($db->execute_s($query, true, false) as $row) {
                $eligible_products2[] = $row['id_product'];
            }
        }
        return array_unique(array_intersect($eligible_products, array_unique($eligible_products2)));
    }
    /**
     * Backported from 1.7.7.8
     *
     * @param string $expr
     *
     * @return array Pool of product IDs
     */
    public function get177($expr): array
    {
        $context = Context::get_context();
        $db = Db::get_instance(_PS_USE_SQL_SLAVE_);
        $fuzzy_loop = 0;
        $eligible_products2 = null;
        $words = Search::extract_key_words($expr, $context->language->id, false, $context->language->iso_code);
        $fuzzy_max_loop = (int) Configuration::get('PS_SEARCH_FUZZY_MAX_LOOP');
        $ps_fuzzy_search = (int) Configuration::get('PS_SEARCH_FUZZY');
        $ps_search_min_word_length = (int) Configuration::get('PS_SEARCH_MINWORDLEN');
        foreach ($words as $key => $word) {
            if (empty($word) || strlen($word) < $ps_search_min_word_length) {
                unset($words[$key]);
                continue;
            }
            $sql_param_search = Search::get_search_param_from_word($word);
            $sql = 'SELECT DISTINCT si.id_product ' . 'FROM ' . _DB_PREFIX_ . 'search_word sw ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'search_index si ON sw.id_word = si.id_word ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'product_shop product_shop ON (product_shop.`id_product` = si.`id_product`) ' . 'WHERE sw.id_lang = ' . (int) $context->language->id . ' ' . 'AND sw.id_shop = ' . $context->shop->id . ' ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . 'AND sw.word LIKE ';
            while (!$result = $db->execute_s($sql . "'" . $sql_param_search . "';", true, false)) {
                if (!$ps_fuzzy_search || $fuzzy_loop++ > $fuzzy_max_loop || !$sql_param_search = Search::find_closest_weightest_word($context, $word)) {
                    break;
                }
            }
            if (!$result) {
                unset($words[$key]);
                continue;
            }
            $product_ids = array_column($result, 'id_product');
            if ($eligible_products2 === null) {
                $eligible_products2 = $product_ids;
            } else {
                $eligible_products2 = array_intersect($eligible_products2, $product_ids);
            }
        }
        if (!count($words)) {
            return [];
        }
        $sql_groups = '';
        if (Group::is_feature_active()) {
            $groups = Front_Controller::get_current_customer_groups();
            $sql_groups = 'AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '=' . (int) Group::get_current()->id);
        }
        $results = $db->execute_s('SELECT DISTINCT cp.`id_product` ' . 'FROM `' . _DB_PREFIX_ . 'category_product` cp ' . (Group::is_feature_active() ? 'INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cp.`id_category` = cg.`id_category`' : '') . ' ' . 'INNER JOIN `' . _DB_PREFIX_ . 'category` c ON cp.`id_category` = c.`id_category` ' . 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON cp.`id_product` = p.`id_product` ' . Shop::add_sql_association('product', 'p', false) . ' ' . 'WHERE c.`active` = 1 ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . $sql_groups, true, false);
        $eligible_products = array_column($results, 'id_product');
        return array_unique(array_intersect($eligible_products, array_unique($eligible_products2)));
    }
    /**
     * Backported from 1.7.8.8
     *
     * @param string $expr
     *
     * @return array Pool of product IDs
     */
    public function get178($expr): array
    {
        $context = Context::get_context();
        $db = Db::get_instance(_PS_USE_SQL_SLAVE_);
        $fuzzy_loop = 0;
        $eligible_products2 = null;
        $words = Search::extract_key_words($expr, $context->language->id, false, $context->language->iso_code);
        $fuzzy_max_loop = (int) Configuration::get('PS_SEARCH_FUZZY_MAX_LOOP');
        $ps_fuzzy_search = (int) Configuration::get('PS_SEARCH_FUZZY');
        $ps_search_min_word_length = (int) Configuration::get('PS_SEARCH_MINWORDLEN');
        foreach ($words as $key => $word) {
            if (empty($word) || strlen($word) < $ps_search_min_word_length) {
                unset($words[$key]);
                continue;
            }
            $sql_param_search = Search::get_search_param_from_word($word);
            $sql = 'SELECT DISTINCT si.id_product ' . 'FROM ' . _DB_PREFIX_ . 'search_word sw ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'search_index si ON sw.id_word = si.id_word ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'product_shop product_shop ON (product_shop.`id_product` = si.`id_product`) ' . 'WHERE sw.id_lang = ' . (int) $context->language->id . ' ' . 'AND sw.id_shop = ' . $context->shop->id . ' ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . 'AND sw.word LIKE ';
            while (!$result = $db->execute_s($sql . "'" . $sql_param_search . "';", true, false)) {
                if (!$ps_fuzzy_search || $fuzzy_loop++ > $fuzzy_max_loop || !$sql_param_search = Search::find_closest_weightest_word($context, $word)) {
                    break;
                }
            }
            if (!$result) {
                unset($words[$key]);
                continue;
            }
            $product_ids = array_column($result, 'id_product');
            if ($eligible_products2 === null) {
                $eligible_products2 = $product_ids;
            } else {
                $eligible_products2 = array_intersect($eligible_products2, $product_ids);
            }
        }
        if (!count($words) || !count($eligible_products2)) {
            return [];
        }
        $sql_groups = '';
        if (Group::is_feature_active()) {
            $groups = Front_Controller::get_current_customer_groups();
            $sql_groups = 'AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '=' . (int) Group::get_current()->id);
        }
        $results = $db->execute_s('SELECT DISTINCT cp.`id_product` ' . 'FROM `' . _DB_PREFIX_ . 'category_product` cp ' . (Group::is_feature_active() ? 'INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cp.`id_category` = cg.`id_category`' : '') . ' ' . 'INNER JOIN `' . _DB_PREFIX_ . 'category` c ON cp.`id_category` = c.`id_category` ' . 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON cp.`id_product` = p.`id_product` ' . Shop::add_sql_association('product', 'p', false) . ' ' . 'WHERE c.`active` = 1 ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . 'AND cp.id_product IN (' . implode(',', $eligible_products2) . ')' . $sql_groups, true, false);
        return array_column($results, 'id_product');
    }
    /**
     * Backported 8.0.1
     *
     * @param string $expr
     *
     * @return array Pool of product IDs
     */
    public function get80($expr): array
    {
        $context = Context::get_context();
        $db = Db::get_instance(_PS_USE_SQL_SLAVE_);
        $score_array = [];
        $fuzzy_loop = 0;
        $word_cnt = 0;
        $eligible_products2full = [];
        $expressions = explode(';', $expr);
        $fuzzy_max_loop = (int) Configuration::get('PS_SEARCH_FUZZY_MAX_LOOP');
        $ps_fuzzy_search = (int) Configuration::get('PS_SEARCH_FUZZY');
        $ps_search_min_word_length = (int) Configuration::get('PS_SEARCH_MINWORDLEN');
        foreach ($expressions as $expression) {
            $eligible_products2 = null;
            $words = Search::extract_key_words($expression, $context->language->id, false, $context->language->iso_code);
            foreach ($words as $key => $word) {
                if (empty($word) || strlen($word) < $ps_search_min_word_length) {
                    unset($words[$key]);
                    continue;
                }
                $sql_param_search = Search::get_search_param_from_word($word);
                $sql = 'SELECT DISTINCT si.id_product ' . 'FROM ' . _DB_PREFIX_ . 'search_word sw ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'search_index si ON sw.id_word = si.id_word ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'product_shop product_shop ON (product_shop.`id_product` = si.`id_product`) ' . 'WHERE sw.id_lang = ' . (int) $context->language->id . ' ' . 'AND sw.id_shop = ' . $context->shop->id . ' ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . 'AND sw.word LIKE ';
                while (!$result = $db->execute_s($sql . "'" . $sql_param_search . "';", true, false)) {
                    if (!$ps_fuzzy_search || $fuzzy_loop++ > $fuzzy_max_loop || !$sql_param_search = Search::find_closest_weightest_word($context, $word)) {
                        break;
                    }
                }
                if (!$result) {
                    unset($words[$key]);
                    continue;
                }
                $product_ids = array_column($result, 'id_product');
                if ($eligible_products2 === null) {
                    $eligible_products2 = $product_ids;
                } else {
                    $eligible_products2 = array_intersect($eligible_products2, $product_ids);
                }
                $score_array[] = 'sw.word LIKE \'' . $sql_param_search . '\'';
            }
            $word_cnt += count($words);
            if ($eligible_products2) {
                $eligible_products2full = array_merge($eligible_products2full, $eligible_products2);
            }
        }
        $eligible_products2full = array_unique($eligible_products2full);
        if (!$word_cnt || !count($eligible_products2full)) {
            return [];
        }
        $sql_score = '';
        if (!empty($score_array) && is_array($score_array)) {
            $sql_score = ',( ' . 'SELECT SUM(weight) ' . 'FROM ' . _DB_PREFIX_ . 'search_word sw ' . 'LEFT JOIN ' . _DB_PREFIX_ . 'search_index si ON sw.id_word = si.id_word ' . 'WHERE sw.id_lang = ' . (int) $context->language->id . ' ' . 'AND sw.id_shop = ' . $context->shop->id . ' ' . 'AND si.id_product = p.id_product ' . 'AND (' . implode(' OR ', $score_array) . ') ' . ') position';
        }
        $sql_groups = '';
        if (Group::is_feature_active()) {
            $groups = Front_Controller::get_current_customer_groups();
            $sql_groups = 'AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '=' . (int) Group::get_current()->id);
        }
        $results = $db->execute_s('SELECT DISTINCT cp.`id_product` ' . $sql_score . ' ' . 'FROM `' . _DB_PREFIX_ . 'category_product` cp ' . (Group::is_feature_active() ? 'INNER JOIN `' . _DB_PREFIX_ . 'category_group` cg ON cp.`id_category` = cg.`id_category`' : '') . ' ' . 'INNER JOIN `' . _DB_PREFIX_ . 'category` c ON cp.`id_category` = c.`id_category` ' . 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON cp.`id_product` = p.`id_product` ' . Shop::add_sql_association('product', 'p', false) . ' ' . 'WHERE c.`active` = 1 ' . 'AND product_shop.`active` = 1 ' . 'AND product_shop.`visibility` IN ("both", "search") ' . 'AND product_shop.indexed = 1 ' . 'AND cp.id_product IN (' . implode(',', $eligible_products2full) . ')' . $sql_groups . '
            ORDER BY position DESC, p.id_product ASC', true, false);
        return array_column($results, 'id_product');
    }
}