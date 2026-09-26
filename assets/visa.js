(function ($) {
  "use strict";

  $(document).ready(function () {
    // Exchange currency list (read from PHP).
    const CURRENCIES = window.NextSafarCurrencies || {};

    // Build currency select options.
    function buildCurrencyOptions(selected) {
      selected = selected || "";

      let options = '<option value="">-- واحد پول --</option>';

      for (const code in CURRENCIES) {
        const selectedAttr = selected === code ? "selected" : "";

        options += `<option value="${code}" ${selectedAttr}>${CURRENCIES[code]} (${code})</option>`;
      }

      return options;
    }

    /* ========================================================================
       Visa Banner Image
       ======================================================================== */
    let bannerFrame = null;

    $(document).on("click", ".ns-visa-banner-upload", function (e) {
      e.preventDefault();

      const $button = $(this);
      const $input = $("#ns-visa-banner-input");
      const $preview = $("#ns-visa-banner-preview");
      const $removeBtn = $(".ns-visa-banner-remove");

      if (bannerFrame) {
        bannerFrame.open();
        return;
      }

      bannerFrame = wp.media({
        title: "انتخاب عکس بنر ویزا",
        button: { text: "انتخاب بنر" },
        multiple: false,
      });

      bannerFrame.on("select", function () {
        const att = bannerFrame.state().get("selection").first().toJSON();

        $input.val(att.url);

        $preview.html(
          '<img src="' +
            att.url +
            '" style="max-width:100%; height:auto; border-radius:6px;">',
        );

        $removeBtn.show();
      });

      bannerFrame.open();
    });

    $(document).on("click", ".ns-visa-banner-remove", function (e) {
      e.preventDefault();

      $("#ns-visa-banner-input").val("");

      $("#ns-visa-banner-preview").html(
        '<div class="ns-visa-banner-placeholder">🏞️ بنری انتخاب نشده</div>',
      );

      $(this).hide();
    });

    /* ========================================================================
       Visa Prices
       ======================================================================== */
    $("#add-visa-price").on("click", function (e) {
      e.preventDefault();

      const wrapper = $("#visa-prices-wrapper");
      const index = wrapper.children(".ns-visa-price-group").length;

      const html = `
        <div class="ns-visa-price-group">
            <input type="text" name="visa_prices[${index}][type]" placeholder="نوع (سینگل/مولتی)">
            <input type="text" name="visa_prices[${index}][duration]" placeholder="مدت (مثلاً ۱۰ روز)">
            <input type="text" name="visa_prices[${index}][person]" placeholder="شخص (بزرگسال/کودک)">
            <input type="text" name="visa_prices[${index}][price]" placeholder="قیمت">
            <select name="visa_prices[${index}][currency]">
                ${buildCurrencyOptions()}
            </select>
            <button type="button" class="ns-remove-visa-price">✕</button>
        </div>
      `;

      wrapper.append(html);
    });

    // Remove price.
    $(document).on("click", ".ns-remove-visa-price", function (e) {
      e.preventDefault();

      $(this)
        .closest(".ns-visa-price-group")
        .fadeOut(200, function () {
          $(this).remove();
        });
    });

    /* ========================================================================
       Visa Category Image Upload (Banner and Image)
       ======================================================================== */
    let visaMediaFrame = null;

    $(document).on("click", ".ns-visa-upload", function (e) {
      e.preventDefault();

      const $button = $(this);
      const target = $button.data("target");
      const $wrap = $button.closest(".ns-visa-image-field");
      const $input = $wrap.find(`input[name="${target}"]`);
      const $preview = $wrap.find(
        `.ns-visa-image-preview[data-target="${target}"]`,
      );
      const $removeBtn = $wrap.find(`.ns-visa-remove[data-target="${target}"]`);

      if (visaMediaFrame) {
        visaMediaFrame.open();
        return;
      }

      visaMediaFrame = wp.media({
        title: "انتخاب تصویر",
        button: { text: "انتخاب تصویر" },
        multiple: false,
      });

      visaMediaFrame.on("select", function () {
        const att = visaMediaFrame.state().get("selection").first().toJSON();

        $input.val(att.id);

        $preview.html(
          '<img src="' + att.url + '" style="max-width:100px; height:auto;">',
        );

        $removeBtn.show();
      });

      visaMediaFrame.open();
    });

    $(document).on("click", ".ns-visa-remove", function (e) {
      e.preventDefault();

      const target = $(this).data("target");
      const $wrap = $(this).closest(".ns-visa-image-field");
      const $input = $wrap.find(`input[name="${target}"]`);
      const $preview = $wrap.find(
        `.ns-visa-image-preview[data-target="${target}"]`,
      );

      $input.val("");
      $preview.empty();
      $(this).hide();
    });
  });
})(jQuery);