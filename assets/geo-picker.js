(function () {
  var modal, map, marker, input, current;
  var DEFAULT_CENTER = [35.6892, 51.3890]; // Tehran

  function parseVal() {
    var v = (input.value || '').trim();
    var p = v.split(',');
    if (p.length !== 2) return null;
    var lat = parseFloat(p[0]), lng = parseFloat(p[1]);
    if (isNaN(lat) || isNaN(lng)) return null;
    return [lat, lng];
  }

  function setMarker(lat, lng) {
    if (lat === null) {
      if (marker) { marker.remove(); marker = null; }
      current.textContent = '—';
      return;
    }

    if (marker) {
      marker.setLatLng([lat, lng]);
    } else {
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on('dragend', function () {
        var ll = marker.getLatLng();
        setMarker(ll.lat, ll.lng);
      });
    }

    current.textContent = lat.toFixed(6) + ',' + lng.toFixed(6);
  }

  function openModal() {
    modal.style.display = 'flex';

    if (!map) {
      map = L.map('ns-geo-map', { zoomControl: true }).setView(DEFAULT_CENTER, 5);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
      }).addTo(map);

      map.on('click', function (e) {
        setMarker(e.latlng.lat, e.latlng.lng);
      });
    }

    setTimeout(function () { map.invalidateSize(); }, 120);

    var p = parseVal();

    if (p) {
      map.setView(p, 15);
      setMarker(p[0], p[1]);
    } else {
      setMarker(null, null);
    }
  }

  function useCoords() {
    if (!marker) { alert('اول روی نقشه کلیک کن'); return; }

    var ll = marker.getLatLng();
    input.value = ll.lat.toFixed(6) + ',' + ll.lng.toFixed(6);
    closeModal();
  }

  function closeModal() {
    modal.style.display = 'none';
  }

  function search() {
    var q = document.getElementById('ns-geo-search').value.trim();
    if (!q) return;

    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q), {
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (rows) {
        if (!rows || !rows.length) { alert('نتیجه‌ای پیدا نشد'); return; }

        var lat = parseFloat(rows[0].lat), lng = parseFloat(rows[0].lon);

        map.setView([lat, lng], 15);
        setMarker(lat, lng);
      })
      .catch(function () { alert('خطا در جستجو'); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    input = document.getElementById('ns_geo_coords');
    modal = document.getElementById('ns-geo-modal');
    current = document.getElementById('ns-geo-current');

    if (!input || !modal) return;

    document.getElementById('ns-geo-open-map').addEventListener('click', openModal);
    document.getElementById('ns-geo-close').addEventListener('click', closeModal);
    document.getElementById('ns-geo-use').addEventListener('click', useCoords);
    document.getElementById('ns-geo-do-search').addEventListener('click', search);

    document.getElementById('ns-geo-search').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); search(); }
    });
  });
})();