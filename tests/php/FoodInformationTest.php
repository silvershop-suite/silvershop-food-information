<?php

declare(strict_types=1);

namespace SilverShop\FoodInformation\Tests;

use SilverShop\FoodInformation\Extension\FoodInformationExtension;
use SilverShop\FoodInformation\Forms\AllergenMatrixField;
use SilverShop\FoodInformation\Model\Allergen;
use SilverShop\FoodInformation\Model\FoodClaim;
use SilverShop\FoodInformation\Service\FoodInformationResolver;
use SilverShop\FoodInformation\Service\FoodRules;
use SilverShop\FoodInformation\Service\ResolvedFoodInformation;
use SilverShop\Model\Order;
use SilverShop\Model\Product\OrderItem;
use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\Model\ArrayData;

class FoodInformationTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('en_US');
        Allergen::singleton()->requireDefaultRecords();
        FoodClaim::singleton()->requireDefaultRecords();
        FoodRules::flushParents();
    }

    /**
     * @param array<string, string|array{level: string, note?: string}> $allergens
     * @param array<string, mixed> $fields
     */
    private function product(array $allergens = [], array $fields = []): Product
    {
        $product = Product::create(array_merge([
            'Title' => 'Pralines',
            'BasePrice' => 12.5,
            'FoodInfoStatus' => FoodInformationExtension::STATUS_REVIEWED,
        ], $fields));
        $product->setFoodAllergens($allergens);
        $product->write();

        return $product;
    }

    private function resolve(Product $product, string $profile = 'eu'): ResolvedFoodInformation
    {
        return FoodInformationResolver::singleton()->resolve($product, FoodRules::forProfile($profile));
    }

    /**
     * @return string[]
     */
    private function names(iterable $list): array
    {
        $out = [];
        foreach ($list as $row) {
            /** @var ArrayData $row */
            $out[] = (string) $row->Name;
        }

        return $out;
    }

    public function testCatalogueIsSeededWithGroups(): void
    {
        $this->assertSame('tree_nuts', Allergen::byCode('hazelnut')?->Parent()->Code);
        $this->assertSame('wheat', Allergen::byCode('spelt')?->Parent()->Code);
        $this->assertContains('milk', FoodClaim::byCode('vegan')?->conflictCodes() ?? []);

        Allergen::singleton()->requireDefaultRecords();
        $this->assertSame(1, Allergen::get()->filter('Code', 'milk')->count(), 'seeding twice adds nothing');
    }

    public function testProfilesExpandGroupsAndKeepTheirOwnLists(): void
    {
        $eu = FoodRules::forProfile('eu')->mandatoryCodes();
        $this->assertContains('hazelnut', $eu);
        $this->assertContains('spelt', $eu);
        $this->assertContains('celery', $eu);
        $this->assertNotContains('pine_nut', $eu, 'pine nut is not on the EU list');

        $us = FoodRules::forProfile('us')->mandatoryCodes();
        $this->assertContains('pine_nut', $us);
        $this->assertNotContains('celery', $us);
        $this->assertSame('eu', FoodRules::forProfile('nonsense')->getKey(), 'unknown profile falls back to the default');
    }

    public function testContainsNamesTheSpecificMember(): void
    {
        $info = $this->resolve($this->product(['tree_nuts' => 'contains', 'hazelnut' => 'contains', 'milk' => 'contains']));

        $this->assertSame(['Milk', 'Hazelnut'], $this->names($info->Contains()));
    }

    public function testEuUsesBoldIngredientsAndContainsOnlyWithoutIngredients(): void
    {
        $withIngredients = $this->resolve($this->product(['milk' => 'contains'], [
            'Ingredients' => '<p>Sugar, cocoa butter, whole <strong>milk</strong> powder.</p>',
        ]));
        $this->assertSame('', $withIngredients->ContainsLine());
        $this->assertNotSame('', $withIngredients->AllergyAdvice());
        $this->assertSame([], $withIngredients->warnings());

        $without = $this->resolve($this->product(['milk' => 'contains']));
        $this->assertSame('Contains: Milk', $without->ContainsLine());
    }

    public function testAustraliaNewZealandAddsGlutenAndUsAlwaysSaysContains(): void
    {
        $product = $this->product(['wheat' => 'contains'], ['Ingredients' => '<p><strong>Wheat</strong> flour</p>']);

        $this->assertSame('Contains: Wheat, gluten', $this->resolve($product, 'au_nz')->ContainsLine());
        $this->assertSame('Contains: Wheat', $this->resolve($product, 'us')->ContainsLine());
    }

    public function testJapanDropsMayContain(): void
    {
        $product = $this->product(['peanuts' => 'may_contain']);

        $this->assertSame(['Peanuts'], $this->names($this->resolve($product)->MayContain()));
        $japan = $this->resolve($product, 'jp');
        $this->assertSame([], $this->names($japan->MayContain()));
        $this->assertNotEmpty(array_filter($japan->warnings(), fn ($w) => str_contains($w, 'not allowed')));
    }

    public function testFreeFromOnlyOnceCheckedAndNeverForWhatItMayContain(): void
    {
        $unchecked = $this->product(['peanuts' => 'free_from'], ['FoodInfoStatus' => FoodInformationExtension::STATUS_INCOMPLETE]);
        $this->assertSame([], $this->names($this->resolve($unchecked)->FreeFrom()));

        $checked = $this->product(['peanuts' => 'free_from', 'tree_nuts' => 'free_from', 'hazelnut' => 'may_contain']);
        $this->assertSame(['Peanuts'], $this->names($this->resolve($checked)->FreeFrom()), 'tree nuts: it may contain hazelnut');
    }

    public function testComponentsAddTheirAllergensAndNeedChecking(): void
    {
        $flavour = $this->product(['milk' => 'contains', 'peanuts' => 'may_contain', 'eggs' => 'free_from'], [
            'Title' => 'Milk praline',
            'FoodInfoStatus' => FoodInformationExtension::STATUS_INCOMPLETE,
        ]);
        $box = $this->product(['milk' => 'free_from', 'eggs' => 'free_from'], [
            'Title' => 'Assortment box',
            'FoodComponentData' => json_encode([Product::class . '#' . $flavour->ID]),
        ]);

        $info = $this->resolve($box);
        $this->assertSame(['Milk'], $this->names($info->Contains()), 'a component that contains milk beats the box saying free from');
        $this->assertSame(['Peanuts'], $this->names($info->MayContain()));
        $this->assertSame(FoodInformationExtension::STATUS_INCOMPLETE, $info->getStatus(), 'a component is not checked yet');

        $flavour->FoodInfoStatus = FoodInformationExtension::STATUS_REVIEWED;
        $flavour->write();
        $info = $this->resolve(Product::get()->byID($box->ID));
        $this->assertTrue($info->IsReviewed());
        $this->assertSame(['Eggs'], $this->names($info->FreeFrom()));
    }

    public function testVariationUsesTheProductUnlessItHasItsOwn(): void
    {
        $product = $this->product(['milk' => 'contains']);
        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 15]);
        $variation->setFoodAllergens(['soy' => 'contains']);
        $variation->write();

        $resolver = FoodInformationResolver::singleton();
        $this->assertSame(['Milk'], $this->names($resolver->resolve($variation, FoodRules::forProfile('eu'))->Contains()));

        $variation->OwnFoodInformation = true;
        $variation->FoodInfoStatus = FoodInformationExtension::STATUS_REVIEWED;
        $variation->write();
        $this->assertSame(['Soy'], $this->names($resolver->resolve($variation, FoodRules::forProfile('eu'))->Contains()));
    }

    public function testWarningsForTheShopToCheck(): void
    {
        $product = $this->product(['tree_nuts' => 'contains', 'milk' => 'contains'], [
            'FoodClaimData' => json_encode(['vegan', 'organic']),
            'Ingredients' => '<p>Sugar, nuts, milk powder.</p>',
            'PackagingType' => 'Prepacked',
        ]);
        $warnings = implode("\n", $this->resolve($product)->warnings());

        $this->assertStringContainsString('"Tree nuts" must be declared by name', $warnings);
        $this->assertStringContainsString('"Vegan" does not fit with "Milk"', $warnings);
        $this->assertStringContainsString('"Organic" needs the certifier', $warnings);
        $this->assertStringContainsString('"Milk" is in the ingredients but not in bold', $warnings);
        $this->assertStringContainsString('net quantity', $warnings);
    }

    public function testNotFoodShowsNothing(): void
    {
        $product = $this->product([], ['FoodInfoStatus' => FoodInformationExtension::STATUS_NOT_FOOD]);

        $this->assertFalse($product->HasFoodInformation());
        $this->assertNull($product->FoodInformation());
        $this->assertSame([], $this->resolve($product)->warnings());
    }

    public function testProductPageBlock(): void
    {
        $product = $this->product(['milk' => 'contains', 'peanuts' => 'may_contain'], [
            'Ingredients' => '<p>Sugar, whole <strong>milk</strong> powder.</p>',
            'FoodClaimData' => json_encode(['vegetarian']),
            'HasNutrition' => true,
            'NutritionEnergyKJ' => 2250,
            'NutritionEnergyKcal' => 540,
            'NutritionFat' => 32.5,
            'StorageInstructions' => 'Store cool and dry.',
        ]);
        $html = (string) $product->FoodInformation();

        $this->assertStringContainsString('<strong>milk</strong>', $html);
        $this->assertStringContainsString('May contain: Peanuts', $html);
        $this->assertStringContainsString('Vegetarian', $html);
        $this->assertStringContainsString('2,250 kJ / 540 kcal', $html);
        $this->assertStringContainsString('Store cool and dry.', $html);
        $this->assertStringNotContainsString('still checking', $html);

        $product->FoodInfoStatus = FoodInformationExtension::STATUS_INCOMPLETE;
        $product->write();
        $html = (string) Product::get()->byID($product->ID)->FoodInformation();
        $this->assertStringContainsString('still checking', $html);
        $this->assertStringNotContainsString('Vegetarian', $html, 'claims wait until the information is checked');
    }

    public function testOrderLineKeepsACopy(): void
    {
        $product = $this->product(['milk' => 'contains', 'hazelnut' => 'may_contain'], [
            'StorageInstructions' => 'Store below 18 °C.',
        ]);
        $product->publishSingle();
        $order = Order::create();
        $order->write();
        $item = OrderItem::create([
            'OrderID' => $order->ID,
            'ProductID' => $product->ID,
            'ProductVersion' => $product->Version,
            'Quantity' => 1,
        ]);
        $item->write();

        $item->onPlacement();
        $item->write();

        $snapshot = OrderItem::get()->byID($item->ID)->foodInformationSnapshot();
        $this->assertSame(['Milk'], $snapshot['contains']);
        $this->assertSame(['Hazelnut'], $snapshot['may_contain']);
        $lines = array_map(fn ($row) => $row->Line, OrderItem::get()->byID($item->ID)->FoodInformationLines()->toArray());
        $this->assertSame(['Contains: Milk', 'May contain: Hazelnut', 'Store below 18 °C.'], $lines);
    }

    public function testMatrixFieldStoresOnlyChosenLevels(): void
    {
        $field = AllergenMatrixField::create('FoodAllergenData');
        $field->setSubmittedValue([
            'milk' => ['level' => 'contains', 'note' => ''],
            'eggs' => ['level' => '', 'note' => 'ignored'],
            'peanuts' => ['level' => 'may_contain', 'note' => 'shared line'],
        ]);

        $this->assertSame('{"milk":{"level":"contains"},"peanuts":{"level":"may_contain","note":"shared line"}}', $field->dataValue());
        $codes = array_map(fn ($row) => $row->Code, $field->Rows()->toArray());
        $this->assertContains('hazelnut', $codes);
        $this->assertLessThan(array_search('hazelnut', $codes, true), array_search('tree_nuts', $codes, true), 'members follow their group');
    }

    public function testTranslatedNames(): void
    {
        $product = $this->product(['milk' => 'contains', 'hazelnut' => 'may_contain']);
        i18n::set_locale('nl_NL');
        try {
            $info = $this->resolve($product);
            $this->assertSame('Bevat: Melk', $info->ContainsLine());
            $this->assertSame('Kan bevatten: Hazelnoot', $info->MayContainLine());
        } finally {
            i18n::set_locale('en_US');
        }
    }
}
