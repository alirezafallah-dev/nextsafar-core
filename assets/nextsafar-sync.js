/**
 * NextSafar Sync Page Scripts
 * Manages synchronization on the sync page.
 * @version 1.0.0
 */
(function ($) {
  "use strict";

  var NS = window.NextSafar;

  $(document).ready(function () {
    var $sourcesContainer = $("#sources-table-container");
    var $sourcesLoading = $("#sources-loading");

    /* ==========================================================================
       Load Sources Table
       ========================================================================== */
    function loadSourcesTable() {
      $sourcesLoading.show();
      $sourcesContainer.hide();

      NS.ajax.getStats({
        success: function (sources) {
          $sourcesLoading.hide();
          $sourcesContainer.show();

          if (!sources || sources.length === 0) {
            $sourcesContainer.html(
              '<p class="ns-text-center" style="color: var(--ns-gray-500); padding: 20px;">' +
                "هنوز منبعی ثبت نشده" +
                "</p>",
            );
            return;
          }

          renderSourcesTable(sources);
        },
        error: function () {
          $sourcesLoading.hide();
          $sourcesContainer.html(
            '<div class="ns-alert ns-alert-danger">' +
              "خطا در بارگذاری منابع" +
              "</div>",
          );
        },
      });
    }

    function renderSourcesTable(sources) {
      var html = '<table class="ns-table">';

      html += "<thead><tr>";
      html += "<th>منبع</th>";
      html += "<th>نوع</th>";
      html += "<th>گروه</th>";
      html += "<th>آخرین دریافت</th>";
      html += "<th>کل دریافتی</th>";
      html += "<th>تکراری</th>";
      html += "<th>وضعیت</th>";
      html += "</tr></thead><tbody>";

      sources.forEach(function (s) {
        html += "<tr>";
        html += "<td><strong>" + escHtml(s.name) + "</strong></td>";
        html +=
          '<td><span class="ns-badge ns-badge-info">' +
          escHtml(s.type.toUpperCase()) +
          "</span></td>";
        html += "<td>" + escHtml(s.group_name) + "</td>";
        html +=
          "<td>" +
          (s.last_fetch ? NS.utils.formatDate(s.last_fetch) : "<em>هرگز</em>") +
          "</td>";
        html += "<td>" + NS.utils.formatNumber(s.total_fetched || 0) + "</td>";
        html +=
          "<td>" + NS.utils.formatNumber(s.total_duplicates || 0) + "</td>";
        html += "<td>";

        if (s.is_active == 1) {
          html += '<span class="ns-badge ns-badge-success">✅ فعال</span>';
        } else {
          html += '<span class="ns-badge ns-badge-danger">❌ غیرفعال</span>';
        }

        if (s.error_message) {
          html +=
            '<br><small style="color: var(--ns-danger);">' +
            escHtml(s.error_message) +
            "</small>";
        }

        html += "</td>";
        html += "</tr>";
      });

      html += "</tbody></table>";

      $sourcesContainer.html(html);
    }

    function escHtml(str) {
      if (!str) return "";

      return str
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    /* ==========================================================================
       Events
       ========================================================================== */

    // Initial load.
    loadSourcesTable();

    // Refresh button.
    $("#refresh-sources-btn").on("click", loadSourcesTable);

    // Reset sources button.
    $("#reset-sources-btn").on("click", function () {
      if (!confirm(nextsafarAdmin.strings.confirmReset)) {
        return;
      }

      var $btn = $(this);

      $btn.prop("disabled", true).text("⏳ در حال بازنشانی...");

      NS.ajax.resetSources({
        success: function (result) {
          $btn.prop("disabled", false).text("🔄 بازنشانی منابع پیش‌فرض");
          NS.utils.showNotice(result.message, "success");
          loadSourcesTable();
        },
        error: function () {
          $btn.prop("disabled", false).text("🔄 بازنشانی منابع پیش‌فرض");
        },
      });
    });

    // Get AI models list.
    $("#load-models-btn").on("click", function () {
      var $btn = $(this);
      var $status = $("#models-status");
      var $select = $("#ai-model-select");
      var provider = $("#ai-provider").val();

      $btn.prop("disabled", true).text("⏳ در حال دریافت...");
      $status.html(
        '<div class="ns-alert ns-alert-info">در حال دریافت لیست مدل‌ها از ' +
          provider +
          "...</div>",
      );

      NS.ajax.getAIModels(provider, {
        success: function (data) {
          $btn.prop("disabled", false).text("🔄 دریافت لیست مدل‌ها");

          if (data.error) {
            $status.html(
              '<div class="ns-alert ns-alert-danger">' + data.error + "</div>",
            );
            return;
          }

          var models = data.models || [];

          if (models.length === 0) {
            $status.html(
              '<div class="ns-alert ns-alert-warning">هیچ مدلی یافت نشد</div>',
            );
            return;
          }

          // Populate dropdown.
          $select.empty();

          var recommended = [];
          var others = [];

          models.forEach(function (m) {
            var label = m.name;

            if (m.is_flash) label += " ⚡";
            if (m.is_mini) label += " 💨";
            if (m.is_4o) label += " 🌟";

            var option = $("<option>").val(m.name).text(label);

            if (
              (provider === "gemini" &&
                m.name.match(/^gemini-3\.[5-9]|^gemini-3\.1/)) ||
              (provider === "openai" && m.is_4o)
            ) {
              recommended.push(option);
            } else {
              others.push(option);
            }
          });

          if (recommended.length > 0) {
            var group1 = $('<optgroup label="⭐ توصیه‌شده">');

            recommended.forEach(function (opt) {
              group1.append(opt);
            });

            $select.append(group1);
          }

          if (others.length > 0) {
            var group2 = $('<optgroup label="سایر مدل‌ها">');

            others.forEach(function (opt) {
              group2.append(opt);
            });

            $select.append(group2);
          }

          if (recommended.length > 0) {
            $select.val(recommended[0].val());
          }

          $status.html(
            '<div class="ns-alert ns-alert-success">' +
              models.length +
              " مدل یافت شد.</div>",
          );
        },
        error: function () {
          $btn.prop("disabled", false).text("🔄 دریافت لیست مدل‌ها");
          $status.html(
            '<div class="ns-alert ns-alert-danger">خطا در ارتباط با سرور</div>',
          );
        },
      });
    });

    // Provider change.
    $("#ai-provider").on("change", function () {
      $("#ai-model-select").html(
        '<option value="">-- ابتدا روی "دریافت لیست مدل‌ها" کلیک کنید --</option>',
      );
      $("#models-status").empty();
    });

    /* ==========================================================================
       News Sync
       ========================================================================== */
    var newsSyncInProgress = false;

    $("#start-news-sync").on("click", function () {
      if (newsSyncInProgress) {
        NS.utils.showNotice("یک سینک در حال اجرا است!", "warning");
        return;
      }

      var mode = $('input[name="news_sync_mode"]:checked').val();

      newsSyncInProgress = true;

      var $btn = $(this);

      $btn.prop("disabled", true).text("⏳ در حال اجرا...");

      $("#news-progress").show();
      $("#news-results").hide();

      NS.ui.clearLog($("#news-sync-log"));
      NS.ui.appendLog($("#news-sync-log"), "🚀 شروع دریافت اخبار...");

      NS.ajax.request(
        "nextsafar_sync_news",
        {
          mode: mode,
        },
        {
          success: function (d) {
            newsSyncInProgress = false;
            $btn.prop("disabled", false).text("🚀 شروع دریافت اخبار");

            NS.ui.appendLog(
              $("#news-sync-log"),
              "📡 دریافت از RSS: " + (d.fetched_rss || 0) + " خبر",
            );

            NS.ui.appendLog(
              $("#news-sync-log"),
              "🌐 دریافت از API: " + (d.fetched_api || 0) + " خبر",
            );

            NS.ui.appendLog(
              $("#news-sync-log"),
              "🔍 تکراری حذف شده: " + (d.duplicates || 0),
            );

            NS.ui.appendLog(
              $("#news-sync-log"),
              "✅ ایجاد شده: " + (d.created || 0),
            );

            if (d.ai_used > 0) {
              NS.ui.appendLog(
                $("#news-sync-log"),
                "🤖 بازنویسی با AI: " + d.ai_used,
              );
            }

            NS.ui.appendLog(
              $("#news-sync-log"),
              "❌ ناموفق: " + (d.failed || 0),
            );

            NS.ui.appendLog($("#news-sync-log"), "\n🎉 همگام‌سازی کامل شد!");

            renderNewsResults(d);
          },
          error: function () {
            newsSyncInProgress = false;
            $btn.prop("disabled", false).text("🚀 شروع دریافت اخبار");
          },
        },
      );
    });

    function renderNewsResults(d) {
      var html = '<table class="ns-table">';

      html += "<tr><th>مورد</th><th>تعداد</th></tr>";

      html +=
        "<tr><td>دریافت از RSS</td><td>" +
        NS.utils.formatNumber(d.fetched_rss || 0) +
        "</td></tr>";

      html +=
        "<tr><td>دریافت از API</td><td>" +
        NS.utils.formatNumber(d.fetched_api || 0) +
        "</td></tr>";

      html +=
        '<tr><td>تکراری حذف شده</td><td style="color: var(--ns-danger);">' +
        NS.utils.formatNumber(d.duplicates || 0) +
        "</td></tr>";

      html +=
        '<tr><td>ایجاد شده</td><td style="color: var(--ns-success);">' +
        NS.utils.formatNumber(d.created || 0) +
        "</td></tr>";

      html +=
        "<tr><td>بازنویسی با AI</td><td>" +
        NS.utils.formatNumber(d.ai_used || 0) +
        "</td></tr>";

      html +=
        "<tr><td>ناموفق</td><td>" +
        NS.utils.formatNumber(d.failed || 0) +
        "</td></tr>";

      html += "</table>";

      $("#news-results-content").html(html);
      $("#news-results").show();
    }
  });
})(jQuery);