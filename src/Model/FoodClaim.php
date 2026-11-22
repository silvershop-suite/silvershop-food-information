<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Model;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * A diet or free-from claim a product can carry (gluten-free, vegan, organic, halal, ...), keyed by a stable `Code`.
 *
 * Seeded from {@see self::$catalogue}; shops can add their own. `ConflictsWith` lists allergen codes a product must not
 * contain when it carries the claim (e.g. vegan vs milk); a conflict is shown as a warning in the CMS and the report.
 *
 * @property string $Code
 * @property string $CustomName
 * @property string $ConflictsWith
 * @property bool $NeedsCertificate
 * @property int $Sort
 * @property bool $Hidden
 * @property bool $IsSeeded
 */
class FoodClaim extends DataObject
{
    private static string $table_name = 'SilverShop_FoodClaim';

    private static string $singular_name = 'Diet / free-from claim';

    private static string $plural_name = 'Diet / free-from claims';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'CustomName' => 'Varchar(255)',
        // Comma-separated allergen codes (a group code covers everything below it).
        'ConflictsWith' => 'Varchar(255)',
        'NeedsCertificate' => 'Boolean',
        'Sort' => 'Int',
        'Hidden' => 'Boolean',
        'IsSeeded' => 'Boolean',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static string $default_sort = '"Sort" ASC, "ID" ASC';

    private static array $field_labels = [
        'CustomName' => 'Name',
        'ConflictsWith' => 'Conflicts with allergens',
        'NeedsCertificate' => 'Needs a certificate',
    ];

    private static array $summary_fields = [
        'Name' => 'Name',
        'Code' => 'Code',
        'ConflictsWith' => 'Conflicts with',
        'NeedsCertificate.Nice' => 'Certificate',
        'Hidden.Nice' => 'Hidden',
    ];

    /**
     * code => [English name, conflicting allergen codes (comma-separated), needs a certificate]
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    private static array $catalogue = [];

    public function getName(): string
    {
        $title = trim((string) $this->getField('CustomName'));
        if ($title !== '') {
            return $title;
        }
        $seed = static::config()->get('catalogue')[$this->Code] ?? null;

        return _t(self::class . '.Title_' . $this->Code, is_array($seed) ? (string) ($seed[0] ?? $this->Code) : $this->Code);
    }

    public function getTitle(): string
    {
        return $this->getName();
    }

    /**
     * @return string[]
     */
    public function conflictCodes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->ConflictsWith))));
    }

    public static function byCode(string $code): ?FoodClaim
    {
        $claim = FoodClaim::get()->filter('Code', $code)->first();

        return $claim instanceof FoodClaim ? $claim : null;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('CustomName')?->setDescription(_t(
            Allergen::class . '.TitleHelp',
            'Leave empty to use the translated default name ({name}).',
            ['name' => $this->getName()]
        ));
        $fields->dataFieldByName('ConflictsWith')?->setDescription(_t(
            self::class . '.ConflictsHelp',
            'Allergen codes a product with this claim must not contain, comma-separated (e.g. milk, eggs).'
        ));
        if ($this->IsSeeded) {
            $fields->dataFieldByName('Code')?->setReadonly(true);
        }
        $fields->removeByName(['IsSeeded', 'Sort']);

        return $fields;
    }

    public function canDelete($member = null): bool
    {
        return !$this->IsSeeded && parent::canDelete($member);
    }

    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        $sort = 0;
        foreach ((array) static::config()->get('catalogue') as $code => $seed) {
            $sort += 10;
            if (FoodClaim::byCode((string) $code) !== null) {
                continue;
            }
            FoodClaim::create([
                'Code' => (string) $code,
                'ConflictsWith' => (string) ($seed[1] ?? ''),
                'NeedsCertificate' => (bool) ($seed[2] ?? false),
                'Sort' => $sort,
                'IsSeeded' => true,
            ])->write();
        }
    }
}
