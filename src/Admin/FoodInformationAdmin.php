<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Admin;

use SilverShop\FoodInformation\Model\Allergen;
use SilverShop\FoodInformation\Model\FoodClaim;
use SilverStripe\Admin\ModelAdmin;

/**
 * The allergen and claim catalogues (seeded on dev/build; add local entries here).
 */
class FoodInformationAdmin extends ModelAdmin
{
    private static string $url_segment = 'food-information';

    private static string $menu_title = 'Food information';

    private static string $menu_icon_class = 'font-icon-p-list';

    private static array $managed_models = [
        Allergen::class,
        FoodClaim::class,
    ];
}
