<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * One entry of the allergen catalogue (e.g. "milk", "tree nuts" > "hazelnut"), keyed by a stable `Code`.
 *
 * The catalogue is seeded from {@see self::$catalogue} on dev/build and can be extended in the CMS (Food information
 * admin). Products refer to allergens by code, so a code never changes; seeded entries can be hidden but not deleted.
 * Which entries a country requires is decided by the rules profile ({@see \SilverShop\FoodInformation\Service\FoodRules}).
 *
 * @property string $Code
 * @property string $GS1Code
 * @property string $CustomName
 * @property int $Sort
 * @property bool $Hidden
 * @property bool $IsSeeded
 * @property int $ParentID
 * @method Allergen Parent()
 * @method HasManyList<Allergen> Children()
 */
class Allergen extends DataObject
{
    private static string $table_name = 'SilverShop_Allergen';

    private static string $singular_name = 'Allergen';

    private static string $plural_name = 'Allergens';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'GS1Code' => 'Varchar(16)',
        // Empty = the translated default name for this code (follows the site language).
        'CustomName' => 'Varchar(255)',
        'Sort' => 'Int',
        'Hidden' => 'Boolean',
        'IsSeeded' => 'Boolean',
    ];

    private static array $has_one = [
        'Parent' => Allergen::class,
    ];

    private static array $has_many = [
        'Children' => Allergen::class . '.Parent',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $field_labels = [
        'CustomName' => 'Name',
        'GS1Code' => 'GS1 allergen code',
    ];

    private static array $summary_fields = [
        'Name' => 'Name',
        'Code' => 'Code',
        'ParentName' => 'Group',
        'GS1Code' => 'GS1',
        'Hidden.Nice' => 'Hidden',
    ];

    /**
     * The seeded catalogue: code => [parent code, GS1 AllergenTypeCode, English name]. The union of the allergens the
     * shipped rules profiles refer to. Add your own in YAML or in the CMS.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private static array $catalogue = [];

    public function getName(): string
    {
        $title = trim((string) $this->getField('CustomName'));
        if ($title !== '') {
            return $title;
        }

        $seed = static::config()->get('catalogue')[$this->Code] ?? null;

        return _t(self::class . '.Title_' . $this->Code, is_array($seed) ? (string) ($seed[2] ?? $this->Code) : $this->Code);
    }

    public function getTitle(): string
    {
        return $this->getName();
    }

    public function getCMSFields(): \SilverStripe\Forms\FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('CustomName')?->setDescription(_t(
            self::class . '.TitleHelp',
            'Leave empty to use the translated default name ({name}).',
            ['name' => $this->getName()]
        ));
        $fields->dataFieldByName('Code')?->setDescription(_t(
            self::class . '.CodeHelp',
            'Stable key used by products and imports (lowercase, underscores). Do not change it once in use.'
        ));
        if ($this->IsSeeded) {
            $fields->dataFieldByName('Code')?->setReadonly(true);
        }
        $fields->removeByName(['IsSeeded', 'Sort', 'Children']);

        return $fields;
    }

    public function getParentName(): string
    {
        $parent = $this->Parent();

        return $parent->exists() ? $parent->getName() : '';
    }

    public static function byCode(string $code): ?Allergen
    {
        $allergen = Allergen::get()->filter('Code', $code)->first();

        return $allergen instanceof Allergen ? $allergen : null;
    }

    /**
     * Codes of this entry and every entry below it (e.g. tree_nuts + all named nuts).
     *
     * @return string[]
     */
    public function selfAndDescendantCodes(): array
    {
        $codes = [$this->Code];
        foreach ($this->Children() as $child) {
            $codes = array_merge($codes, $child->selfAndDescendantCodes());
        }

        return $codes;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        \SilverShop\FoodInformation\Service\AllergenTree::singleton()->flush();
    }

    public function canDelete($member = null): bool
    {
        return !$this->IsSeeded && parent::canDelete($member);
    }

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        $catalogue = (array) static::config()->get('catalogue');
        $sort = 0;
        $byCode = [];
        foreach ($catalogue as $code => $seed) {
            $sort += 10;
            $allergen = Allergen::byCode((string) $code) ?? Allergen::create(['Code' => (string) $code]);
            if (!$allergen->isInDB()) {
                $allergen->update(['GS1Code' => (string) ($seed[1] ?? ''), 'Sort' => $sort, 'IsSeeded' => true]);
                $allergen->write();
            }
            $byCode[(string) $code] = $allergen;
        }
        foreach ($catalogue as $code => $seed) {
            $parent = (string) ($seed[0] ?? '');
            $allergen = $byCode[(string) $code];
            if ($parent !== '' && isset($byCode[$parent]) && !$allergen->ParentID) {
                $allergen->ParentID = $byCode[$parent]->ID;
                $allergen->write();
            }
        }
    }
}
