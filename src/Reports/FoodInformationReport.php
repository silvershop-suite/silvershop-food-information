<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Reports;

use SilverShop\FoodInformation\Extension\FoodInformationExtension;
use SilverShop\FoodInformation\Service\FoodInformationResolver;
use SilverShop\Page\Product;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Reports\Report;

/**
 * Food products whose information is not checked yet or needs attention (missing allergen names, claim conflicts,
 * allergens not in bold, missing label information for prepacked food).
 */
class FoodInformationReport extends Report
{
    public function title(): string
    {
        return _t(self::class . '.Title', 'Food information to check');
    }

    public function description(): string
    {
        return _t(
            self::class . '.Description',
            'Food products that are not checked yet or whose food information needs attention.'
        );
    }

    public function sourceRecords($params = [], $sort = null, $limit = null)
    {
        $rows = ArrayList::create();
        $resolver = FoodInformationResolver::singleton();
        foreach (Product::get()->exclude('FoodInfoStatus', FoodInformationExtension::STATUS_NOT_FOOD) as $product) {
            $warnings = $resolver->resolve($product)->warnings();
            if ($warnings === []) {
                continue;
            }
            $rows->push(ArrayData::create([
                'ID' => $product->ID,
                'Title' => $product->Title,
                'Status' => $product->dbObject('FoodInfoStatus')->getValue(),
                'Warnings' => implode(' · ', $warnings),
                'CMSEditLink' => $product->CMSEditLink(),
            ]));
        }

        return $rows;
    }

    public function columns()
    {
        return [
            'Title' => [
                'title' => _t(self::class . '.Product', 'Product'),
                'formatting' => '<a href="$CMSEditLink">$Title</a>',
            ],
            'Status' => _t(FoodInformationExtension::class . '.Status', 'Status'),
            'Warnings' => _t(self::class . '.Warnings', 'To check'),
        ];
    }
}
