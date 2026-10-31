/* Chart rendering for dashboard/report pages. Data comes from JSON script tags
 * rendered by the server from real database aggregates. */
(function () {
  "use strict";
  if (!window.Chart) return;

  const NAVY = "#0B1F33";
  const ORANGE = "#FF6B00";
  const GREEN = "#059669";
  const PALETTE = [ORANGE, NAVY, "#2E4E72", "#FFA35F", "#7F98B6", "#B84900", "#059669"];
  const inr = (v) => "₹ " + Number(v || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });

  Chart.defaults.font.family = "Inter, 'Segoe UI', system-ui, sans-serif";
  Chart.defaults.color = "#64748b";
  Chart.defaults.plugins.tooltip.callbacks.label = (ctx) => {
    const value = ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed;
    return ` ${ctx.dataset.label || ctx.label}: ${inr(value)}`;
  };

  function data(id) {
    const el = document.getElementById(id);
    return el ? JSON.parse(el.textContent) : null;
  }
  function empty(canvas, message) {
    const box = canvas.parentElement;
    box.innerHTML = `<div class="flex h-full min-h-[200px] items-center justify-center text-sm text-slate-400">${message}</div>`;
  }
  const moneyAxis = { beginAtZero: true, ticks: { callback: (v) => inr(v) }, grid: { color: "#eef2f7" } };

  const trendCanvas = document.getElementById("trendChart");
  const trend = data("trend-data");
  if (trendCanvas && trend) {
    const total = [...trend.income, ...trend.expense, ...trend.received].reduce((a, b) => a + b, 0);
    if (!total) empty(trendCanvas, "No approved transactions in this period yet.");
    else new Chart(trendCanvas, {
      data: {
        labels: trend.labels,
        datasets: [
          { type: "bar", label: "Income billed", data: trend.income, backgroundColor: NAVY, borderRadius: 6, maxBarThickness: 28 },
          { type: "bar", label: "Expenses", data: trend.expense, backgroundColor: ORANGE, borderRadius: 6, maxBarThickness: 28 },
          { type: "line", label: "Payments received", data: trend.received, borderColor: GREEN, backgroundColor: GREEN, tension: 0.35, pointRadius: 3 },
        ],
      },
      options: { maintainAspectRatio: false, interaction: { mode: "index", intersect: false },
        plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 8 } } },
        scales: { y: moneyAxis, x: { grid: { display: false } } } },
    });
  }

  const expCanvas = document.getElementById("expenseChart");
  const exp = data("expense-data");
  if (expCanvas && exp) {
    if (!exp.values.length) empty(expCanvas, "No approved expenses in this period.");
    else new Chart(expCanvas, {
      type: "doughnut",
      data: { labels: exp.labels, datasets: [{ data: exp.values, backgroundColor: PALETTE, borderWidth: 2, borderColor: "#fff" }] },
      options: { maintainAspectRatio: false, cutout: "65%",
        plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 8 } } } },
    });
  }

  const catCanvas = document.getElementById("categoryChart");
  const cat = data("category-data");
  if (catCanvas && cat) {
    if (![...cat.income, ...cat.expense].some(Boolean)) empty(catCanvas, "No JCB or tractor transactions in this period.");
    else new Chart(catCanvas, {
      type: "bar",
      data: { labels: cat.labels, datasets: [
        { label: "Income", data: cat.income, backgroundColor: NAVY, borderRadius: 6, maxBarThickness: 40 },
        { label: "Expenses", data: cat.expense, backgroundColor: ORANGE, borderRadius: 6, maxBarThickness: 40 },
      ] },
      options: { maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 8 } } },
        scales: { y: moneyAxis, x: { grid: { display: false } } } },
    });
  }

  const vCanvas = document.getElementById("vehicleChart");
  const vdata = data("vehicle-data");
  if (vCanvas && vdata) {
    if (!vdata.labels.length) empty(vCanvas, "No vehicle activity in this period.");
    else new Chart(vCanvas, {
      type: "bar",
      data: { labels: vdata.labels, datasets: [
        { label: "Income", data: vdata.income, backgroundColor: NAVY, borderRadius: 4 },
        { label: "Expenses", data: vdata.expense, backgroundColor: ORANGE, borderRadius: 4 },
        { label: "Profit", data: vdata.profit, backgroundColor: GREEN, borderRadius: 4 },
      ] },
      options: { maintainAspectRatio: false, indexAxis: vdata.labels.length > 6 ? "y" : "x",
        plugins: { legend: { position: "bottom", labels: { usePointStyle: true, boxWidth: 8 } } },
        scales: vdata.labels.length > 6 ? { x: moneyAxis } : { y: moneyAxis } },
    });
  }
})();
