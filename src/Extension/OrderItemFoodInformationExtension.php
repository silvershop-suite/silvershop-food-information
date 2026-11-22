<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Extension;

use SilverShop\FoodInformation\Service\FoodInformationResolver;
use SilverShop\Model\OrderItem;
use SilverStripe\Core\Extension;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Keeps a copy of the food information on each order line when the order is placed, so the packing slip, emails and
 * order history show what the customer was told, even after the product changes.
 *
 * @extends Extension<OrderItem>
 */
class OrderItemFoodInformationExtension extends Extension
{
    private static array $db = [
        'FoodInformationSnapshot' => 'Text',
    ];

    /**
     * Runs from OrderItem::onPlacement() (the hook core uses to freeze prices and titles).
     */
    public function onPlacement(): void
    {
        $item = $this->getOwner();
        $buyable = $item->Buyable();
        if (!$buyable instanceof DataObject || !$buyable->hasExtension(FoodInformationExtension::class)) {
            return;
        }
        $info = FoodInformationResolver::singleton()->resolve($buyable);
        if (!$info->IsFood()) {
            return;
        }
        $item->setField('FoodInformationSnapshot', (string) json_encode($info->toSnapshot(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function foodInformationSnapshot(): array
    {
        $decoded = json_decode((string) $this->getOwner()->getField('FoodInformationSnapshot'), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function HasFoodInformation(): bool
    {
        return $this->foodInformationSnapshot() !== [];
    }

    /**
     * Short lines for packing slips and emails: "Contains: ...", "May contain: ...", storage.
     */
    public function FoodInformationLines(): ArrayList
    {
        $snapshot = $this->foodInformationSnapshot();
        $lines = ArrayList::create();
        $labels = [
            'contains' => _t(self::class . '.Contains', 'Contains: {list}'),
            'may_contain' => _t(self::class . '.MayContain', 'May contain: {list}'),
        ];
        foreach ($labels as $key => $template) {
            if (!empty($snapshot[$key]) && is_array($snapshot[$key])) {
                $lines->push(ArrayData::create(['Line' => str_replace('{list}', implode(', ', $snapshot[$key]), $template)]));
            }
        }
        foreach (['storage', 'shelf_life'] as $key) {
            if (!empty($snapshot[$key]) && is_string($snapshot[$key])) {
                $lines->push(ArrayData::create(['Line' => $snapshot[$key]]));
            }
        }

        return $lines;
    }

    public function FoodInformationSummary(): ?DBHTMLText
    {
        if (!$this->HasFoodInformation()) {
            return null;
        }

        return $this->getOwner()->renderWith('SilverShop\\FoodInformation\\Includes\\OrderItemFoodInformation');
    }
}
