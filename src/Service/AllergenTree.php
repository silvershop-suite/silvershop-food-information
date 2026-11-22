<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Service;

use SilverShop\FoodInformation\Model\Allergen;
use SilverStripe\Core\Injector\Injectable;

/**
 * The allergen catalogue as a tree of codes (hazelnut → tree_nuts), cached per request. Seeded entries come from the
 * YAML catalogue, the CMS can add or move entries.
 */
class AllergenTree
{
    use Injectable;

    /** @var array<string, string>|null code => parent code ('' = top level) */
    private ?array $parents = null;

    public function flush(): void
    {
        $this->parents = null;
    }

    /**
     * @return string[]
     */
    public function selfAndDescendants(string $code): array
    {
        $codes = [$code];
        foreach ($this->parents() as $child => $parent) {
            if ($parent === $code && $child !== $code) {
                $codes = array_merge($codes, $this->selfAndDescendants($child));
            }
        }

        return $codes;
    }

    /**
     * @return string[]
     */
    public function selfAndAncestors(string $code): array
    {
        $codes = [$code];
        $parents = $this->parents();
        $guard = 0;
        while (($parents[$code] ?? '') !== '' && $guard++ < 10) {
            $code = $parents[$code];
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    public function parents(): array
    {
        if ($this->parents === null) {
            $map = [];
            foreach ((array) Allergen::config()->get('catalogue') as $code => $seed) {
                $map[(string) $code] = (string) ($seed[0] ?? '');
            }
            $codesById = [];
            $records = Allergen::get()->toArray();
            foreach ($records as $allergen) {
                $codesById[(int) $allergen->ID] = (string) $allergen->Code;
            }
            foreach ($records as $allergen) {
                $code = (string) $allergen->Code;
                $map[$code] = $allergen->ParentID ? ($codesById[(int) $allergen->ParentID] ?? '') : ($map[$code] ?? '');
            }
            $this->parents = $map;
        }

        return $this->parents;
    }
}
