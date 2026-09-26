(function ($) {
  "use strict";

  $(document).ready(function () {
    /* ========================================================================
       Working Hours Logic
       ======================================================================== */

    // When "Closed" is checked, disable the time fields.
    $(".worktime-checkbox-off").on("change", function () {
      var $day = $(this).closest(".ns-wh-day");

      if ($(this).is(":checked")) {
        $day.find('input[type="time"]').val("").prop("disabled", true);
        $day.find(".worktime-checkbox-24h").prop("checked", false);
      } else {
        $day.find('input[type="time"]').prop("disabled", false);
      }
    });

    // When "24 hours" is checked, clear and disable the time fields.
    $(".worktime-checkbox-24h").on("change", function () {
      var $day = $(this).closest(".ns-wh-day");

      if ($(this).is(":checked")) {
        $day.find('input[type="time"]').val("").prop("disabled", true);
        $day.find(".worktime-checkbox-off").prop("checked", false);
      } else {
        $day.find('input[type="time"]').prop("disabled", false);
      }
    });

    // Apply the initial state when the page loads.
    $(".worktime-checkbox-off, .worktime-checkbox-24h").each(function () {
      if ($(this).is(":checked")) {
        $(this).trigger("change");
      }
    });

    // When the user changes the time fields, uncheck the status checkboxes.
    $('input[type="time"]').on("change", function () {
      var $day = $(this).closest(".ns-wh-day");

      if ($(this).val()) {
        $day
          .find(".worktime-checkbox-off, .worktime-checkbox-24h")
          .prop("checked", false);
      }
    });
  });
})(jQuery);