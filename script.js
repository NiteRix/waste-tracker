/* =========================================================
   Waste Tracking System - dashboard + records front end
   ========================================================= */

const LINES = {
  line1: { name: "Line 1 - Drawing", short: "Line 1" },
  line2: { name: "Line 2 - Extrusion", short: "Line 2" },
  line3: { name: "Line 3 - Stranding", short: "Line 3" },
};

// the bin is a 5 kg load cell, so treat that as the point it needs emptying
const BIN_CAPACITY_KG = 5;
const POLL_MS = 5000;

function lineName(code) {
  return LINES[code] ? LINES[code].name : code;
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]
  ));
}

function typeBadge(type) {
  const isEmptying = type === "emptying";
  const label = isEmptying ? "Emptying" : "Waste";
  const cls = isEmptying ? "badge-emptying" : "badge-waste";
  return `<span class="badge ${cls}">${label}</span>`;
}

// "just now" / "4 min ago" / "2 h ago" - falls back to a date once it is old
function relativeTime(iso) {
  const then = new Date(iso);
  const secs = Math.floor((Date.now() - then.getTime()) / 1000);

  if (!isFinite(secs)) return "";
  if (secs < 10) return "just now";
  if (secs < 60) return `${secs} s ago`;
  if (secs < 3600) return `${Math.floor(secs / 60)} min ago`;
  if (secs < 86400) return `${Math.floor(secs / 3600)} h ago`;
  if (secs < 172800) return "yesterday";
  return then.toLocaleDateString();
}

function fmtClock(date) {
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: false });
}

/* =========================================================
   chart - cumulative waste today, drawn as inline SVG
   ========================================================= */

const SVG_NS = "http://www.w3.org/2000/svg";

function el(name, attrs = {}) {
  const node = document.createElementNS(SVG_NS, name);
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
  return node;
}

// round a maximum up to something a human would put on an axis, without
// leaving so much headroom that the line collapses into the bottom half
function niceMax(value) {
  if (value <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(value)));
  const n = value / pow;
  const steps = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];
  return (steps.find((s) => n <= s) ?? 10) * pow;
}

const chartState = { series: [], points: [] };

