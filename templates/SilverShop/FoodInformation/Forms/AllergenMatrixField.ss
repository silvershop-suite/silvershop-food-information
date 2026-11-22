<div class="food-allergen-matrix" id="$ID">
    <p class="form__field-description">
        <%t SilverShop\FoodInformation\Forms\AllergenMatrixField.Intro "Rules: {profile}. Allergens marked * must be declared under these rules. Leave a row on \"Not specified\" when it doesn't apply." profile=$Rules.Title %>
    </p>
    <table class="table table-sm food-allergen-matrix__table">
        <thead>
            <tr>
                <th scope="col"><%t SilverShop\FoodInformation\Forms\AllergenMatrixField.Allergen "Allergen" %></th>
                <% loop $Levels %><th scope="col">$Title</th><% end_loop %>
                <th scope="col"><%t SilverShop\FoodInformation\Forms\AllergenMatrixField.Note "Note" %></th>
            </tr>
        </thead>
        <tbody>
            <% loop $Rows %>
                <tr class="food-allergen-matrix__row food-allergen-matrix__row--depth-$Depth<% if $Mandatory %> food-allergen-matrix__row--mandatory<% end_if %>">
                    <th scope="row" style="padding-left: {$Indent}em; font-weight: <% if $Depth %>normal<% else %>600<% end_if %>;">$Name<% if $Mandatory %> *<% end_if %></th>
                    <% loop $Levels %>
                        <td><input type="radio" id="$ID" name="{$Up.InputName}[level]" value="$Value"<% if $Checked %> checked<% end_if %> aria-label="$Up.Name: $Title"></td>
                    <% end_loop %>
                    <td><input type="text" class="text form-control form-control-sm" name="{$InputName}[note]" value="$Note" aria-label="$Name: <%t SilverShop\FoodInformation\Forms\AllergenMatrixField.Note "Note" %>"></td>
                </tr>
            <% end_loop %>
        </tbody>
    </table>
</div>
