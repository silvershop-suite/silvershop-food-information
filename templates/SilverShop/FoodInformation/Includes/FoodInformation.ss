<%-- Food information block for a product page: $FoodInformation on a product renders this with the resolved data
     (SilverShop\FoodInformation\Service\ResolvedFoodInformation). Override in your theme for your own layout. --%>
<section class="food-information" aria-labelledby="food-information-title">
    <h2 class="food-information__title" id="food-information-title"><%t SilverShop\FoodInformation.Title "Ingredients and allergens" %></h2>

    <% if not $IsReviewed %>
        <p class="food-information__notice"><%t SilverShop\FoodInformation.NotChecked "We are still checking the allergen information for this product. If you have an allergy, please contact us before ordering." %></p>
    <% end_if %>

    <% if $LegalName %><p class="food-information__name">$LegalName</p><% end_if %>

    <% if $HasIngredients %>
        <div class="food-information__ingredients">
            <h3 class="food-information__subtitle"><%t SilverShop\FoodInformation.Ingredients "Ingredients" %></h3>
            $Ingredients
        </div>
    <% end_if %>

    <div class="food-information__allergens">
        <h3 class="food-information__subtitle"><%t SilverShop\FoodInformation.Allergens "Allergens" %></h3>
        <% if $AllergyAdvice %><p>$AllergyAdvice</p><% end_if %>
        <% if $ContainsLine %><p><strong>$ContainsLine</strong></p><% end_if %>
        <% if $MayContainLine %><p>$MayContainLine</p><% end_if %>
        <% if $NoAllergens %><p><%t SilverShop\FoodInformation.NoAllergens "Contains none of the allergens that must be declared." %></p><% end_if %>
        <% if $FreeFromLine %><p>$FreeFromLine</p><% end_if %>
    </div>

    <% if $Claims %>
        <ul class="food-information__claims">
            <% loop $Claims %><li class="food-information__claim food-information__claim--$Code">$Name</li><% end_loop %>
        </ul>
        <% if $Certification %><p class="food-information__certification">$Certification</p><% end_if %>
    <% end_if %>

    <% if $NutritionRows %>
        <table class="food-information__nutrition">
            <caption><%t SilverShop\FoodInformation.NutritionPer "Nutrition per {basis}" basis=$NutritionBasis %><% if $NutritionServing %> · <%t SilverShop\FoodInformation.Portion "portion: {serving}" serving=$NutritionServing %><% end_if %></caption>
            <tbody>
                <% loop $NutritionRows %>
                    <tr<% if $Sub %> class="food-information__nutrient--sub"<% end_if %>><th scope="row">$Label</th><td>$Value</td></tr>
                <% end_loop %>
            </tbody>
        </table>
    <% else_if $NutritionExemptionText %>
        <p class="food-information__exemption">$NutritionExemptionText</p>
    <% end_if %>

    <% if $Particulars || $ExtraStatements %>
        <dl class="food-information__particulars">
            <% loop $Particulars %><dt>$Label</dt><dd>$Value</dd><% end_loop %>
        </dl>
        <% loop $ExtraStatements %><p class="food-information__statement">$Line</p><% end_loop %>
    <% end_if %>
</section>
