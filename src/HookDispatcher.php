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
namespace Presta_Shop\Module\Faceted_Search;

use Ps_Facetedsearch;
/**
 * Class works with Hook\AbstractHook instances in order to reduce ps_facetedsearch.php size.
 *
 * The dispatch method is called from the __call method in the module class.
 */
class Hook_Dispatcher
{
    public const CLASSES = [Hook\Attribute::class, Hook\Attribute_Group::class, Hook\Category::class, Hook\Configuration::class, Hook\Design::class, Hook\Feature::class, Hook\Feature_Value::class, Hook\Product::class, Hook\Product_Search::class, Hook\Specific_Price::class];
    /**
     * List of available hooks
     *
     * @var string[]
     */
    private $available_hooks = [];
    /**
     * Hook classes
     *
     * @var Hook\AbstractHook[]
     */
    private $hooks = [];
    /**
     * Module
     *
     * @var Ps_Facetedsearch
     */
    private $module;
    /**
     * Init hooks
     */
    public function __construct(Ps_Facetedsearch $module)
    {
        $this->module = $module;
        foreach (self::CLASSES as $hook_class) {
            $hook = new $hook_class($this->module);
            $this->available_hooks = array_merge($this->available_hooks, $hook->get_available_hooks());
            $this->hooks[] = $hook;
        }
    }
    /**
     * Get available hooks
     *
     * @return string[]
     */
    public function get_available_hooks()
    {
        return $this->available_hooks;
    }
    /**
     * Find hook and dispatch it
     *
     * @param string $hookName
     *
     * @return mixed
     */
    public function dispatch($hook_name, array $params = [])
    {
        $hook_name = preg_replace('~^hook~', '', $hook_name);
        foreach ($this->hooks as $hook) {
            if (method_exists($hook, $hook_name)) {
                return call_user_func([$hook, $hook_name], $params);
            }
        }
        // No hook found, render it as a widget
        return $this->module->render_widget($hook_name, $params);
    }
}