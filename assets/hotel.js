(function ($) {
  "use strict";

  $(document).ready(function () {
    // console.log("✅ NextSafar Hotel JS loaded");

    /* ==========================================================================
       Amenities Accordion
       ========================================================================== */
    $(document).on("click", ".ns-amenity-header", function (e) {
      e.preventDefault();

      var target = $(this).data("target");
      var body = $("#" + target);

      // console.log("Clicked accordion:", target);
      // console.log("Body found:", body.length);

      $(this).toggleClass("active");

      if (body.is(":visible")) {
        body.slideUp(200);
      } else {
        body.slideDown(200);
      }
    });

    /* ==========================================================================
       Add New Location
       ========================================================================== */
    var locationIndex =
      parseInt($("#hotel-locations-wrapper").data("index-count")) || 0;

    $("#add-location").on("click", function () {
      var row =
        '<div class="ns-location-row">' +
        '<select name="hotel_near_locations[' +
        locationIndex +
        '][type]">' +
        '<option value="manual">دستی</option>' +
        '<option value="post">از پست</option>' +
        "</select>" +
        '<select name="hotel_near_locations[' +
        locationIndex +
        '][category]">' +
        '<option value="">دسته‌بندی</option>' +
        '<option value="near_locations_htl">مکان‌های نزدیک</option>' +
        '<option value="destination">مقصد گردشگری</option>' +
        '<option value="restaurant">رستوران</option>' +
        '<option value="airport">فرودگاه</option>' +
        '<option value="shopping">مرکز خرید</option>' +
        '<option value="hospital">بیمارستان</option>' +
        "</select>" +
        '<input type="text" name="hotel_near_locations[' +
        locationIndex +
        '][name]" placeholder="نام محل">' +
        '<input type="text" name="hotel_near_locations[' +
        locationIndex +
        '][coords]" placeholder="lat,lng">' +
        '<input type="text" name="hotel_near_locations[' +
        locationIndex +
        '][distance_km]" placeholder="فاصله">' +
        '<select name="hotel_near_locations[' +
        locationIndex +
        '][distance_unit]">' +
        '<option value="km">کیلومتر</option>' +
        '<option value="m">متر</option>' +
        "</select>" +
        '<input type="text" name="hotel_near_locations[' +
        locationIndex +
        '][walking_hr]" placeholder="زمان پیاده">' +
        '<button type="button" class="button remove-location">✕</button>' +
        "</div>";

      $("#hotel-locations-list").append(row);
      locationIndex++;
    });

    // Remove location
    $(document).on("click", ".remove-location", function () {
      $(this)
        .closest(".ns-location-row")
        .fadeOut(200, function () {
          $(this).remove();
        });
    });

    /* ==========================================================================
       Add Custom Section
       ========================================================================== */
    $("#add-custom-section").on("click", function () {
      var row =
        '<div class="ns-custom-section-row">' +
        '<select name="hotel_custom_sections[select][]">' +
        '<option value="option1">ودیعه خسارت</option>' +
        '<option value="option2">خروج</option>' +
        '<option value="option3">لغو/پیش‌پرداخت</option>' +
        '<option value="option4">محدودیت سنی</option>' +
        '<option value="option5">حیوانات خانگی</option>' +
        "</select>" +
        '<textarea name="hotel_custom_sections[textarea][]" rows="2"></textarea>' +
        '<button type="button" class="button remove-custom-section">✕</button>' +
        "</div>";

      $("#custom-sections-list").append(row);
    });

    // Remove custom section
    $(document).on("click", ".remove-custom-section", function () {
      $(this)
        .closest(".ns-custom-section-row")
        .fadeOut(200, function () {
          $(this).remove();
        });
    });

    /* ==========================================================================
       Working Hours Modal
       ========================================================================== */
    $(document).on("click", ".set-working-hours", function () {
      var key = $(this).data("key");

      var label = $(this)
        .closest(".ns-amenity-item")
        .find("label")
        .first()
        .text()
        .trim();

      $("#wh-current-key").val(key);
      $("#wh-item-name").text(label);

      var savedHours = {};

      try {
        savedHours = JSON.parse($(this).data("hours") || "{}");
      } catch (e) {
        savedHours = {};
      }

      $(".wh-start, .wh-end").val("");

      $.each(savedHours, function (day, times) {
        $('.wh-start[data-day="' + day + '"]').val(times.start || "");
        $('.wh-end[data-day="' + day + '"]').val(times.end || "");
      });

      $("#working-hours-modal").fadeIn(200);
    });

    $(".close-modal").on("click", function () {
      $("#working-hours-modal").fadeOut(200);
    });

    $("#save-working-hours").on("click", function () {
      var key = $("#wh-current-key").val();
      var hours = {};

      $(".wh-start").each(function () {
        var day = $(this).data("day");
        var start = $(this).val();
        var end = $('.wh-end[data-day="' + day + '"]').val();

        if (start || end) {
          hours[day] = { start: start, end: end };
        }
      });

      var inputName = "working_hours_" + key;
      var hiddenInput = $('input[name="' + inputName + '"]');

      if (hiddenInput.length === 0) {
        hiddenInput = $('<input type="hidden" name="' + inputName + '">');
        $(".nextsafar-hotel-metabox").append(hiddenInput);
      }

      hiddenInput.val(JSON.stringify(hours));
      $("#working-hours-modal").fadeOut(200);
    });
  });
})(jQuery);