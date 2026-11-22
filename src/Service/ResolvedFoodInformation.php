<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Service;

use NumberFormatter;
use SilverShop\FoodInformation\Extension\FoodInformationExtension;
use SilverShop\FoodInformation\Model\Allergen;
use SilverShop\FoodInformation\Model\FoodClaim;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The food information that applies to one record, ready for templates ({@see FoodInformationExtension::FoodInformation()}),
 * the CMS warnings and the order-line snapshot. Built by {@see FoodInformationResolver}.
 */
class ResolvedFoodInformation extends ModelData
{
    /** @var array<string, Allergen>|null */
    private ?array $catalogue = null;

    /**
     * @param array<string, array{level: string, note: string}> $allergens effective statements by code
     * @param DataObject[] $components
     */
    public function __construct(
        private readonly DataObject $holder,
        private readonly FoodRules $rules,
        private readonly string $status,
        private readonly array $allergens,
        private readonly array $components = []
    ) {
        parent::__construct();
    }

    public function getHolder(): DataObject
    {
        return $this->holder;
    }

    public function getRules(): FoodRules
    {
        return $this->rules;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function IsFood(): bool
    {
        return $this->status !== FoodInformationExtension::STATUS_NOT_FOOD;
    }

    public function IsReviewed(): bool
    {
        return $this->status === FoodInformationExtension::STATUS_REVIEWED;
    }

    // ----- allergens ---------------------------------------------------------------------------------------------

    /**
     * Codes at a level, as stored (groups and members alike).
     *
     * @return string[]
     */
    public function codesAt(string $level): array
    {
        return array_keys(array_filter($this->allergens, fn (array $row) => $row['level'] === $level));
    }

    /**
     * What it contains, by the most specific name declared (a group is left out when one of its members is listed).
     */
    public function Contains(): ArrayList
    {
        return $this->listFor($this->displayCodes($this->codesAt(FoodInformationExtension::LEVEL_CONTAINS)));
    }

    /**
     * "May contain" (precautionary), minus anything it already contains; empty where the profile forbids it.
     */
    public function MayContain(): ArrayList
    {
        if (!$this->rules->mayContainAllowed()) {
            return ArrayList::create();
        }
        $contained = $this->related($this->codesAt(FoodInformationExtension::LEVEL_CONTAINS));
        $codes = array_filter(
            $this->codesAt(FoodInformationExtension::LEVEL_MAY_CONTAIN),
            fn (string $code) => !in_array($code, $contained, true)
        );

        return $this->listFor($this->displayCodes(array_values($codes)));
    }

    /**
     * "Free from", only once the information is checked, and never for something it (may) contain(s).
     */
    public function FreeFrom(): ArrayList
    {
        if (!$this->IsReviewed()) {
            return ArrayList::create();
        }
        $present = $this->related(array_merge(
            $this->codesAt(FoodInformationExtension::LEVEL_CONTAINS),
            $this->codesAt(FoodInformationExtension::LEVEL_MAY_CONTAIN)
        ));
        $codes = array_filter(
            $this->codesAt(FoodInformationExtension::LEVEL_FREE_FROM),
            fn (string $code) => !in_array($code, $present, true)
        );

        return $this->listFor(array_values($codes));
    }

    /**
     * The "Contains: ..." sentence where the profile wants one ('' for the EU-style "bold in the ingredients" when
     * there is an ingredients list).
     */
    public function ContainsLine(): string
    {
        $names = array_map(fn (ArrayData $row) => (string) $row->Name, $this->Contains()->toArray());
        if ($names === []) {
            return '';
        }
        $mode = $this->rules->statementMode();
        if ($mode === FoodRules::STATEMENT_EMPHASIS && $this->HasIngredients()) {
            return '';
        }
        if ($mode === FoodRules::STATEMENT_CONTAINS_REQUIRED && $this->containsGluten()) {
            $gluten = _t(self::class . '.Gluten', 'gluten');
            if (!in_array($gluten, $names, true)) {
                $names[] = $gluten;
            }
        }

        return _t(self::class . '.ContainsLine', 'Contains: {list}', ['list' => implode(', ', $names)]);
    }

    public function MayContainLine(): string
    {
        $names = array_map(fn (ArrayData $row) => (string) $row->Name, $this->MayContain()->toArray());

        return $names === [] ? '' : _t(self::class . '.MayContainLine', 'May contain: {list}', ['list' => implode(', ', $names)]);
    }

    public function FreeFromLine(): string
    {
        $names = array_map(fn (ArrayData $row) => (string) $row->Name, $this->FreeFrom()->toArray());

        return $names === [] ? '' : _t(self::class . '.FreeFromLine', 'Free from: {list}', ['list' => implode(', ', $names)]);
    }

    /**
     * EU / UK style: the allergens are in bold in the ingredients list.
     */
    public function AllergyAdvice(): string
    {
        if ($this->rules->statementMode() !== FoodRules::STATEMENT_EMPHASIS || !$this->HasIngredients()) {
            return '';
        }
        if ($this->Contains()->count() === 0) {
            return '';
        }

        return _t(self::class . '.AllergyAdvice', 'Allergy advice: allergens are shown in bold in the ingredients.');
    }

    /**
     * Checked, and nothing to declare.
     */
    public function NoAllergens(): bool
    {
        return $this->IsReviewed() && $this->Contains()->count() === 0 && $this->MayContain()->count() === 0;
    }

    // ----- other information -------------------------------------------------------------------------------------

    public function HasIngredients(): bool
    {
        return trim(strip_tags((string) $this->holder->getField('Ingredients'))) !== '';
    }

    public function Ingredients(): DBHTMLText
    {
        /** @var DBHTMLText $field */
        $field = DBField::create_field('HTMLText', (string) $this->holder->getField('Ingredients'));

        return $field;
    }

    public function LegalName(): string
    {
        return trim((string) $this->holder->getField('FoodLegalName'));
    }

    /**
     * Claims only show once the information is checked.
     */
    public function Claims(): ArrayList
    {
        $list = ArrayList::create();
        if (!$this->IsReviewed()) {
            return $list;
        }
        foreach ($this->claimRecords() as $claim) {
            $list->push(ArrayData::create(['Code' => $claim->Code, 'Name' => $claim->getName()]));
        }

        return $list;
    }

    public function Certification(): string
    {
        return $this->IsReviewed() ? trim((string) $this->holder->getField('FoodCertification')) : '';
    }

    /**
     * Label lines with a value: net quantity, storage, shelf life, use, origin, alcohol, business operator.
     */
    public function Particulars(): ArrayList
    {
        $rows = [
            'NetQuantity' => [_t(FoodInformationExtension::class . '.NetQuantity', 'Net quantity'), $this->text('NetQuantity')],
            'StorageInstructions' => [_t(FoodInformationExtension::class . '.Storage', 'Storage'), $this->text('StorageInstructions')],
            'ShelfLife' => [_t(FoodInformationExtension::class . '.ShelfLife', 'Shelf life'), $this->text('ShelfLife')],
            'UseInstructions' => [_t(FoodInformationExtension::class . '.Use', 'Instructions for use'), $this->text('UseInstructions')],
            'Origin' => [_t(FoodInformationExtension::class . '.Origin', 'Origin'), $this->text('Origin')],
        ];
        $abv = (float) $this->holder->getField('AlcoholByVolume');
        if ($abv > 0) {
            $rows['AlcoholByVolume'] = [_t(FoodInformationExtension::class . '.AlcoholShort', 'Alcohol'), $this->number($abv, 1) . ' % vol'];
        }
        $rows['BusinessOperator'] = [_t(self::class . '.BusinessOperator', 'Food business'), $this->BusinessOperator()];

        $list = ArrayList::create();
        foreach ($rows as $key => [$label, $value]) {
            if ($value !== '') {
                $list->push(ArrayData::create(['Key' => $key, 'Label' => $label, 'Value' => $value]));
            }
        }

        return $list;
    }

    public function ExtraStatements(): ArrayList
    {
        $list = ArrayList::create();
        foreach (preg_split('/\R/', (string) $this->holder->getField('ExtraStatements')) ?: [] as $line) {
            if (trim($line) !== '') {
                $list->push(ArrayData::create(['Line' => trim($line)]));
            }
        }

        return $list;
    }

    /**
     * Name and address of the business responsible for the food: the Food information setting, else the shop's legal
     * name and address from silvershop-suite/store-profile.
     */
    public function BusinessOperator(): string
    {
        $config = SiteConfig::current_site_config();
        $own = $config->hasField('FoodBusinessOperator') ? trim((string) $config->getField('FoodBusinessOperator')) : '';
        if ($own !== '') {
            return preg_replace('/\s*\R\s*/', ', ', $own) ?? $own;
        }
        if ($config->hasField('LegalName')) {
            $parts = array_filter([
                trim((string) ($config->getField('LegalName') ?: $config->getField('ShopName'))),
                trim((string) $config->getField('StoreStreet')),
                trim(trim((string) $config->getField('StorePostcode')) . ' ' . trim((string) $config->getField('StoreCity'))),
            ]);

            return implode(', ', $parts);
        }

        return '';
    }

    // ----- nutrition ---------------------------------------------------------------------------------------------

    public function HasNutrition(): bool
    {
        return (bool) $this->holder->getField('HasNutrition');
    }

    public function NutritionBasis(): string
    {
        return $this->holder->getField('NutritionBasis') === '100ml' ? '100 ml' : '100 g';
    }

    public function NutritionServing(): string
    {
        return $this->text('NutritionServing');
    }

    /**
     * Rows for the nutrition table; energy is one row in kJ / kcal.
     */
    public function NutritionRows(): ArrayList
    {
        $list = ArrayList::create();
        if (!$this->HasNutrition()) {
            return $list;
        }
        $list->push(ArrayData::create([
            'Label' => _t(FoodInformationExtension::class . '.NutritionEnergyKJ', 'Energy'),
            'Value' => $this->number((float) $this->holder->getField('NutritionEnergyKJ'), 0) . ' kJ / '
                . $this->number((float) $this->holder->getField('NutritionEnergyKcal'), 0) . ' kcal',
            'Sub' => false,
        ]));
        foreach (FoodInformationExtension::NUTRIENTS as $field => [$label, $unit]) {
            if (str_starts_with($field, 'NutritionEnergy')) {
                continue;
            }
            if ($field === 'NutritionFibre' && (float) $this->holder->getField($field) === 0.0) {
                continue;
            }
            $list->push(ArrayData::create([
                'Label' => _t(FoodInformationExtension::class . '.' . $field, $label),
                'Value' => $this->number((float) $this->holder->getField($field), 1) . ' ' . $unit,
                'Sub' => in_array($field, ['NutritionSaturates', 'NutritionSugars'], true),
            ]));
        }

        return $list;
    }

    public function NutritionExemptionText(): string
    {
        $code = (string) $this->holder->getField('NutritionExemption');
        if ($this->HasNutrition() || $code === '') {
            return '';
        }

        return _t(self::class . '.Exemption_' . $code, 'No nutrition declaration required for this product.');
    }

    // ----- checks ------------------------------------------------------------------------------------------------

    /**
     * Things to check in the CMS (and in the Food information report). Advisory: never blocks saving.
     *
     * @return string[]
     */
    public function warnings(): array
    {
        if (!$this->IsFood()) {
            return [];
        }
        $warnings = [];
        $ext = FoodInformationExtension::class;

        if ($this->holder->getField('FoodInfoStatus') !== FoodInformationExtension::STATUS_REVIEWED) {
            $warnings[] = _t($ext . '.W_NotReviewed', 'The food information is not checked yet. The product page asks customers with an allergy to contact you.');
        }
        foreach ($this->components as $component) {
            if ($component->getField('FoodInfoStatus') !== FoodInformationExtension::STATUS_REVIEWED) {
                $warnings[] = _t($ext . '.W_ComponentNotReviewed', 'Not checked yet: "{name}" (part of this product).', ['name' => $component->getTitle()]);
            }
        }

        $own = $this->ownAllergens();
        foreach ($this->rules->nameSpecificGroups() as $group) {
            $level = $own[$group]['level'] ?? '';
            if ($level !== FoodInformationExtension::LEVEL_CONTAINS && $level !== FoodInformationExtension::LEVEL_MAY_CONTAIN) {
                continue;
            }
            $members = array_diff(FoodRules::selfAndDescendants($group), [$group]);
            if (array_intersect($members, array_keys($own)) === []) {
                $warnings[] = _t($ext . '.W_NameSpecific', '"{group}" must be declared by name (which one?).', ['group' => $this->name($group)]);
            }
        }

        if (!$this->rules->mayContainAllowed() && $this->codesAt(FoodInformationExtension::LEVEL_MAY_CONTAIN) !== []) {
            $warnings[] = _t($ext . '.W_MayContainProhibited', '"May contain" is not allowed under the selected rules ({profile}); it is not shown.', ['profile' => $this->rules->getTitle()]);
        }

        $contained = [];
        foreach ($this->codesAt(FoodInformationExtension::LEVEL_CONTAINS) as $code) {
            $contained = array_merge($contained, FoodRules::selfAndAncestors($code));
        }
        foreach ($this->claimRecords() as $claim) {
            $conflict = array_intersect($claim->conflictCodes(), $contained);
            if ($conflict !== []) {
                $warnings[] = _t($ext . '.W_ClaimConflict', '"{claim}" does not fit with "{allergen}".', [
                    'claim' => $claim->getName(),
                    'allergen' => $this->name((string) reset($conflict)),
                ]);
            }
            if ($claim->NeedsCertificate && trim((string) $this->holder->getField('FoodCertification')) === '') {
                $warnings[] = _t($ext . '.W_Certificate', '"{claim}" needs the certifier and certificate number under Certification.', ['claim' => $claim->getName()]);
            }
        }

        foreach ($this->emphasisWarnings() as $warning) {
            $warnings[] = $warning;
        }

        if ($this->holder->getField('PackagingType') === 'Prepacked') {
            if (!$this->HasIngredients()) {
                $warnings[] = _t($ext . '.W_NoIngredients', 'Prepacked food needs an ingredients list.');
            }
            if ($this->text('NetQuantity') === '') {
                $warnings[] = _t($ext . '.W_NoNetQuantity', 'Prepacked food needs the net quantity.');
            }
            if (!$this->HasNutrition() && (string) $this->holder->getField('NutritionExemption') === '') {
                $warnings[] = _t($ext . '.W_NoNutrition', 'Prepacked food needs nutrition values, or the reason it is exempt.');
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * The order-line copy: what the customer was shown when they ordered.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        $names = fn (ArrayList $list) => array_map(fn (ArrayData $row) => (string) $row->Name, $list->toArray());

        return array_filter([
            'profile' => $this->rules->getKey(),
            'status' => $this->status,
            'legal_name' => $this->LegalName(),
            'ingredients' => trim(html_entity_decode(strip_tags((string) $this->holder->getField('Ingredients')))),
            'contains' => $names($this->Contains()),
            'may_contain' => $names($this->MayContain()),
            'claims' => $names($this->Claims()),
            'storage' => $this->text('StorageInstructions'),
            'shelf_life' => $this->text('ShelfLife'),
            'extra' => array_map(fn (ArrayData $row) => (string) $row->Line, $this->ExtraStatements()->toArray()),
        ], fn ($value) => $value !== '' && $value !== []);
    }

    // ----- internals ---------------------------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function emphasisWarnings(): array
    {
        $mode = $this->rules->statementMode();
        if (!$this->HasIngredients() || !in_array($mode, [FoodRules::STATEMENT_EMPHASIS, FoodRules::STATEMENT_CONTAINS_REQUIRED], true)) {
            return [];
        }
        $html = (string) $this->holder->getField('Ingredients');
        $plain = mb_strtolower(html_entity_decode(strip_tags($html)));
        preg_match_all('#<(strong|b)\b[^>]*>(.*?)</\1>#is', $html, $matches);
        $bold = mb_strtolower(html_entity_decode(strip_tags(implode(' ', $matches[2] ?? []))));

        $warnings = [];
        foreach ($this->Contains() as $row) {
            $stems = $this->stems((string) $row->Code, (string) $row->Name);
            $mentioned = $this->containsAny($plain, $stems);
            if (!$mentioned) {
                $warnings[] = _t(FoodInformationExtension::class . '.W_NotInIngredients', '"{name}" is declared but not found in the ingredients (named differently, e.g. "whey (milk)"?).', ['name' => $row->Name]);
            } elseif (!$this->containsAny($bold, $stems)) {
                $warnings[] = _t(FoodInformationExtension::class . '.W_NotBold', '"{name}" is in the ingredients but not in bold.', ['name' => $row->Name]);
            }
        }

        return $warnings;
    }

    /**
     * @return string[]
     */
    private function stems(string $code, string $name): array
    {
        $seed = (array) (Allergen::config()->get('catalogue')[$code] ?? []);
        $stems = [];
        foreach (array_filter([$name, (string) ($seed[2] ?? '')]) as $word) {
            $word = mb_strtolower(trim($word));
            $stems[] = mb_substr($word, 0, max(3, mb_strlen($word) - 2));
        }

        return array_values(array_unique($stems));
    }

    /**
     * @param string[] $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsGluten(): bool
    {
        foreach ($this->codesAt(FoodInformationExtension::LEVEL_CONTAINS) as $code) {
            if (in_array('gluten_cereals', FoodRules::selfAndAncestors($code), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record's own statements (not its components').
     *
     * @return array<string, array{level: string, note: string}>
     */
    private function ownAllergens(): array
    {
        return FoodInformationExtension::allergensOf($this->holder);
    }

    /**
     * @return FoodClaim[]
     */
    private function claimRecords(): array
    {
        $codes = FoodInformationExtension::claimCodesOf($this->holder);
        if ($codes === []) {
            return [];
        }

        return FoodClaim::get()->filter('Code', $codes)->toArray();
    }

    /**
     * Drop a group when one of its members is also in the list (say "hazelnut", not "tree nuts, hazelnut").
     *
     * @param string[] $codes
     * @return string[]
     */
    private function displayCodes(array $codes): array
    {
        return array_values(array_filter($codes, function (string $code) use ($codes) {
            $members = array_diff(FoodRules::selfAndDescendants($code), [$code]);

            return array_intersect($members, $codes) === [];
        }));
    }

    /**
     * The codes plus everything above and below them.
     *
     * @param string[] $codes
     * @return string[]
     */
    private function related(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out = array_merge($out, FoodRules::selfAndAncestors($code), FoodRules::selfAndDescendants($code));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param string[] $codes
     */
    private function listFor(array $codes): ArrayList
    {
        $catalogue = $this->catalogue();
        usort($codes, fn (string $a, string $b) => ($catalogue[$a]->Sort ?? 9999) <=> ($catalogue[$b]->Sort ?? 9999));
        $list = ArrayList::create();
        foreach ($codes as $code) {
            $list->push(ArrayData::create([
                'Code' => $code,
                'Name' => $this->name($code),
                'Note' => (string) ($this->allergens[$code]['note'] ?? ''),
            ]));
        }

        return $list;
    }

    private function name(string $code): string
    {
        $catalogue = $this->catalogue();
        if (isset($catalogue[$code])) {
            return $catalogue[$code]->getName();
        }

        return (string) Allergen::create(['Code' => $code])->getName();
    }

    /**
     * @return array<string, Allergen>
     */
    private function catalogue(): array
    {
        if ($this->catalogue === null) {
            $this->catalogue = [];
            foreach (Allergen::get() as $allergen) {
                $this->catalogue[(string) $allergen->Code] = $allergen;
            }
        }

        return $this->catalogue;
    }

    private function text(string $field): string
    {
        return trim((string) $this->holder->getField($field));
    }

    private function number(float $value, int $decimals): string
    {
        $formatter = NumberFormatter::create(i18n::get_locale(), NumberFormatter::DECIMAL);
        if (!$formatter instanceof NumberFormatter) {
            return number_format($value, $decimals);
        }
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return (string) $formatter->format($value);
    }
}
