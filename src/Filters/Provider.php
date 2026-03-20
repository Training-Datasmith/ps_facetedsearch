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

use Db;
use Presta_Shop\Presta_Shop\Core\Product\Search\Product_Search_Query;
/**
 * Class responsible for providing filters configured for current search query
 */
class Provider
{
    /**
     * @var array
     */
    private $filters = [];
    /**
     * @var Db
     */
    private $database;
    public function __construct(Db $database)
    {
        $this->database = $database;
    }
    /**
     * Get filters for current search query
     *
     *
     * @return array Filters
     */
    public function get_filters_for_query(Product_Search_Query $query, int $id_shop)
    {
        if (empty($this->filters)) {
            $this->filters = $this->database->execute_s('SELECT type, id_value, filter_show_limit, filter_type FROM ' . _DB_PREFIX_ . 'layered_category
            WHERE controller = \'' . $query->get_query_type() . '\'
            AND id_category = ' . ($query->get_query_type() == 'category' ? (int) $query->get_id_category() : 0) . '
            AND id_shop = ' . $id_shop . '
            GROUP BY `type`, id_value ORDER BY position ASC');
        }
        return $this->filters;
    }
}