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
namespace Presta_Shop\Module\Faceted_Search\Constraint;

use Presta_Shop\Presta_Shop\Adapter\Tools;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraint_Validator;
use Symfony\Component\Validator\Exception\Unexpected_Type_Exception;
/**
 * Class UrlSegmentValidator responsible for validating an URL segment.
 */
class Url_Segment_Validator extends Constraint_Validator
{
    /**
     * @var Tools
     */
    private $tools;
    public function __construct(Tools $tools)
    {
        $this->tools = $tools;
    }
    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof Url_Segment) {
            throw new Unexpected_Type_Exception($constraint, Url_Segment::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (strtolower($value) !== $this->tools->link_rewrite($value)) {
            $this->context->build_violation($constraint->message)->set_translation_domain('Admin.Notifications.Error')->set_parameter('%s', $this->format_value($value))->add_violation();
        }
    }
}