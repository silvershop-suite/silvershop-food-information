<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Service;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The food-information rules of one jurisdiction (a "profile" in YAML, chosen in Settings > Shop > Food information):
 * which allergens are mandatory, which groups must be named by their specific member, how the allergen statement is
 * shown and whether "may contain" is allowed. See _config/food-information.yml for the shipped profiles.
 */
class FoodRules
{
    use Configurable;
    use Injectable;

    public const STATEMENT_EMPHASIS = 'emphasis';
    public const STATEMENT_CONTAINS = 'contains';
    public const STATEMENT_CONTAINS_REQUIRED = 'contains_required';

    private static string $default_profile = 'eu';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $profiles = [];

    /**
     * @param array<string, mixed> $profile
     */
    public function __construct(private readonly string $key, private readonly array $profile)
    {
    }

    public static function current(): FoodRules
    {
        $key = '';
        $config = SiteConfig::current_site_config();
        if ($config->hasField('FoodRulesProfile')) {
            $key = (string) $config->getField('FoodRulesProfile');
        }

        return static::forProfile($key);
    }

    public static function forProfile(string $key): FoodRules
    {
        $profiles = (array) static::config()->get('profiles');
        if ($key === '' || !isset($profiles[$key])) {
            $key = (string) static::config()->get('default_profile');
        }

        return FoodRules::create($key, (array) ($profiles[$key] ?? []));
    }

    /**
     * @return array<string, string> profile key => title, for the settings dropdown
     */
    public static function profileOptions(): array
    {
        $options = [];
        foreach ((array) static::config()->get('profiles') as $key => $profile) {
            $options[(string) $key] = _t(self::class . '.Profile_' . $key, (string) ($profile['title'] ?? $key));
        }

        return $options;
    }

    public static function flushParents(): void
    {
        AllergenTree::singleton()->flush();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTitle(): string
    {
        return self::profileOptions()[$this->key] ?? $this->key;
    }

    public function statementMode(): string
    {
        return (string) ($this->profile['statement'] ?? self::STATEMENT_EMPHASIS);
    }

    public function mayContainAllowed(): bool
    {
        return ($this->profile['may_contain'] ?? 'voluntary') !== 'prohibited';
    }

    /**
     * Mandatory allergen codes, groups expanded to everything below them, minus the profile's exclusions.
     *
     * @return string[]
     */
    public function mandatoryCodes(): array
    {
        $exclude = array_map('strval', (array) ($this->profile['exclude'] ?? []));
        $codes = [];
        foreach ((array) ($this->profile['mandatory'] ?? []) as $code) {
            foreach (self::selfAndDescendants((string) $code) as $member) {
                if (!in_array($member, $exclude, true)) {
                    $codes[$member] = $member;
                }
            }
        }

        return array_values($codes);
    }

    public function isMandatory(string $code): bool
    {
        return in_array($code, $this->mandatoryCodes(), true);
    }

    /**
     * Groups that must be declared by their specific member (e.g. tree nuts: say "hazelnut").
     *
     * @return string[]
     */
    public function nameSpecificGroups(): array
    {
        return array_map('strval', (array) ($this->profile['name_specific'] ?? []));
    }

    /**
     * @return string[]
     */
    public static function selfAndDescendants(string $code): array
    {
        return AllergenTree::singleton()->selfAndDescendants($code);
    }

    /**
     * The code and the codes above it (hazelnut, tree_nuts).
     *
     * @return string[]
     */
    public static function selfAndAncestors(string $code): array
    {
        return AllergenTree::singleton()->selfAndAncestors($code);
    }
}