function drawChart(series) {
  chartState.series = series;

  const svg = document.getElementById("wasteChart");
  const wrap = document.getElementById("chartWrap");
  const tooltip = document.getElementById("chartTooltip");
  if (!svg || !wrap) return;

  const width = wrap.clientWidth;
  const height = svg.clientHeight || 220;
  if (width === 0) return;

  while (svg.firstChild) svg.removeChild(svg.firstChild);
  svg.setAttribute("width", width);
  svg.setAttribute("height", height);

  const pad = { top: 12, right: 14, bottom: 26, left: 44 };
  const plotW = Math.max(10, width - pad.left - pad.right);
  const plotH = Math.max(10, height - pad.top - pad.bottom);

  // build the cumulative line; emptying events are marked but add no weight
  let running = 0;
  const cumulative = series.map((ev) => {
    if (ev.type !== "emptying") running += ev.weight;
    return { t: new Date(ev.at).getTime(), y: running, type: ev.type, weight: ev.weight };
  });

  // x runs from the first event to now, so the line always reaches the right edge
  const now = Date.now();
  let tMin = cumulative.length ? cumulative[0].t : now - 3600e3;
  let tMax = now;
  if (tMax - tMin < 600e3) tMin = tMax - 600e3; // never squash into under 10 minutes

  const yMax = niceMax(running);

  const xOf = (t) => pad.left + ((t - tMin) / (tMax - tMin)) * plotW;
  const yOf = (v) => pad.top + plotH - (v / yMax) * plotH;

  // ---- defs: area gradient + the glow on the line ----
  const defs = el("defs");
  const grad = el("linearGradient", { id: "areaGradient", x1: "0", y1: "0", x2: "0", y2: "1" });
  grad.appendChild(el("stop", { offset: "0%", "stop-color": "#3987e5", "stop-opacity": "0.28" }));
  grad.appendChild(el("stop", { offset: "100%", "stop-color": "#3987e5", "stop-opacity": "0" }));
  defs.appendChild(grad);

  const filter = el("filter", { id: "lineGlow", x: "-20%", y: "-40%", width: "140%", height: "180%" });
  filter.appendChild(el("feGaussianBlur", { stdDeviation: "3", result: "blur" }));
  const merge = el("feMerge");
  merge.appendChild(el("feMergeNode", { in: "blur" }));
  merge.appendChild(el("feMergeNode", { in: "SourceGraphic" }));
  filter.appendChild(merge);
  defs.appendChild(filter);
  svg.appendChild(defs);

  // ---- horizontal grid + y labels ----
  for (let i = 0; i <= 4; i++) {
    const v = (yMax / 4) * i;
    const y = yOf(v);
    svg.appendChild(el("line", {
      class: "grid-line", x1: pad.left, y1: y, x2: pad.left + plotW, y2: y,
    }));
    const label = el("text", {
      class: "axis-label", x: pad.left - 8, y: y + 3.5, "text-anchor": "end",
    });
    label.textContent = yMax >= 10 ? v.toFixed(0) : v.toFixed(1);
    svg.appendChild(label);
  }

  // ---- x labels ----
  const xTicks = width < 520 ? 3 : 5;
  for (let i = 0; i <= xTicks; i++) {
    const t = tMin + ((tMax - tMin) / xTicks) * i;
    const label = el("text", {
      class: "axis-label",
      x: xOf(t),
      y: pad.top + plotH + 17,
      "text-anchor": i === 0 ? "start" : i === xTicks ? "end" : "middle",
    });
    label.textContent = fmtClock(new Date(t));
    svg.appendChild(label);
  }

  if (cumulative.length === 0) {
    const note = el("text", {
      class: "axis-label",
      x: pad.left + plotW / 2,
      y: pad.top + plotH / 2,
      "text-anchor": "middle",
    });
    note.textContent = "No events recorded today";
    svg.appendChild(note);
    chartState.points = [];
    return;
  }

  // ---- stepped path: weight holds flat between events, jumps on each one ----
  const steps = [];
  steps.push([xOf(cumulative[0].t), yOf(0)]);
  let prevY = 0;
  cumulative.forEach((p) => {
    steps.push([xOf(p.t), yOf(prevY)]);
    steps.push([xOf(p.t), yOf(p.y)]);
    prevY = p.y;
  });
  steps.push([xOf(tMax), yOf(prevY)]);

  const linePath = steps.map(([x, y], i) => `${i === 0 ? "M" : "L"}${x.toFixed(1)},${y.toFixed(1)}`).join(" ");
  const baseline = yOf(0);
  const areaPath = `${linePath} L${xOf(tMax).toFixed(1)},${baseline.toFixed(1)} L${steps[0][0].toFixed(1)},${baseline.toFixed(1)} Z`;

  svg.appendChild(el("path", { class: "area", d: areaPath }));
  svg.appendChild(el("path", { class: "line", d: linePath }));

  // ---- emptying markers: a tick on the baseline, since they reset the bin ----
  cumulative.filter((p) => p.type === "emptying").forEach((p) => {
    svg.appendChild(el("line", {
      class: "empty-tick",
      x1: xOf(p.t), y1: baseline - 7, x2: xOf(p.t), y2: baseline + 5,
    }));
  });

  // ---- event dots, but only while they stay legible ----
  if (cumulative.length <= 40) {
    cumulative.filter((p) => p.type !== "emptying").forEach((p) => {
      svg.appendChild(el("circle", { class: "point", cx: xOf(p.t), cy: yOf(p.y), r: 4 }));
    });
  }

  // ---- hover layer ----
  const cursor = el("line", {
    class: "cursor-line", x1: 0, y1: pad.top, x2: 0, y2: pad.top + plotH, opacity: "0",
  });
  svg.appendChild(cursor);
  const marker = el("circle", { class: "point", cx: 0, cy: 0, r: 5, opacity: "0" });
  svg.appendChild(marker);

  const hit = el("rect", {
    class: "hit", x: pad.left, y: pad.top, width: plotW, height: plotH,
  });
  svg.appendChild(hit);

  chartState.points = cumulative.map((p) => ({ ...p, x: xOf(p.t), yPix: yOf(p.y) }));

  function moveTo(clientX) {
    const rect = svg.getBoundingClientRect();
    const x = clientX - rect.left;

    // nearest event at or before the cursor
    let nearest = chartState.points[0];
    for (const p of chartState.points) {
      if (p.x <= x) nearest = p; else break;
    }
    if (!nearest) return;

    cursor.setAttribute("x1", nearest.x);
    cursor.setAttribute("x2", nearest.x);
    cursor.setAttribute("opacity", "1");
    marker.setAttribute("cx", nearest.x);
    marker.setAttribute("cy", nearest.yPix);
    marker.setAttribute("opacity", "1");

    if (tooltip) {
      const label = nearest.type === "emptying" ? "Bin emptied" : `+${nearest.weight.toFixed(2)} kg`;
      tooltip.innerHTML =
        `<div class="tt-time">${fmtClock(new Date(nearest.t))}</div>` +
        `<div class="tt-value">${nearest.y.toFixed(2)} kg total</div>` +
        `<div class="tt-time">${escapeHtml(label)}</div>`;
      tooltip.style.left = `${Math.min(Math.max(nearest.x, 60), width - 60)}px`;
      tooltip.style.top = `${nearest.yPix - 12}px`;
      tooltip.classList.add("is-visible");
    }
  }

  function hide() {
    cursor.setAttribute("opacity", "0");
    marker.setAttribute("opacity", "0");
    if (tooltip) tooltip.classList.remove("is-visible");
  }

  hit.addEventListener("mousemove", (e) => moveTo(e.clientX));
  hit.addEventListener("mouseleave", hide);
  hit.addEventListener("touchmove", (e) => {
    if (e.touches[0]) moveTo(e.touches[0].clientX);
  }, { passive: true });
  hit.addEventListener("touchend", hide);
}

