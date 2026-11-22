<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Extension;

use SilverShop\FoodInformation\Forms\AllergenMatrixField;
use SilverShop\FoodInformation\Model\FoodClaim;
use SilverShop\FoodInformation\Service\FoodInformationResolver;
use SilverShop\FoodInformation\Service\ResolvedFoodInformation;
use SilverShop\Model\Variation\Variation;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;

/**
 * Food information on any DataObject: products and variations by default, and whatever a project adds it to (a flavour,
 * a recipe, a menu item). Statements are stored as JSON on the record itself, so a versioned product's food information
 * follows its draft / publish cycle.
 *
 * - `FoodAllergenData`  {"milk":{"level":"contains"},"hazelnut":{"level":"may_contain","note":"shared line"}}
 * - `FoodClaimData`     ["vegan","organic"]
 * - `FoodComponentData` ["SilverShop\\Page\\Product#12", ...] (records whose allergens are combined into this one)
 *
 * Templates: `$FoodInformation` renders the product-page block, `$HasFoodInformation` tells whether there is one,
 * `$ResolvedFoodInformation` gives the data for a custom layout.
 *
 * @extends Extension<DataObject>
 */
class FoodInformationExtension extends Extension
{
    public const STATUS_NOT_FOOD = 'NotFood';
    public const STATUS_INCOMPLETE = 'Incomplete';
    public const STATUS_REVIEWED = 'Reviewed';

    public const LEVEL_CONTAINS = 'contains';
    public const LEVEL_MAY_CONTAIN = 'may_contain';
    public const LEVEL_FREE_FROM = 'free_from';

    /**
     * Classes whose records can be chosen as components of a mixed product.
     *
     * @var string[]
     */
    private static array $component_classes = [];

    private static array $db = [
        'FoodInfoStatus' => "Enum('NotFood,Incomplete,Reviewed','NotFood')",
        'FoodReviewedAt' => 'Datetime',
        'FoodReviewedBy' => 'Varchar(255)',
        'OwnFoodInformation' => 'Boolean',
        'FoodAllergenData' => 'Text',
        'FoodClaimData' => 'Text',
        'FoodCertification' => 'Varchar(255)',
        'FoodComponentData' => 'Text',
        'Ingredients' => 'HTMLText',
        'FoodLegalName' => 'Varchar(255)',
        'NetQuantity' => 'Varchar(64)',
        'PackagingType' => "Enum('Unspecified,Prepacked,PackedForDirectSale,NonPrepacked','Unspecified')",
        'StorageInstructions' => 'Text',
        'UseInstructions' => 'Text',
        'ShelfLife' => 'Varchar(255)',
        'Origin' => 'Varchar(255)',
        'AlcoholByVolume' => 'Decimal(4,1)',
        'ExtraStatements' => 'Text',
        'HasNutrition' => 'Boolean',
        'NutritionBasis' => "Enum('100g,100ml','100g')",
        'NutritionServing' => 'Varchar(64)',
        'NutritionEnergyKJ' => 'Decimal(8,1)',
        'NutritionEnergyKcal' => 'Decimal(8,1)',
        'NutritionFat' => 'Decimal(8,2)',
        'NutritionSaturates' => 'Decimal(8,2)',
        'NutritionCarbohydrate' => 'Decimal(8,2)',
        'NutritionSugars' => 'Decimal(8,2)',
        'NutritionFibre' => 'Decimal(8,2)',
        'NutritionProtein' => 'Decimal(8,2)',
        'NutritionSalt' => 'Decimal(8,2)',
        'NutritionExemption' => 'Varchar(32)',
    ];

    /**
     * The nutrition values in label order: field => [English label, unit].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const NUTRIENTS = [
        'NutritionEnergyKJ' => ['Energy', 'kJ'],
        'NutritionEnergyKcal' => ['Energy', 'kcal'],
        'NutritionFat' => ['Fat', 'g'],
        'NutritionSaturates' => ['of which saturates', 'g'],
        'NutritionCarbohydrate' => ['Carbohydrate', 'g'],
        'NutritionSugars' => ['of which sugars', 'g'],
        'NutritionFibre' => ['Fibre', 'g'],
        'NutritionProtein' => ['Protein', 'g'],
        'NutritionSalt' => ['Salt', 'g'],
    ];

    public const EXEMPTIONS = ['small_quantity', 'small_surface', 'alcoholic_drink', 'other'];

    // ----- data access -------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{level: string, note: string}>
     */
    public function foodAllergens(): array
    {
        return self::allergensOf($this->getOwner());
    }

