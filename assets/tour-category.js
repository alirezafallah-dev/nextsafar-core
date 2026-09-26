/**
 * Custom Range Picker Calendar - Version 4.0 (Fixed)
 * Definitively fixes range highlight and auto-close issues.
 */
(function ($) {
  "use strict";

  class PersianRangeCalendar {
    constructor(container) {
      this.$container = $(container);

      this.$depInput = this.$container
        .find(".ns-date-input-group")
        .eq(0)
        .find(".ns-persian-date");

      this.$retInput = this.$container
        .find(".ns-date-input-group")
        .eq(1)
        .find(".ns-persian-date");

      this.$depHidden = this.$container
        .find('[name="departure_date_en"]')
        .first();

      this.$retHidden = this.$container
        .find('[name="return_date_en"]')
        .first();

      this.$duration = $(
        'input[name="duration"], select[name="duration"]',
      ).first();

      this.$nightsDisplay = $("#ns-nights-display");
      this.$rangeStatus = $("#ns-range-status");

      this.state = {
        isOpen: false,
        justOpened: false,
        departure: null,
        departureUnix: null,
        return: null,
        returnUnix: null,
        selectingReturn: false,
        viewYear: 0,
        viewMonth: 0,
      };

      this.MONTH_NAMES = [
        "فروردین",
        "اردیبهشت",
        "خرداد",
        "تیر",
        "مرداد",
        "شهریور",
        "مهر",
        "آبان",
        "آذر",
        "دی",
        "بهمن",
        "اسفند",
      ];

      this.WEEKDAYS = ["ش", "ی", "د", "س", "چ", "پ", "ج"];

      this.init();
    }

    init() {
      if (typeof persianDate === "undefined") {
        console.error("persianDate library not loaded");
        return;
      }

      this.buildCalendarDOM();
      this.bindEvents();
      this.loadExistingValues();
    }

    buildCalendarDOM() {
      this.$dropdown = $(`
        <div class="ns-calendar-dropdown">
            <div class="ns-calendar-header">
                <div class="ns-calendar-status">
                    <span class="ns-status-step" id="ns-step-indicator">مرحله ۱: تاریخ رفت را انتخاب کنید</span>
                </div>
                <button type="button" class="ns-calendar-close-btn" title="بستن">✕</button>
            </div>
            <div class="ns-calendar-nav-bar">
                <button type="button" class="ns-nav-btn ns-nav-prev" title="ماه قبل">‹</button>
                <div class="ns-nav-title" id="ns-nav-title">—</div>
                <button type="button" class="ns-nav-btn ns-nav-next" title="ماه بعد">›</button>
            </div>
            <div class="ns-calendar-months">
                <div class="ns-calendar-month" data-month-slot="1"></div>
                <div class="ns-calendar-month" data-month-slot="2"></div>
            </div>
            <div class="ns-calendar-footer">
                <div class="ns-calendar-summary">
                    <div class="ns-summary-item ns-sum-dep">
                        <span class="ns-summary-label">رفت:</span>
                        <span class="ns-summary-value">—</span>
                    </div>
                    <div class="ns-summary-item ns-sum-ret">
                        <span class="ns-summary-label">برگشت:</span>
                        <span class="ns-summary-value">—</span>
                    </div>
                    <div class="ns-summary-item ns-sum-nights">
                        <span class="ns-summary-label">شب:</span>
                        <span class="ns-summary-value">—</span>
                    </div>
                </div>
                <div class="ns-calendar-actions">
                    <button type="button" class="ns-btn-reset">ریست</button>
                    <button type="button" class="ns-btn-confirm" disabled>تأیید</button>
                </div>
            </div>
        </div>
      `);

      this.$container.append(this.$dropdown);
    }

    bindEvents() {
      const self = this;

      this.$depInput.add(this.$retInput).on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.toggle();
      });

      this.$dropdown.on("click", ".ns-calendar-close-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.close();
      });

      this.$dropdown.on("click", ".ns-btn-reset", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.reset();
      });

      this.$dropdown.on("click", ".ns-btn-confirm", function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (!$(this).prop("disabled")) self.close();
      });

      this.$dropdown.on("click", ".ns-nav-prev", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.navigateMonth(-1);
      });

      this.$dropdown.on("click", ".ns-nav-next", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.navigateMonth(1);
      });

      this.$dropdown.on("click", ".ns-day", function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (
          $(this).hasClass("ns-day-empty") ||
          $(this).hasClass("ns-day-disabled")
        )
          return;

        const day = parseInt($(this).data("day"));
        const month = parseInt($(this).data("month"));
        const year = parseInt($(this).data("year"));

        self.handleDayClick(year, month, day);
      });

      this.$dropdown.on("click", function (e) {
        e.stopPropagation();
      });

      $(document).on("click", function (e) {
        if (!self.state.isOpen) return;
        if (self.state.justOpened) return;

        const $target = $(e.target);

        if ($target.closest(".ns-calendar-dropdown").length) return;
        if ($target.closest(".ns-date-input-group").length) return;

        self.close();
      });

      this.$duration.on("change", function () {
        self.calculateReturnFromDuration();
      });
    }

    // Helper method: convert Persian/Arabic digits to English.
    normalizeDigits(str) {
      if (!str) return str;

      const persianNumbers = ["۰", "۱", "۲", "۳", "۴", "۵", "۶", "۷", "۸", "۹"];
      const arabicNumbers = ["٠", "١", "٢", "٣", "٤", "٥", "٦", "٧", "٨", "٩"];

      let result = String(str);

      for (let i = 0; i < 10; i++) {
        result = result.replace(new RegExp(persianNumbers[i], "g"), String(i));
        result = result.replace(new RegExp(arabicNumbers[i], "g"), String(i));
      }

      return result;
    }

    loadExistingValues() {
      // Normalize digits to prevent bugs if values are stored with Persian digits.
      const depVal = this.normalizeDigits(this.$depInput.val());
      const retVal = this.normalizeDigits(this.$retInput.val());
      const today = new persianDate();

      this.state.viewYear = today.year();
      this.state.viewMonth = today.month();

      if (depVal) {
        try {
          this.state.departure = depVal;

          const depDate = this.parseJalali(depVal);

          if (depDate) {
            this.state.departureUnix = depDate.valueOf();
            this.state.viewYear = depDate.year();
            this.state.viewMonth = depDate.month();
          }
        } catch (e) {}
      }

      if (retVal) {
        try {
          this.state.return = retVal;

          const retDate = this.parseJalali(retVal);

          if (retDate) {
            this.state.returnUnix = retDate.valueOf();
            this.state.selectingReturn = false;
          }
        } catch (e) {}
      }

      if (depVal && retVal) {
        this.updateInputs();
        this.updateStatus();
      }
    }

    toggle() {
      this.state.isOpen ? this.close() : this.open();
    }

    open() {
      this.state.isOpen = true;
      this.state.justOpened = true;

      this.render();
      this.$dropdown.addClass("active");

      setTimeout(() => {
        this.state.justOpened = false;
      }, 150);
    }

    close() {
      this.state.isOpen = false;
      this.state.justOpened = false;
      this.$dropdown.removeClass("active");
    }

    reset() {
      this.state.departure = null;
      this.state.departureUnix = null;
      this.state.return = null;
      this.state.returnUnix = null;
      this.state.selectingReturn = false;

      this.$depInput.val("").removeClass("has-value");
      this.$retInput.val("").removeClass("has-value");

      this.$depHidden.val("");
      this.$retHidden.val("");

      this.setDuration("");
      this.$rangeStatus.empty();

      this.updateStatus();
      this.render();
    }

    navigateMonth(direction) {
      this.state.viewMonth += direction;

      if (this.state.viewMonth > 12) {
        this.state.viewMonth = 1;
        this.state.viewYear++;
      } else if (this.state.viewMonth < 1) {
        this.state.viewMonth = 12;
        this.state.viewYear--;
      }

      this.render();
    }

    handleDayClick(year, month, day) {
      const dateStr = this.formatJalali(year, month, day);

      if (!this.state.selectingReturn) {
        // First click: departure date.
        this.state.departure = dateStr;
        this.state.departureUnix = null;
        this.state.return = null;
        this.state.returnUnix = null;
        this.state.selectingReturn = true;

        console.log("رفت ثبت شد:", dateStr);
      } else {
        // Second click.
        if (dateStr > this.state.departure) {
          this.state.return = dateStr;
          this.state.returnUnix = null;
          this.state.selectingReturn = false;

          console.log("برگشت ثبت شد:", dateStr);

          this.updateInputs();
          this.updateStatus();
          this.render();

          // Auto-close has been removed as requested.
          return;
        } else {
          this.state.departure = dateStr;
          this.state.departureUnix = null;
          this.state.return = null;
          this.state.returnUnix = null;
          this.state.selectingReturn = true;

          console.log("ریست، رفت جدید:", dateStr);
        }
      }

      this.updateStatus();
      this.render();
    }

    setDuration(value) {
      if (!this.$duration.length) {
        console.warn("فیلد مدت اقامت پیدا نشد");
        return;
      }

      if (this.$duration.is("select")) {
        if (
          value !== "" &&
          this.$duration.find(`option[value="${value}"]`).length === 0
        ) {
          this.$duration.append(
            `<option value="${value}">${value} شب</option>`,
          );
        }

        this.$duration.val(value);
      } else {
        this.$duration.val(value);
      }

      this.$duration.trigger("change");
      this.$nightsDisplay.text(value ? `(${value} شب)` : "");
    }

    calculateReturnFromDuration() {
      const nights = parseInt(this.$duration.val());

      if (isNaN(nights) || nights <= 0) return;

      if (!this.state.departure) {
        alert("ابتدا تاریخ رفت را انتخاب کنید!");
        this.setDuration("");
        return;
      }

      try {
        const depDate = this.parseJalali(this.state.departure);

        if (!depDate) return;

        const retDate = depDate.add("days", nights);

        // Use formatJalali instead of the library format to avoid Persian digits.
        const newReturnStr = this.formatJalali(
          retDate.year(),
          retDate.month(),
          retDate.date(),
        );

        if (this.state.return !== newReturnStr) {
          this.state.return = newReturnStr;
          this.state.returnUnix = retDate.valueOf();
          this.state.selectingReturn = false;

          this.updateInputs();
          this.updateStatus();

          if (this.state.isOpen) this.render();
        }
      } catch (e) {
        console.error("خطا در محاسبه تاریخ برگشت:", e);
      }
    }

    updateInputs() {
      if (this.state.departure) {
        this.$depInput.val(this.state.departure).addClass("has-value");
        this.$depHidden.val(this.toGregorian(this.state.departure));
      } else {
        this.$depInput.val("").removeClass("has-value");
        this.$depHidden.val("");
      }

      if (this.state.return) {
        this.$retInput.val(this.state.return).addClass("has-value");
        this.$retHidden.val(this.toGregorian(this.state.return));
      } else {
        this.$retInput.val("").removeClass("has-value");
        this.$retHidden.val("");
      }

      const nights = this.calculateNights();

      // Prevent infinite loop in the change trigger.
      if (nights > 0) {
        const currentDuration = parseInt(this.$duration.val());

        if (currentDuration !== nights) {
          this.setDuration(nights);
        } else {
          this.$nightsDisplay.text(`(${nights} شب)`);
        }
      } else {
        this.$nightsDisplay.text("");
      }

      this.$rangeStatus.html(`
        <span class="ns-range-dep">رفت: <strong>${this.state.departure || "—"}</strong></span>
        <span class="ns-range-ret">برگشت: <strong>${this.state.return || "—"}</strong></span>
        <span class="ns-range-nights">${nights || 0} شب</span>
      `);
    }

    updateStatus() {
      const $step = this.$dropdown.find("#ns-step-indicator");

      $step.removeClass("step-1 step-2 done");

      if (this.state.departure && this.state.return) {
        $step.addClass("done").text("بازه کامل شد");
        this.$dropdown.find(".ns-btn-confirm").prop("disabled", false);
      } else if (this.state.departure) {
        $step.addClass("step-2").text("مرحله ۲: تاریخ برگشت را انتخاب کنید");
        this.$dropdown.find(".ns-btn-confirm").prop("disabled", true);
      } else {
        $step.addClass("step-1").text("مرحله ۱: تاریخ رفت را انتخاب کنید");
        this.$dropdown.find(".ns-btn-confirm").prop("disabled", true);
      }

      const nights = this.calculateNights();

      this.$dropdown
        .find(".ns-sum-dep .ns-summary-value")
        .text(this.state.departure || "—");

      this.$dropdown
        .find(".ns-sum-ret .ns-summary-value")
        .text(this.state.return || "—");

      this.$dropdown
        .find(".ns-sum-nights .ns-summary-value")
        .text(nights || "—");

      this.$dropdown
        .find(".ns-sum-dep")
        .toggleClass("has-value", !!this.state.departure);

      this.$dropdown
        .find(".ns-sum-ret")
        .toggleClass("has-value", !!this.state.return);

      this.$dropdown
        .find(".ns-sum-nights")
        .toggleClass("has-value", nights > 0);
    }

    render() {
      const nextMonth =
        this.state.viewMonth === 12 ? 1 : this.state.viewMonth + 1;

      const nextYear =
        this.state.viewMonth === 12
          ? this.state.viewYear + 1
          : this.state.viewYear;

      this.$dropdown.find("#ns-nav-title").text(
        `${this.MONTH_NAMES[this.state.viewMonth - 1]} ${this.state.viewYear} — ${this.MONTH_NAMES[nextMonth - 1]} ${nextYear}`,
      );

      this.renderMonth(
        this.$dropdown.find('[data-month-slot="1"]'),
        this.state.viewYear,
        this.state.viewMonth,
      );

      this.renderMonth(
        this.$dropdown.find('[data-month-slot="2"]'),
        nextYear,
        nextMonth,
      );
    }

    renderMonth($slot, year, month) {
      const daysInMonth = this.getDaysInMonth(year, month);
      const firstDayOfWeek = this.getFirstDayOfWeek(year, month);

      const today = new persianDate();
      const todayStr = this.formatJalali(
        today.year(),
        today.month(),
        today.date(),
      );

      const tomorrow = new persianDate().add("days", 1);
      const tomorrowStr = this.formatJalali(
        tomorrow.year(),
        tomorrow.month(),
        tomorrow.date(),
      );

      let html = `
        <div class="ns-month-title">${this.MONTH_NAMES[month - 1]} ${year}</div>
        <div class="ns-weekdays">
            ${this.WEEKDAYS.map((d) => `<div class="ns-weekday">${d}</div>`).join("")}
        </div>
        <div class="ns-days-grid">
      `;

      for (let i = 0; i < firstDayOfWeek; i++) {
        html += `<div class="ns-day ns-day-empty"></div>`;
      }

      for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = this.formatJalali(year, month, day);
        const classes = ["ns-day"];

        if (dateStr === todayStr) classes.push("ns-day-today");
        if (dateStr < tomorrowStr) classes.push("ns-day-disabled");

        const isDeparture =
          this.state.departure && dateStr === this.state.departure;

        const isReturn =
          this.state.return && dateStr === this.state.return;

        const isBetween =
          this.state.departure &&
          this.state.return &&
          dateStr > this.state.departure &&
          dateStr < this.state.return;

        if (isDeparture) {
          classes.push("ns-day-range-start", "ns-day-selected");
        } else if (isReturn) {
          classes.push("ns-day-range-end", "ns-day-selected");
        } else if (isBetween) {
          classes.push("ns-day-range-between");
        }

        if (
          this.state.selectingReturn &&
          this.state.departure &&
          dateStr <= this.state.departure
        ) {
          if (!classes.includes("ns-day-disabled"))
            classes.push("ns-day-disabled");
        }

        html += `<div class="${classes.join(" ")}" data-day="${day}" data-month="${month}" data-year="${year}">${day}</div>`;
      }

      html += "</div>";

      $slot.html(html);
    }

    formatJalali(year, month, day) {
      return `${year}/${String(month).padStart(2, "0")}/${String(day).padStart(2, "0")}`;
    }

    toGregorian(jalaliStr) {
      try {
        const pd = this.parseJalali(jalaliStr);

        if (!pd) return "";

        const gd = pd.toGregorian();

        return `${gd.year}-${String(gd.month).padStart(2, "0")}-${String(gd.date).padStart(2, "0")}`;
      } catch (e) {
        return "";
      }
    }

    parseJalali(jalaliStr) {
      try {
        const normalized = this.normalizeDigits(jalaliStr);
        const parts = normalized.split("/").map(Number);

        return new persianDate(parts);
      } catch (e) {
        console.error("خطا در پارس تاریخ:", jalaliStr, e);
        return null;
      }
    }

    calculateNights() {
      if (!this.state.departure || !this.state.return) return 0;

      try {
        const depDate = this.parseJalali(this.state.departure);
        const retDate = this.parseJalali(this.state.return);

        if (!depDate || !retDate) return 0;

        const nights = Math.round(
          (retDate.valueOf() - depDate.valueOf()) / (1000 * 60 * 60 * 24),
        );

        return nights;
      } catch (e) {
        console.error("خطا در محاسبه شب:", e);
        return 0;
      }
    }

    getDaysInMonth(year, month) {
      if (month <= 6) return 31;
      if (month <= 11) return 30;

      return this.isLeapYear(year) ? 30 : 29;
    }

    isLeapYear(year) {
      return [1, 5, 9, 13, 17, 22, 26, 30].includes(year % 33);
    }

    getFirstDayOfWeek(year, month) {
      try {
        const pd = new persianDate(`${year}/${month}/1`);
        const gd = pd.toGregorian();
        const date = new Date(gd.year, gd.month - 1, gd.date);

        return (date.getDay() + 1) % 7;
      } catch (e) {
        return 0;
      }
    }
  }

  $(document).ready(function () {
    if (typeof persianDate === "undefined") {
      console.error("persianDate not loaded");
      return;
    }

    $(".ns-date-range-container").each(function () {
      new PersianRangeCalendar(this);
    });

    /* ======================================================================
       Image Upload
       ====================================================================== */
    $(document).on("click", ".ns-upload-image", function (e) {
      e.preventDefault();

      var $button = $(this);
      var $input = $button.next("input[type=hidden]");
      var $preview = $input.next(".ns-image-preview");

      /* A new media frame is created each time so closure variables stay updated. */
      var mediaFrame = wp.media({
        title: "انتخاب یا آپلود تصویر",
        button: { text: "انتخاب تصویر" },
        multiple: false,
      });

      mediaFrame.on("select", function () {
        var att = mediaFrame.state().get("selection").first().toJSON();

        $input.val(att.id);

        if ($preview.length) {
          $preview.html(
            '<img src="' +
              att.url +
              '" style="max-width:100px; margin-top:5px;">' +
              '<button type="button" class="button ns-remove-image" style="display:block; margin-top:5px;">✕</button>',
          );
        }

        /* After adding an image, the upload/select image button is hidden. */
        $button.hide();

        /* Automatically close the media window after selecting an image. */
        mediaFrame.close();
      });

      mediaFrame.open();
    });

    $(document).on("click", ".ns-remove-image", function (e) {
      e.preventDefault();

      var $preview = $(this).closest(".ns-image-preview");
      var $input = $preview.prev("input[type=hidden]");
      var $uploadBtn = $input.prev(".ns-upload-image");

      /* Clear the input value (the save method deletes term meta when the value is empty). */
      $input.val("");
      $preview.empty();

      /* After removing the image, the upload/select image button becomes visible again. */
      $uploadBtn.show();
    });

    /* ======================================================================
       Transportation
       ====================================================================== */
    $(document).on("change", "#transport", function () {
      const value = $(this).val();

      $(".ns-transport-field").hide();

      if (value === "هواپیما") $("#airline-field").show();
      else if (value === "قطار") $("#train-field").show();
      else if (value === "کشتی") $("#ship-field").show();
      else if (value === "اتوبوس") $("#bus-field").show();
    });

    if ($("#transport").val()) $("#transport").trigger("change");

    /* ======================================================================
       Add Repeatable Items
       ====================================================================== */
    $(document).on("click", ".ns-add-otherfeatures", function (e) {
      e.preventDefault();

      const input = document.createElement("input");
      input.type = "text";
      input.name = "otherfeatures[]";
      input.style.width = "100%";
      input.style.marginBottom = "5px";

      document.getElementById("otherfeatures-fields").appendChild(input);
    });

    $(document).on("click", ".ns-add-otherdocs", function (e) {
      e.preventDefault();

      const input = document.createElement("input");
      input.type = "text";
      input.name = "otherdocs[]";
      input.style.width = "100%";
      input.style.marginBottom = "5px";

      document.getElementById("otherdocs-fields").appendChild(input);
    });

    $(document).on("click", ".ns-add-itinerary", function (e) {
      e.preventDefault();

      const textarea = document.createElement("textarea");
      textarea.name = "itinerary[]";
      textarea.rows = 3;
      textarea.style.width = "100%";
      textarea.style.marginBottom = "5px";

      document.getElementById("itinerary-fields").appendChild(textarea);
    });
  });
})(jQuery);