/* =========================================================
   dashboard
   ========================================================= */

let knownIds = null; // null until the first load, so nothing flashes on arrival

function renderStats(data) {
  const device = data.device;
  const isLive = device && device.online;

  // the scale itself is the better source when we can hear from it; the sum of
  // events since the last emptying is the fallback when we cannot
  const bin = isLive ? device.live_weight : data.bin_weight;
  document.getElementById("binWeight").textContent = bin.toFixed(2);
  document.getElementById("todayTotal").textContent = data.today_total.toFixed(2);
  document.getElementById("eventsCount").textContent = data.today_events;

  // bin fill gauge
  const pct = Math.min(100, (bin / BIN_CAPACITY_KG) * 100);
  const fill = document.getElementById("binGauge");
  fill.style.width = `${pct}%`;
  fill.classList.toggle("is-full", pct >= 80);

  const binNote = document.getElementById("binNote");
  const source = isLive ? "live from scale" : "from logged events";
  if (pct >= 80) {
    binNote.innerHTML = `<span class="delta up">${pct.toFixed(0)}% full</span> &middot; needs emptying`;
  } else if (data.last_emptied) {
    binNote.innerHTML = `${pct.toFixed(0)}% of ${BIN_CAPACITY_KG} kg &middot; ${source}`;
  } else {
    binNote.innerHTML = `${pct.toFixed(0)}% of ${BIN_CAPACITY_KG} kg &middot; ${source}`;
  }

  // today vs yesterday
  const note = document.getElementById("todayNote");
  const y = data.yesterday_total;
  if (y > 0) {
    const change = ((data.today_total - y) / y) * 100;
    const cls = Math.abs(change) < 1 ? "flat" : change > 0 ? "up" : "down";
    const arrow = Math.abs(change) < 1 ? "" : change > 0 ? "▲ " : "▼ ";
    note.innerHTML = `<span class="delta ${cls}">${arrow}${Math.abs(change).toFixed(0)}%</span> vs yesterday`;
  } else {
    note.textContent = "No comparison for yesterday";
  }

  const eventsNote = document.getElementById("eventsNote");
  eventsNote.textContent = data.recent.length
    ? `Last event ${relativeTime(data.recent[0].at)}`
    : "Waiting for the first event";
}

