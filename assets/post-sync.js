(function () {
  var modal, postId;

  function ensureModal() {
    if (modal) return;

    modal = document.createElement("div");
    modal.id = "ns-sync-modal";
    modal.style.cssText =
      "position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:flex;align-items:center;justify-content:center;";

    modal.innerHTML =
      '<div style="width:92%;max-width:780px;max-height:85vh;background:#fff;border-radius:10px;display:flex;flex-direction:column;overflow:hidden;">' +
      '<div style="padding:10px;border-bottom:1px solid #dcdcde;display:flex;gap:8px;background:#f6f7f7;">' +
      '<input id="ns-sync-query" style="flex:1;" placeholder="عبارت جستجو (نام + شهر)...">' +
      '<button class="button" id="ns-sync-search">🔍 جستجو</button>' +
      '<button class="button" id="ns-sync-close">بستن</button></div>' +
      '<div id="ns-sync-status" style="padding:8px 12px;font-size:12px;"></div>' +
      '<div id="ns-sync-list" style="overflow-y:auto;padding:10px;"></div></div>';

    document.body.appendChild(modal);

    document.getElementById("ns-sync-close").onclick = function () {
      modal.style.display = "none";
    };

    document.getElementById("ns-sync-search").onclick = search;

    document.getElementById("ns-sync-query").addEventListener("keydown", function (e) {
      if (e.key === "Enter") search();
    });
  }

  function status(t) {
    document.getElementById("ns-sync-status").textContent = t;
  }

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s || "";
    return d.innerHTML;
  }

  function openModal(pid, q) {
    ensureModal();

    postId = pid;
    modal.style.display = "flex";

    document.getElementById("ns-sync-query").value = q || "";
    document.getElementById("ns-sync-list").innerHTML = "";

    if (q) search();
  }

  function search() {
    var q = document.getElementById("ns-sync-query").value.trim();

    if (!q) return;

    status("⏳ در حال جستجو از SearchApi...");

    jQuery.post(
      ajaxurl,
      {
        action: "ns_post_sync_candidates",
        nonce: NS_SYNC.nonce,
        post_id: postId,
        query: q,
      },
      function (res) {
        if (!res.success) {
          status("❌ " + ((res.data && res.data.message) || "خطا"));
          return;
        }

        render(res.data.candidates);
      },
    ).fail(function () {
      status("❌ خطای ارتباط");
    });
  }

  function render(cands) {
    var list = document.getElementById("ns-sync-list");

    list.innerHTML = "";

    status(cands.length + " نتیجه پیدا شد — یکی را انتخاب کن:");

    cands.forEach(function (c) {
      var row = document.createElement("div");

      row.style.cssText =
        "display:flex;gap:10px;align-items:center;padding:8px;border:1px solid #dcdcde;border-radius:8px;margin-bottom:8px;";

      row.innerHTML =
        (c.thumbnail
          ? '<img src="' +
            c.thumbnail +
            '" style="width:56px;height:56px;object-fit:cover;border-radius:6px;">'
          : "") +
        '<div style="flex:1;"><strong>' +
        esc(c.name) +
        "</strong><br>" +
        '<span style="font-size:11px;color:#666;">' +
        esc(c.address) +
        "</span><br>" +
        '<span style="font-size:11px;">⭐ ' +
        c.rating +
        " (" +
        c.reviews +
        ") • شباهت: " +
        Math.round(c.similarity * 100) +
        "٪</span></div>" +
        '<button class="button button-primary">اعمال</button>';

      row.querySelector("button").onclick = function () {
        apply(c);
      };

      list.appendChild(row);
    });
  }

  function apply(c) {
    status("⏳ در حال اعمال داده روی پست...");

    jQuery.post(
      ajaxurl,
      {
        action: "ns_post_sync_apply",
        nonce: NS_SYNC.nonce,
        post_id: postId,
        data: JSON.stringify(c.data),
        force: c.similarity < 0.35 ? 1 : 0,
      },
      function (res) {
        if (!res.success) {
          status("❌ " + ((res.data && res.data.message) || "خطا"));
          return;
        }

        status("✅ اعمال شد: " + (res.data.applied || []).join("، "));

        setTimeout(function () {
          location.reload();
        }, 1200);
      },
    ).fail(function () {
      status("❌ خطای ارتباط");
    });
  }

  /* ==========================================================================
     Bulk Sync
     ========================================================================== */
  var bulkOffset = 0,
    bulkDone = 0;

  function bulkRun() {
    var log = document.getElementById("ns-bulk-log");
    var btn = document.getElementById("ns-bulk-start");

    btn.disabled = true;

    function step() {
      jQuery.post(
        ajaxurl,
        {
          action: "ns_bulk_sync_step",
          nonce: NS_SYNC.nonce,
          offset: bulkOffset,
          batch: 5,
        },
        function (res) {
          if (!res.success) {
            log.innerHTML +=
              '<div style="color:#a00;">❌ ' +
              ((res.data && res.data.message) || "خطا") +
              "</div>";
            btn.disabled = false;
            return;
          }

          var d = res.data;

          d.results.forEach(function (r) {
            var color =
              r.status === "ok"
                ? "#0a0"
                : r.status === "low_sim"
                  ? "#c80"
                  : "#a00";

            log.innerHTML +=
              '<div style="color:' +
              color +
              ";'>[" +
              r.status +
              "] " +
              esc(r.title) +
              (r.sim ? " (" + r.sim + "٪)" : "") +
              "</div>";
          });

          log.scrollTop = log.scrollHeight;

          bulkOffset = d.offset;
          bulkDone += d.processed;

          document.getElementById("ns-bulk-progress").textContent =
            "پردازش‌شده: " + bulkDone;

          if (d.processed > 0) {
            setTimeout(step, 1000);
          } else {
            log.innerHTML += '<div style="color:#0a0;">✅ پایان سینک انبوه</div>';
            btn.disabled = false;
          }
        },
      ).fail(function () {
        log.innerHTML += '<div style="color:#a00;">❌ خطای ارتباط</div>';
        btn.disabled = false;
      });
    }

    step();
  }

  document.addEventListener("DOMContentLoaded", function () {
    var btn = document.getElementById("ns-sync-open");

    if (btn) {
      btn.onclick = function () {
        openModal(parseInt(btn.dataset.post, 10), btn.dataset.query);
      };
    }

    var bulkBtn = document.getElementById("ns-bulk-start");

    if (bulkBtn) {
      bulkBtn.onclick = bulkRun;
    }
  });
})();