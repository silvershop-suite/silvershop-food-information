<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Forms;

use SilverShop\FoodInformation\Extension\FoodInformationExtension;
use SilverShop\FoodInformation\Model\Allergen;
use SilverShop\FoodInformation\Service\FoodRules;
use SilverStripe\Forms\FormField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * One row per allergen (groups with their members indented): not specified / contains / may contain / free from, plus
 * an optional note. Allergens the selected rules make mandatory are marked. Stores the JSON of
 * {@see FoodInformationExtension::encodeAllergens()}.
 */
class AllergenMatrixField extends FormField
{
    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;

    public function setValue($value, $data = null)
    {
        $this->value = is_array($value) ? FoodInformationExtension::encodeAllergens($value) : (string) $value;

        return $this;
    }

    public function setSubmittedValue($value, $data = null)
    {
        return $this->setValue(is_array($value) ? $value : []);
    }

    public function dataValue()
    {
        return (string) $this->value;
    }

    /**
     * @return array<string, array{level: string, note: string}>
     */
    private function current(): array
    {
        $decoded = json_decode((string) $this->value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function Rules(): FoodRules
    {
        return FoodRules::current();
    }

    public function Levels(): ArrayList
    {
        return ArrayList::create([
            ArrayData::create(['Value' => '', 'Title' => _t(self::class . '.Unspecified', 'Not specified')]),
            ArrayData::create(['Value' => FoodInformationExtension::LEVEL_CONTAINS, 'Title' => _t(self::class . '.Contains', 'Contains')]),
            ArrayData::create(['Value' => FoodInformationExtension::LEVEL_MAY_CONTAIN, 'Title' => _t(self::class . '.MayContain', 'May contain')]),
            ArrayData::create(['Value' => FoodInformationExtension::LEVEL_FREE_FROM, 'Title' => _t(self::class . '.FreeFrom', 'Free from')]),
        ]);
    }

    /**
     * Flattened tree of the catalogue: mandatory allergens first (in catalogue order), then the others.
     */
    public function Rows(): ArrayList
    {
        $current = $this->current();
        $mandatory = $this->Rules()->mandatoryCodes();
        $byParent = [];
        foreach (Allergen::get() as $allergen) {
            if ($allergen->Hidden && !isset($current[$allergen->Code])) {
                continue;
            }
            $byParent[(int) $allergen->ParentID][] = $allergen;
        }

        $rows = ArrayList::create();
        $walk = function (int $parentId, int $depth) use (&$walk, &$rows, $byParent, $current, $mandatory): void {
            foreach ($byParent[$parentId] ?? [] as $allergen) {
                $code = (string) $allergen->Code;
                $levels = ArrayList::create();
                foreach ($this->Levels() as $level) {
                    $levels->push(ArrayData::create([
                        'Value' => $level->Value,
                        'Title' => $level->Title,
                        'Checked' => ($current[$code]['level'] ?? '') === $level->Value,
                        'ID' => $this->ID() . '_' . $code . '_' . ($level->Value ?: 'none'),
                    ]));
                }
                $rows->push(ArrayData::create([
                    'Code' => $code,
                    'Name' => $allergen->getName(),
                    'Depth' => $depth,
                    'Indent' => $depth * 1.5,
                    'Mandatory' => in_array($code, $mandatory, true),
                    'Note' => (string) ($current[$code]['note'] ?? ''),
                    'Levels' => $levels,
                    'InputName' => $this->getName() . '[' . $code . ']',
                ]));
                $walk((int) $allergen->ID, $depth + 1);
            }
        };
        $walk(0, 0);

        return $rows;
    }
}
