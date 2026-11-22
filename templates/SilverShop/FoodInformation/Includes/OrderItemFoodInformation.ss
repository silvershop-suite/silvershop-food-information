<%-- Food information copied onto an order line when the order was placed (packing slip, emails, order history). --%>
<% if $FoodInformationLines %>
    <span class="order-item-food-information">
        <% loop $FoodInformationLines %>$Line<% if not $IsLast %><br><% end_if %><% end_loop %>
    </span>
<% end_if %>
