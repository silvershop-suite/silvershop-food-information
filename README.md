# SilverShop Food Information

Food information for [SilverShop](https://github.com/silvershop/silvershop-core) on Silverstripe CMS 6, for any shop
that sells food: a chocolatier, bakery, butcher, grocer, wine shop, meal-prep kitchen or ice-cream parlour, in any
country.

- **Allergens per product:** contains, may contain (traces) or free from, chosen from an allergen catalogue with groups
  and their members (tree nuts > hazelnut, cereals containing gluten > wheat > spelt) and GS1 allergen codes.
- **Rules per country:** a profile decides which allergens are mandatory, which must be named specifically, how the
  allergen statement is shown and whether "may contain" is allowed. Shipped: EU/EEA, UK, Switzerland, US, Canada,
  Australia/New Zealand, Japan and Codex. Profiles are YAML, so a country is added or corrected without code.
- **Ingredients** with the allergens in bold, and a check that every declared allergen is mentioned and in bold.
- **Diet and free-from claims** (gluten-free, lactose-free, vegan, vegetarian, organic, halal, kosher, your own), with
  conflict checks (vegan vs milk) and the certificate for organic, halal and kosher.
- **Label information:** name of the food, packaging type, net quantity, storage, shelf life, instructions for use,
  origin, alcohol, other mandatory statements (e.g. "Cocoa solids: 70% minimum"), the food business name and address.
- **Nutrition** per 100 g or 100 ml (energy, fat, saturates, carbohydrate, sugars, fibre, protein, salt, an optional
  portion), or the reason no nutrition declaration is needed (e.g. small quantities supplied directly by the maker).
- **Mixed products:** variations use their product's information unless they have their own; a box, assortment or gift
  basket combines the allergens of the products it is made of (and a silvershop-suite/product-bundles bundle those of
  its items).
- **Checked or not:** each product is "not food", "food, not checked yet" or "food, checked". Until it is checked the
  product page asks customers with an allergy to contact you, and nothing is ever shown as "free from".
- **Product page block, order-line copy and a report:** `$FoodInformation` renders the block; each order line keeps a
  copy of what the customer was shown (for packing slips, emails and order history); Reports > "Food information to
  check" lists products that need attention.
- Translations: en, nl, de, fr, it, es (allergen names, claims, statements, CMS).

## Installation

```bash
composer require silvershop-suite/food-information
```

Run `dev/build?flush=all` (it seeds the allergen and claim catalogues). Then:

1. **Settings > Shop > Food information:** choose the rules (default: EU) and, if you don't use
   silvershop-suite/store-profile, the food business name and address.
2. **Each food product > Food information tab:** set the status, the allergens, the ingredients (allergens in bold) and
   the other information; set the status to "checked" when it is complete.
3. **Show it on the product page:** add this to your product template where the block should go:

   ```
   <% if $HasFoodInformation %>$FoodInformation<% end_if %>
   ```

   The markup is `templates/SilverShop/FoodInformation/Includes/FoodInformation.ss`; override it in your theme for
   your own layout, or use `$ResolvedFoodInformation` (`$Contains`, `$MayContain`, `$FreeFrom`, `$ContainsLine`,
   `$Ingredients`, `$Claims`, `$NutritionRows`, `$Particulars`, ...) in your own template.
4. **At delivery:** an order line's `$FoodInformationSummary` (or `$FoodInformationLines`) prints its saved copy, e.g.
   on a packing slip or in the confirmation email.

## Other objects

Anything can carry food information, e.g. a flavour, a recipe or a menu item in your project:

```yaml
App\Model\Flavour:
  extensions:
    - SilverShop\FoodInformation\Extension\FoodInformationExtension

# allow them as components of mixed products (a box of flavours)
SilverShop\FoodInformation\Extension\FoodInformationExtension:
  component_classes:
    - App\Model\Flavour
```

## Catalogues and rules

- **Food information** (CMS menu): the allergen and claim catalogues. Seeded entries can be renamed or hidden; add your
  own (a stable `Code` is the key products and imports use).
- **Profiles** live in `_config/food-information.yml` (`SilverShop\FoodInformation\Service\FoodRules.profiles`):
  `mandatory`, `exclude`, `name_specific`, `statement` (`emphasis` / `contains` / `contains_required`) and
  `may_contain` (`voluntary` / `prohibited`).
- Data is stored as JSON on the product itself (`FoodAllergenData`, `FoodClaimData`, `FoodComponentData`), so it
  follows the product's draft and publish cycle; codes, not IDs, keep it portable.

## Legal summary

Researched in November 2026.

- **EU (Regulation 1169/2011):** 14 allergens; the specific cereal and nut must be named; allergens are emphasised in
  the ingredients list (a separate "Contains" box is only for food without an ingredients list). Online, every
  mandatory particular except the best-before date must be available **before the purchase is completed**, and
  everything **at delivery**. Food that is not prepacked (made to order, packed on the premises for direct sale) still
  needs its allergens before purchase and at delivery. Small producers supplying small quantities directly are exempt
  from the nutrition declaration (Annex V item 19). "May contain" is voluntary.
- **UK:** the same 14 allergens and the same online rules; FSA guidance: use "may contain" only after a risk
  assessment and never with a free-from claim for the same allergen.
- **US:** 9 major allergens (incl. sesame); a "Contains:" statement or the allergen in brackets; specific tree nut, fish
  and crustacean species.
- **Australia / New Zealand (PEAL):** allergens in bold in the ingredients **and** a bold "Contains:" summary, which
  includes "gluten" when a gluten cereal is present.
- **Japan:** "may contain" wording is not allowed.

**This module helps you show the information; it is not legal advice.** The shop is responsible for its data and for
checking the rules of the countries it sells to.

## Status

v1. Planned: structured ingredient lines (quantities, automatic bold), the US nutrition panel, GS1 structured data,
marketplace feed attributes, search filters ("free from nuts", "vegan").

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