    /**
     * @return array<string, array{level: string, note: string}>
     */
    public static function allergensOf(DataObject $record): array
    {
        $decoded = json_decode((string) $record->getField('FoodAllergenData'), true);
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $code => $row) {
            $level = is_array($row) ? (string) ($row['level'] ?? '') : '';
            if (in_array($level, [self::LEVEL_CONTAINS, self::LEVEL_MAY_CONTAIN, self::LEVEL_FREE_FROM], true)) {
                $out[(string) $code] = ['level' => $level, 'note' => (string) ($row['note'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{level: string, note?: string}|string> $allergens code => level, or code => [level, note]
     */
    public function setFoodAllergens(array $allergens): DataObject
    {
        $this->getOwner()->setField('FoodAllergenData', self::encodeAllergens($allergens));

        return $this->getOwner();
    }

    /**
     * @param array<string, mixed> $allergens
     */
    public static function encodeAllergens(array $allergens): string
    {
        $clean = [];
        foreach ($allergens as $code => $row) {
            $level = is_array($row) ? (string) ($row['level'] ?? '') : (string) $row;
            if (!in_array($level, [self::LEVEL_CONTAINS, self::LEVEL_MAY_CONTAIN, self::LEVEL_FREE_FROM], true)) {
                continue;
            }
            $entry = ['level' => $level];
            $note = is_array($row) ? trim((string) ($row['note'] ?? '')) : '';
            if ($note !== '') {
                $entry['note'] = $note;
            }
            $clean[(string) $code] = $entry;
        }
        ksort($clean);

        return $clean === [] ? '' : (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return string[]
     */
    public function foodClaimCodes(): array
    {
        return self::claimCodesOf($this->getOwner());
    }

    /**
     * @return string[]
     */
    public static function claimCodesOf(DataObject $record): array
    {
        $decoded = json_decode((string) $record->getField('FoodClaimData'), true);

        return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
    }

    /**
     * @return DataObject[]
     */
    public function foodComponents(): array
    {
        return self::componentsOf($this->getOwner());
    }

    /**
     * @return DataObject[]
     */
    public static function componentsOf(DataObject $record): array
    {
        $decoded = json_decode((string) $record->getField('FoodComponentData'), true);
        $allowed = self::componentClasses();
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $ref) {
            [$class, $id] = array_pad(explode('#', (string) $ref, 2), 2, '0');
            if (!in_array($class, $allowed, true) || !class_exists($class)) {
                continue;
            }
            $record = DataObject::get_by_id($class, (int) $id);
            if ($record instanceof DataObject && $record->hasExtension(self::class)) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public static function componentClasses(): array
    {
        return array_values(array_map('strval', (array) Config::inst()->get(self::class, 'component_classes')));
    }

    // ----- template API ------------------------------------------------------------------------------------------

    public function ResolvedFoodInformation(): ResolvedFoodInformation
    {
        return FoodInformationResolver::singleton()->resolve($this->getOwner());
    }

    public function HasFoodInformation(): bool
    {
        return $this->ResolvedFoodInformation()->IsFood();
    }

    public function FoodInformation(): ?DBHTMLText
    {
        $info = $this->ResolvedFoodInformation();
        if (!$info->IsFood()) {
            return null;
        }

        return $info->renderWith('SilverShop\\FoodInformation\\Includes\\FoodInformation');
    }

    // ----- lifecycle ---------------------------------------------------------------------------------------------

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        if ($owner->getField('FoodInfoStatus') === self::STATUS_REVIEWED && $owner->isChanged('FoodInfoStatus')) {
            $owner->setField('FoodReviewedAt', DBDatetime::now()->getValue());
            $member = Security::getCurrentUser();
            $owner->setField('FoodReviewedBy', $member ? $member->getName() : '');
        }
    }

    // ----- CMS ---------------------------------------------------------------------------------------------------

    protected function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();
        // silvershop's Product scaffolds extension $db fields into Root.Main; drop them before adding our own tab.
        $fields->removeByName(array_keys((array) Config::inst()->get(self::class, 'db', Config::UNINHERITED)));

        $tab = 'Root.FoodInformation';
        $fields->findOrMakeTab($tab, _t(self::class . '.Tab', 'Food information'));

        $add = [];
        $add[] = DropdownField::create('FoodInfoStatus', _t(self::class . '.Status', 'Status'), [
            self::STATUS_NOT_FOOD => _t(self::class . '.Status_NotFood', 'Not food (no food information)'),
            self::STATUS_INCOMPLETE => _t(self::class . '.Status_Incomplete', 'Food, information not checked yet'),
            self::STATUS_REVIEWED => _t(self::class . '.Status_Reviewed', 'Food, information checked'),
        ])->setDescription($this->reviewedDescription());

        if ($owner instanceof Variation) {
            $add[] = CheckboxField::create('OwnFoodInformation', _t(
                self::class . '.OwnFoodInformation',
                'This variation has its own food information (otherwise the product\'s applies)'
            ));
        }

        $warnings = $owner->isInDB() ? $this->ResolvedFoodInformation()->warnings() : [];
        if ($warnings !== []) {
            $items = implode('', array_map(fn (string $w) => '<li>' . htmlspecialchars($w) . '</li>', $warnings));
            $add[] = LiteralField::create('FoodWarnings', '<div class="alert alert-warning"><strong>'
                . htmlspecialchars(_t(self::class . '.CheckThese', 'Please check')) . '</strong><ul>' . $items . '</ul></div>');
        }

        $add[] = HeaderField::create('FoodAllergensHeader', _t(self::class . '.Allergens', 'Allergens'), 3);
        $add[] = AllergenMatrixField::create('FoodAllergenData', '');
        $add[] = HTMLEditorField::create('Ingredients', _t(self::class . '.Ingredients', 'Ingredients'))
            ->setRows(6)
            ->setDescription(_t(
                self::class . '.IngredientsHelp',
                'The ingredients in descending order of weight. Put every allergen in bold, e.g. sugar, <strong>hazelnuts</strong>, cocoa butter, whole <strong>milk</strong> powder.'
            ));

        $add[] = HeaderField::create('FoodClaimsHeader', _t(self::class . '.Claims', 'Diet and free-from claims'), 3);
        $add[] = CheckboxSetField::create('FoodClaimData', '', FoodClaim::get()->filter('Hidden', false)->map('Code', 'Name'));
        $add[] = TextField::create('FoodCertification', _t(self::class . '.Certification', 'Certification'))
            ->setDescription(_t(self::class . '.CertificationHelp', 'For organic, halal or kosher: the control body or certifier and the certificate number.'));

        $components = $this->componentOptions();
        if ($components !== []) {
            $add[] = HeaderField::create('FoodComponentsHeader', _t(self::class . '.Components', 'Made of'), 3);
            $add[] = ListboxField::create('FoodComponentData', '', $components)->setDescription(_t(
                self::class . '.ComponentsHelp',
                'For a box, assortment or gift basket: the products it contains. Their allergens are added to this one.'
            ));
        }

        $add[] = HeaderField::create('FoodLabelHeader', _t(self::class . '.Label', 'Label information'), 3);
        $add[] = TextField::create('FoodLegalName', _t(self::class . '.LegalName', 'Name of the food'))
            ->setDescription(_t(self::class . '.LegalNameHelp', 'The descriptive name, e.g. "Milk chocolate pralines with hazelnut filling". Empty = the product title.'));
        $add[] = DropdownField::create('PackagingType', _t(self::class . '.PackagingType', 'Packaging'), [
            'Unspecified' => _t(self::class . '.Packaging_Unspecified', '(not specified)'),
            'Prepacked' => _t(self::class . '.Packaging_Prepacked', 'Prepacked (packed before it is offered for sale)'),
            'PackedForDirectSale' => _t(self::class . '.Packaging_PackedForDirectSale', 'Packed on the premises for direct sale'),
            'NonPrepacked' => _t(self::class . '.Packaging_NonPrepacked', 'Not prepacked / made to order'),
        ]);
        $add[] = TextField::create('NetQuantity', _t(self::class . '.NetQuantity', 'Net quantity'))
            ->setDescription(_t(self::class . '.NetQuantityHelp', 'E.g. 250 g, 0.75 l, 12 pieces.'));
        $add[] = TextareaField::create('StorageInstructions', _t(self::class . '.Storage', 'Storage'))->setRows(2);
        $add[] = TextField::create('ShelfLife', _t(self::class . '.ShelfLife', 'Shelf life'))
            ->setDescription(_t(self::class . '.ShelfLifeHelp', 'E.g. "at least 3 weeks after delivery". The exact date is on the product you receive.'));
        $add[] = TextareaField::create('UseInstructions', _t(self::class . '.Use', 'Instructions for use'))->setRows(2);
        $add[] = TextField::create('Origin', _t(self::class . '.Origin', 'Origin'));
        $add[] = NumericField::create('AlcoholByVolume', _t(self::class . '.Alcohol', 'Alcohol (% vol)'))->setScale(1);
        $add[] = TextareaField::create('ExtraStatements', _t(self::class . '.ExtraStatements', 'Other mandatory statements'))
            ->setRows(2)
            ->setDescription(_t(self::class . '.ExtraStatementsHelp', 'One per line, e.g. "Cocoa solids: 70% minimum".'));

        $add[] = HeaderField::create('FoodNutritionHeader', _t(self::class . '.Nutrition', 'Nutrition'), 3);
        $add[] = CheckboxField::create('HasNutrition', _t(self::class . '.HasNutrition', 'Show nutrition values'));
        $add[] = DropdownField::create('NutritionBasis', _t(self::class . '.NutritionBasis', 'Values per'), [
            '100g' => '100 g',
            '100ml' => '100 ml',
        ]);
        foreach (self::NUTRIENTS as $field => [$label, $unit]) {
            $add[] = NumericField::create($field, _t(self::class . '.' . $field, $label) . ' (' . $unit . ')')->setScale(2);
        }
        $add[] = TextField::create('NutritionServing', _t(self::class . '.Serving', 'Portion (optional)'))
            ->setDescription(_t(self::class . '.ServingHelp', 'E.g. "1 bonbon (12 g)".'));
        $exemptions = ['' => _t(self::class . '.Exemption_none', '(none)')];
        foreach (self::EXEMPTIONS as $code) {
            $exemptions[$code] = _t(self::class . '.Exemption_' . $code, $code);
        }
        $add[] = DropdownField::create('NutritionExemption', _t(self::class . '.Exemption', 'No nutrition values because'), $exemptions);

        $fields->addFieldsToTab($tab, $add);
    }

    private function reviewedDescription(): string
    {
        $owner = $this->getOwner();
        $at = (string) $owner->getField('FoodReviewedAt');
        if ($at === '') {
            return _t(
                self::class . '.StatusHelp',
                'Until the information is checked, the product page asks customers with an allergy to contact you.'
            );
        }

        $field = $owner->dbObject('FoodReviewedAt');

        return _t(self::class . '.ReviewedOn', 'Last checked {date} by {name}.', [
            'date' => $field instanceof DBDatetime ? $field->Nice() : $at,
            'name' => (string) $owner->getField('FoodReviewedBy') ?: '-',
        ]);
    }

    /**
     * @return array<string, string> "Class#ID" => title
     */
    private function componentOptions(): array
    {
        $owner = $this->getOwner();
        $options = [];
        foreach (self::componentClasses() as $class) {
            if (!class_exists($class) || !singleton($class)->hasExtension(self::class)) {
                continue;
            }
            foreach (DataObject::get($class)->exclude('FoodInfoStatus', self::STATUS_NOT_FOOD) as $record) {
                if ($record->ClassName === $owner->ClassName && (int) $record->ID === (int) $owner->ID) {
                    continue;
                }
                $options[$class . '#' . $record->ID] = (string) $record->getTitle();
            }
        }
        asort($options);

        return $options;
    }

    /**
     * Template helper for custom layouts: one row per nutrient that has a value.
     *
     * @return ArrayData[]
     */
    public function nutritionRows(): array
    {
        $owner = $this->getOwner();
        $rows = [];
        foreach (self::NUTRIENTS as $field => [$label, $unit]) {
            $rows[] = ArrayData::create([
                'Field' => $field,
                'Label' => _t(self::class . '.' . $field, $label),
                'Value' => (float) $owner->getField($field),
                'Unit' => $unit,
            ]);
        }

        return $rows;
    }
}