function renderRecent(recent) {
  const tbody = document.getElementById("recordsBody");
  const empty = document.getElementById("recentEmpty");
  const table = document.getElementById("recordsTable");

  if (recent.length === 0) {
    table.style.display = "none";
    empty.style.display = "block";
    return;
  }

  table.style.display = "";
  empty.style.display = "none";

  const firstLoad = knownIds === null;
  const seen = firstLoad ? new Set() : knownIds;

  tbody.innerHTML = "";
  recent.forEach((r) => {
    const row = document.createElement("tr");
    if (!firstLoad && !seen.has(r.id)) row.className = "is-new";
    row.innerHTML = `
      <td class="time">${escapeHtml(r.time)}</td>
      <td><span class="line-cell">${escapeHtml(lineName(r.line))}</span></td>
      <td class="num">${r.weight.toFixed(2)}</td>
      <td>${typeBadge(r.type)}</td>
      <td class="time hide-xs">${escapeHtml(relativeTime(r.at))}</td>
    `;
    tbody.appendChild(row);
  });

  knownIds = new Set(recent.map((r) => r.id));
}

// three different things can be wrong, and they are not the same problem:
//   connected - the bin is sending heartbeats
//   waiting   - the server is fine, but no device has ever checked in
//   offline   - the bin was here and has gone quiet
//   lost      - the browser cannot reach the server at all
function setLive(state, message) {
  const live = document.getElementById("liveIndicator");
  if (!live) return;
  live.classList.toggle("is-stale", state === "lost");
  live.classList.toggle("is-offline", state === "offline" || state === "waiting");
  live.querySelector(".live-label").textContent = message;
}

function deviceState(device) {
  if (!device || !device.known) {
    return { state: "waiting", message: "Waiting for bin" };
  }
  if (device.online) {
    return { state: "connected", message: `Bin online · ${device.live_weight.toFixed(2)} kg` };
  }
  return { state: "offline", message: `Bin offline · ${relativeTime(device.last_seen)}` };
}

async function loadDashboard() {
  try {
    const res = await fetch("api/get_dashboard.php", { cache: "no-store" });

    if (res.status === 401) {
      window.location.href = "index.php";
      return;
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);

    const data = await res.json();

    renderStats(data);
    renderRecent(data.recent);
    drawChart(data.series);

    const { state, message } = deviceState(data.device);
    setLive(state, message);
  } catch (err) {
    console.error("Dashboard update failed:", err);
    setLive("lost", "Connection lost");
  }
}

/* =========================================================
   deleting records
   ========================================================= */

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") : "";
}

// a styled confirmation, so a destructive action never rides on a native
// dialog the user can dismiss on autopilot. Resolves true only on confirm.
function confirmAction(title, text, confirmLabel) {
  return new Promise((resolve) => {
    const modal = document.getElementById("confirmModal");
    const okBtn = document.getElementById("confirmOk");
    const cancelBtn = document.getElementById("confirmCancel");

    document.getElementById("confirmTitle").textContent = title;
    document.getElementById("confirmText").textContent = text;
    okBtn.textContent = confirmLabel || "Delete";

    modal.hidden = false;
    okBtn.focus();

    function cleanup(result) {
      modal.hidden = true;
      okBtn.removeEventListener("click", onOk);
      cancelBtn.removeEventListener("click", onCancel);
      modal.removeEventListener("click", onBackdrop);
      document.removeEventListener("keydown", onKey);
      resolve(result);
    }

    function onOk() { cleanup(true); }
    function onCancel() { cleanup(false); }
    function onBackdrop(e) { if (e.target === modal) cleanup(false); }
    function onKey(e) { if (e.key === "Escape") cleanup(false); }

    okBtn.addEventListener("click", onOk);
    cancelBtn.addEventListener("click", onCancel);
    modal.addEventListener("click", onBackdrop);
    document.addEventListener("keydown", onKey);
  });
}

async function deleteRecords(payload) {
  const res = await fetch("api/delete_records.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...payload, csrf_token: csrfToken() }),
  });

  if (res.status === 401) {
    window.location.href = "index.php";
    return null;
  }

  const data = await res.json().catch(() => ({}));

  if (!res.ok) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
}

/* =========================================================
   all records page
   ========================================================= */

let activeFilter = { line: "", date: "" };

