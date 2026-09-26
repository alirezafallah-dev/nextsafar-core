(function ($) {
  "use strict";

  $(document).ready(function () {
    /* ==========================================================================
       Transportation - Show Related Field
       ========================================================================== */
    $("#tour_transport_type").on("change", function () {
      var value = $(this).val();

      $(".ns-transport-field").hide();

      if (value === "هواپیما") {
        $("#tour-airline-field").show();
      } else if (value === "قطار") {
        $("#tour-train-field").show();
      } else if (value === "کشتی") {
        $("#tour-ship-field").show();
      } else if (value === "اتوبوس") {
        $("#tour-bus-field").show();
      }
    });

    /* ==========================================================================
       Price Formatting with Commas
       ========================================================================== */
    $(".ns-price-input").on("blur", function () {
      var value = $(this).val().replace(/,/g, "");

      if (!isNaN(value) && value !== "") {
        $(this).val(Number(value).toLocaleString("en-US"));
      }
    });

    /* ==========================================================================
       Stays - Add
       ========================================================================== */
    $("#add-tour-stay").on("click", function () {
      var hotels = $(this).data("hotels") || [];
      var index = $("#tour-stays-wrapper .ns-tour-stay-item").length;
      var options = '<option value="">-- انتخاب کنید --</option>';

      hotels.forEach(function (hotel) {
        options +=
          '<option value="' + hotel.id + '">' + hotel.title + "</option>";
      });

      var html = `
        <div class="ns-tour-stay-item" data-index="${index}">
            <div class="ns-field" style="flex: 2;">
                <label>هتل:</label>
                <select name="tour_stays[${index}][hotel]" class="ns-input">
                    ${options}
                </select>
            </div>
            <div class="ns-field" style="flex: 1;">
                <label>تعداد شب:</label>
                <input type="number" name="tour_stays[${index}][nights]" 
                       min="1" max="30" class="ns-input">
            </div>
            <button type="button" class="ns-remove-stay">✕</button>
        </div>
      `;

      $("#tour-stays-wrapper").append(html);
    });

    // Remove stay
    $(document).on("click", ".ns-remove-stay", function () {
      $(this)
        .closest(".ns-tour-stay-item")
        .fadeOut(200, function () {
          $(this).remove();
        });
    });

    /* ==========================================================================
       Itinerary - Add Day
       ========================================================================== */
    $("#add-itinerary-day").on("click", function () {
      var dayCount =
        $("#tour-itinerary-wrapper .ns-itinerary-item").length + 1;

      var html = `
        <div class="ns-itinerary-item" data-day="${dayCount}">
            <div class="ns-itinerary-header">
                <strong>📅 روز ${dayCount}</strong>
                <button type="button" class="ns-remove-day">✕</button>
            </div>
            <textarea name="tour_itinerary[${dayCount}]" rows="3" class="ns-textarea"></textarea>
        </div>
      `;

      $("#tour-itinerary-wrapper").append(html);
    });

    // Remove day
    $(document).on("click", ".ns-remove-day", function () {
      var $item = $(this).closest(".ns-itinerary-item");
      var wrapper = $("#tour-itinerary-wrapper");
      var siblings = wrapper.find(".ns-itinerary-item");

      if (siblings.length > 1) {
        $item.fadeOut(200, function () {
          $(this).remove();

          // Rewrite day numbers.
          wrapper.find(".ns-itinerary-item").each(function (index) {
            var dayNum = index + 1;

            $(this).attr("data-day", dayNum);

            $(this)
              .find(".ns-itinerary-header strong")
              .text("📅 روز " + dayNum);

            $(this)
              .find("textarea")
              .attr("name", "tour_itinerary[" + dayNum + "]");
          });
        });
      } else {
        $item.find("textarea").val("");
      }
    });
  });
})(jQuery);