(function ($) {
  "use strict";

  $(document).ready(function () {
    /* ==========================================================================
       Amenities Accordion (With Conflict Prevention)
       ========================================================================== */
    $(document)
      .off("click.nsRestAccordion", ".ns-amenity-header")
      .on("click.nsRestAccordion", ".ns-amenity-header", function (e) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var $header = $(this);
        var target = $header.data("target");
        var $body = $("#" + target);

        if (!$body.length) return;

        var isOpen = $header.attr("data-open") === "true";

        $body.stop(true, true);

        if (isOpen) {
          $body.slideUp(200, function () {
            $header.removeClass("active");
            $header.attr("data-open", "false");
          });
        } else {
          $header.addClass("active");
          $body.slideDown(200, function () {
            $header.attr("data-open", "true");
          });
        }

        console.log("Restaurant accordion:", target, "open:", !isOpen);
      });

    /* ==========================================================================
       Working Hours
       ========================================================================== */
    $(document)
      .off("change.nsRestHours", ".worktime-checkbox-off")
      .on("change.nsRestHours", ".worktime-checkbox-off", function () {
        var $day = $(this).closest(".ns-wh-day");

        if ($(this).is(":checked")) {
          $day.find('input[type="time"]').val("").prop("disabled", true);
          $day.find(".worktime-checkbox-24h").prop("checked", false);
        } else {
          $day.find('input[type="time"]').prop("disabled", false);
        }
      });

    $(document)
      .off("change.nsRestHours24", ".worktime-checkbox-24h")
      .on("change.nsRestHours24", ".worktime-checkbox-24h", function () {
        var $day = $(this).closest(".ns-wh-day");

        if ($(this).is(":checked")) {
          $day.find('input[type="time"]').val("").prop("disabled", true);
          $day.find(".worktime-checkbox-off").prop("checked", false);
        } else {
          $day.find('input[type="time"]').prop("disabled", false);
        }
      });

    /* ==========================================================================
       Time Field Changes
       ========================================================================== */
    $(document)
      .off("change.nsRestTime", '.ns-wh-time input[type="time"]')
      .on("change.nsRestTime", '.ns-wh-time input[type="time"]', function () {
        var $day = $(this).closest(".ns-wh-day");

        if ($(this).val()) {
          $day
            .find(".worktime-checkbox-off, .worktime-checkbox-24h")
            .prop("checked", false);
        }
      });

    /* ==========================================================================
       Apply Initial State
       ========================================================================== */
    $(".worktime-checkbox-off, .worktime-checkbox-24h").each(function () {
      if ($(this).is(":checked")) {
        $(this).trigger("change");
      }
    });

    /* ==========================================================================
       Add Repeatable Item
       ========================================================================== */
    $(document)
      .off("click.nsRestAddItem", ".ns-btn-add.add-item")
      .on("click.nsRestAddItem", ".ns-btn-add.add-item", function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $wrapper = $(this).closest(".ns-repeatable-wrapper");
        var fieldName = $wrapper.data("name");
        var $list = $wrapper.find(".ns-repeatable-list");
        var $firstItem = $list.find(".ns-repeatable-item").first();

        if ($firstItem.length) {
          var $newItem = $firstItem.clone();
          $newItem.find("input").val("");
          $list.append($newItem);
        } else {
          var html =
            '<div class="ns-repeatable-item">' +
            '<input type="text" name="' +
            fieldName +
            '[]" placeholder="مورد سفارشی..." />' +
            '<button type="button" class="remove-item">✕</button>' +
            "</div>";
          $list.append(html);
        }
      });

    /* ==========================================================================
       Remove Repeatable Item
       ========================================================================== */
    $(document)
      .off("click.nsRestRemoveItem", ".ns-repeatable-item .remove-item")
      .on(
        "click.nsRestRemoveItem",
        ".ns-repeatable-item .remove-item",
        function (e) {
          e.preventDefault();
          e.stopPropagation();

          var $item = $(this).closest(".ns-repeatable-item");
          var $list = $item.closest(".ns-repeatable-list");
          var $siblings = $list.find(".ns-repeatable-item");

          if ($siblings.length > 1) {
            $item.fadeOut(200, function () {
              $(this).remove();
            });
          } else {
            $item.find("input").val("");
          }
        },
      );

    /* ==========================================================================
       Initialize Accordions
       ========================================================================== */
    $(".ns-amenity-header").each(function () {
      var $header = $(this);
      var target = $header.data("target");
      var $body = $("#" + target);

      if ($body.length) {
        var display = $body.css("display");
        var isOpen = display !== "none" && display !== "";
        $header.attr("data-open", isOpen ? "true" : "false");
      }
    });
  });
})(jQuery);