<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare (strict_types=1);
namespace Presta_Shop\Module\Faceted_Search\Form\Attribute;

use Db;
use Presta_Shop_Database_Exception;
class Form_Data_Provider
{
    /**
     * @var Db
     */
    private $database;
    public function __construct(Db $database)
    {
        $this->database = $database;
    }
    /**
     * Fills form data
     *
     *
     *
     * @throws PrestaShopDatabaseException
     */
    public function get_data(array $params): array
    {
        $default_url = [];
        $default_meta_title = [];
        // if params contains id, gets data for edit form
        if (!empty($params['id'])) {
            $attribute_id = (int) $params['id'];
            $result = $this->database->execute_s('SELECT `url_name`, `meta_title`, `id_lang` ' . 'FROM ' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value ' . 'WHERE `id_attribute` = ' . $attribute_id);
            if (!empty($result) && is_array($result)) {
                foreach ($result as $data) {
                    $default_url[$data['id_lang']] = $data['url_name'];
                    $default_meta_title[$data['id_lang']] = $data['meta_title'];
                }
            }
        }
        return ['url_name' => $default_url, 'meta_title' => $default_meta_title];
    }
}