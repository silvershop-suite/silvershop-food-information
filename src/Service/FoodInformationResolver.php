<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Service;

use SilverShop\FoodInformation\Extension\FoodInformationExtension;
use SilverShop\Model\Variation\Variation;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * Works out the food information that applies to a record:
 *
 * - a variation without its own information uses its product's;
 * - allergens of components (a box made of flavours, a gift basket of products, the products in a product-bundles
 *   bundle) are added: "contains" beats "may contain"; "free from" only comes from the record itself and is dropped
 *   when a component contains (or may contain) it;
 * - the result is only "checked" when the record and every component are.
 */
class FoodInformationResolver
{
    use Injectable;

    private const RANK = [
        FoodInformationExtension::LEVEL_FREE_FROM => 1,
        FoodInformationExtension::LEVEL_MAY_CONTAIN => 2,
        FoodInformationExtension::LEVEL_CONTAINS => 3,
    ];

    /** Bundles (silvershop-suite/product-bundles), referenced by name so the module stays optional. */
    private const BUNDLE_CLASS = 'SilverShop\\ProductBundles\\Page\\BundleProduct';

    public function resolve(DataObject $record, ?FoodRules $rules = null): ResolvedFoodInformation
    {
        $holder = $this->holderFor($record);
        $rules ??= FoodRules::current();

        $visited = [];
        $components = $this->collectComponents($holder, $visited);

        $allergens = [];
        $reviewed = $holder->getField('FoodInfoStatus') === FoodInformationExtension::STATUS_REVIEWED;
        foreach ($this->allergensOf($holder) as $code => $row) {
            $allergens[$code] = $row;
        }
        foreach ($components as $component) {
            $reviewed = $reviewed && $component->getField('FoodInfoStatus') === FoodInformationExtension::STATUS_REVIEWED;
            foreach ($this->allergensOf($component) as $code => $row) {
                // "Free from" is a statement about the whole product: only the product's own one counts.
                if ($row['level'] === FoodInformationExtension::LEVEL_FREE_FROM) {
                    continue;
                }
                $current = $allergens[$code] ?? null;
                if ($current === null || self::RANK[$row['level']] > self::RANK[$current['level']]) {
                    $allergens[$code] = $row;
                }
            }
        }

        $status = (string) $holder->getField('FoodInfoStatus');
        if ($status === '' || $status === FoodInformationExtension::STATUS_NOT_FOOD) {
            $status = FoodInformationExtension::STATUS_NOT_FOOD;
        } elseif (!$reviewed) {
            $status = FoodInformationExtension::STATUS_INCOMPLETE;
        }

        return ResolvedFoodInformation::create($holder, $rules, $status, $allergens, $components);
    }

    /**
     * The record whose food information applies: a variation falls back to its product unless it has its own.
     */
    public function holderFor(DataObject $record): DataObject
    {
        if ($record instanceof Variation && !$record->getField('OwnFoodInformation')) {
            $product = $record->Product();
            if ($product instanceof DataObject && $product->exists() && $product->hasExtension(FoodInformationExtension::class)) {
                return $product;
            }
        }

        return $record;
    }

    /**
     * @return array<string, array{level: string, note: string}>
     */
    private function allergensOf(DataObject $record): array
    {
        return $record->hasExtension(FoodInformationExtension::class) ? FoodInformationExtension::allergensOf($record) : [];
    }

    /**
     * Components (recursively), each record once.
     *
     * @param array<string, true> $visited
     * @return DataObject[]
     */
    private function collectComponents(DataObject $record, array &$visited): array
    {
        $visited[$record->ClassName . '#' . $record->ID] = true;
        $direct = $record->hasExtension(FoodInformationExtension::class) ? FoodInformationExtension::componentsOf($record) : [];
        foreach ($this->bundleComponents($record) as $component) {
            $direct[] = $component;
        }

        $out = [];
        foreach ($direct as $component) {
            $component = $this->holderFor($component);
            $key = $component->ClassName . '#' . $component->ID;
            if (isset($visited[$key]) || $component->getField('FoodInfoStatus') === FoodInformationExtension::STATUS_NOT_FOOD) {
                continue;
            }
            $out[] = $component;
            foreach ($this->collectComponents($component, $visited) as $nested) {
                $out[] = $nested;
            }
        }

        return $out;
    }

    /**
     * @return DataObject[]
     */
    private function bundleComponents(DataObject $record): array
    {
        if (!class_exists(self::BUNDLE_CLASS) || !is_a($record, self::BUNDLE_CLASS) || !$record->hasMethod('BundleItems')) {
            return [];
        }
        $out = [];
        foreach ($record->BundleItems() as $item) {
            $buyable = $item->VariationID ? $item->Variation() : $item->Product();
            if ($buyable instanceof DataObject && $buyable->exists() && $buyable->hasExtension(FoodInformationExtension::class)) {
                $out[] = $buyable;
            }
        }

        return $out;
    }
}