async function loadAllRecords(lineFilter = "", dateFilter = "") {
  activeFilter = { line: lineFilter, date: dateFilter };
  const tbody = document.getElementById("allRecordsBody");
  const empty = document.getElementById("recordsEmpty");
  const table = document.getElementById("allRecordsTable");
  const count = document.getElementById("resultCount");

  const params = new URLSearchParams();
  if (lineFilter) params.append("line", lineFilter);
  if (dateFilter) params.append("date", dateFilter);

  let records = [];
  try {
    const res = await fetch("api/get_records.php?" + params.toString(), { cache: "no-store" });
    if (res.status === 401) {
      window.location.href = "index.php";
      return;
    }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    records = await res.json();
  } catch (err) {
    console.error("Failed to load records:", err);
    table.style.display = "none";
    empty.style.display = "block";
    empty.querySelector("h4").textContent = "Could not load records";
    empty.querySelector("p").textContent = "The server did not respond. Check that the database is running, then try again.";
    count.textContent = "";
    return;
  }

  const total = records.reduce((sum, r) => (r.type === "waste" ? sum + r.weight : sum), 0);
  count.textContent = records.length
    ? `${records.length} record${records.length === 1 ? "" : "s"} · ${total.toFixed(2)} kg`
    : "";

  if (records.length === 0) {
    table.style.display = "none";
    empty.style.display = "block";
    return;
  }

  table.style.display = "";
  empty.style.display = "none";

  tbody.innerHTML = "";
  records.slice().reverse().forEach((r) => {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td class="time">${escapeHtml(r.date)}</td>
      <td class="time">${escapeHtml(r.time)}</td>
      <td><span class="line-cell">${escapeHtml(lineName(r.line))}</span></td>
      <td class="num">${r.weight.toFixed(2)}</td>
      <td>${typeBadge(r.type)}</td>
      <td class="col-action">
        <button class="icon-btn" data-id="${r.id}" title="Delete this record" aria-label="Delete record from ${escapeHtml(r.date)} ${escapeHtml(r.time)}">&times;</button>
      </td>
    `;
    tbody.appendChild(row);
  });

  // one listener on the table body rather than one per row
  tbody.onclick = async (e) => {
    const btn = e.target.closest(".icon-btn");
    if (!btn) return;

    const id = parseInt(btn.dataset.id, 10);
    const record = records.find((r) => r.id === id);
    if (!record) return;

    const ok = await confirmAction(
      "Delete this record?",
      `${record.date} at ${record.time} - ${lineName(record.line)}, ${record.weight.toFixed(2)} kg. This cannot be undone.`,
      "Delete record"
    );
    if (!ok) return;

    try {
      await deleteRecords({ mode: "one", id });
      loadAllRecords(activeFilter.line, activeFilter.date);
    } catch (err) {
      alert("Could not delete the record: " + err.message);
    }
  };
}

/* =========================================================
   boot
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {

  if (document.getElementById("recordsBody")) {
    loadDashboard();
    setInterval(loadDashboard, POLL_MS);

    // keep the chart proportioned when the window changes size
    let resizeTimer;
    window.addEventListener("resize", () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => drawChart(chartState.series), 150);
    });
  }

  if (document.getElementById("allRecordsBody")) {
    const lineSel = document.getElementById("lineFilter");
    const dateInput = document.getElementById("dateFilter");

    loadAllRecords();

    document.getElementById("filterBtn").addEventListener("click", () => {
      loadAllRecords(lineSel.value, dateInput.value);
    });

    document.getElementById("resetBtn").addEventListener("click", () => {
      lineSel.value = "";
      dateInput.value = "";
      loadAllRecords();
    });

    // Enter in the date field should filter, not do nothing
    dateInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") loadAllRecords(lineSel.value, dateInput.value);
    });

    document.getElementById("resetAllBtn").addEventListener("click", async () => {
      const ok = await confirmAction(
        "Delete every record?",
        "This permanently removes all waste and emptying events, including anything the bin has logged. The bin will carry on recording new events afterwards. This cannot be undone.",
        "Delete everything"
      );
      if (!ok) return;

      try {
        const result = await deleteRecords({ mode: "all" });
        if (result) {
          lineSel.value = "";
          dateInput.value = "";
          loadAllRecords();
        }
      } catch (err) {
        alert("Could not delete the records: " + err.message);
      }
    });
  }

});
