jQuery(function ($) {
  /* ========================================================================
     Image Upload
     ======================================================================== */
  $(document).on("click", ".ns-tourism-upload", function (e) {
    e.preventDefault();

    var target = $(this).data("target");
    var $field = $(this).closest(".ns-tourism-image-field");

    var frame = wp.media({
      title: "انتخاب تصویر",
      multiple: false,
    });

    frame.on("select", function () {
      var att = frame.state().get("selection").first().toJSON();

      // Store Attachment ID.
      $("#" + target).val(att.id);

      // Show preview.
      $field.find(".ns-tourism-preview").html(
        '<img src="' +
          att.url +
          '" style="max-width:200px; height:auto; border-radius:4px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">'
      );

      /* After adding the image:
         the "Select Image" button is hidden and the "✕" button is shown. */
      $field.find(".ns-tourism-upload").hide();
      $field.find(".ns-tourism-remove").show();
    });

    frame.open();
  });

  /* ========================================================================
     Remove Image
     ======================================================================== */
  $(document).on("click", ".ns-tourism-remove", function (e) {
    e.preventDefault();

    var target = $(this).data("target");
    var $field = $(this).closest(".ns-tourism-image-field");

    // Clear input value.
    $("#" + target).val("");

    // Restore placeholder.
    $field.find(".ns-tourism-preview").html(
      '<div class="ns-no-image" style="padding:20px; text-align:center; background:#f0f0f1; border-radius:4px; color:#646970;">📷 تصویری انتخاب نشده</div>'
    );

    /* After removing the image:
       the "✕" button is hidden and the "Select Image" button is shown again. */
    $(this).hide();
    $field.find(".ns-tourism-upload").show();
  });
});