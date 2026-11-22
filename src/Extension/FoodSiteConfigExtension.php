<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Extension;

use SilverShop\FoodInformation\Service\FoodRules;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Settings > Shop > Food information: which country's rules apply, and the food business shown on product pages.
 *
 * @extends Extension<SiteConfig>
 */
class FoodSiteConfigExtension extends Extension
{
    private static array $db = [
        'FoodRulesProfile' => 'Varchar(32)',
        'FoodBusinessOperator' => 'Text',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['FoodRulesProfile', 'FoodBusinessOperator']);
        $tab = 'Root.Shop';
        $fields->addFieldsToTab($tab, [
            HeaderField::create('FoodInformationHeader', _t(self::class . '.Header', 'Food information'), 3),
            DropdownField::create('FoodRulesProfile', _t(self::class . '.Profile', 'Allergen and labelling rules'), FoodRules::profileOptions())
                ->setEmptyString(_t(self::class . '.ProfileDefault', 'Default ({profile})', ['profile' => FoodRules::forProfile('')->getTitle()]))
                ->setDescription(_t(
                    self::class . '.ProfileHelp',
                    'Which allergens must be declared and how they are shown. Choose the rules of the country you sell in.'
                )),
            TextareaField::create('FoodBusinessOperator', _t(self::class . '.Operator', 'Food business (name and address)'))
                ->setRows(3)
                ->setDescription(_t(
                    self::class . '.OperatorHelp',
                    'Shown with the food information. Empty = the legal name and address from the store profile.'
                )),
        ]);
    }
}
