/* Madhusudhan Earthmover ERP — progressive enhancement only.
 * Every form still validates on the server; this script improves usability. */
(function () {
  "use strict";

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function icons() {
    if (window.lucide) window.lucide.createIcons();
  }

  /* ---------- Sidebar (mobile drawer) ---------- */
  function setSidebar(open) {
    const sidebar = $("#sidebar");
    const backdrop = $("#sidebar-backdrop");
    if (!sidebar) return;
    sidebar.classList.toggle("-translate-x-full", !open);
    if (backdrop) backdrop.classList.toggle("hidden", !open);
    document.body.classList.toggle("overflow-hidden", open);
  }
  document.addEventListener("click", (e) => {
    if (e.target.closest("[data-sidebar-open]")) setSidebar(true);
    else if (e.target.closest("[data-sidebar-close]")) setSidebar(false);
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      setSidebar(false);
      $$("[data-dropdown-menu]").forEach((m) => m.classList.add("hidden"));
    }
  });

  /* ---------- Dropdowns ---------- */
  document.addEventListener("click", (e) => {
    const toggle = e.target.closest("[data-dropdown-toggle]");
    $$("[data-dropdown-menu]").forEach((menu) => {
      if (!toggle || !menu.closest("[data-dropdown]").contains(toggle)) menu.classList.add("hidden");
    });
    if (toggle) {
      const menu = $("[data-dropdown-menu]", toggle.closest("[data-dropdown]"));
      menu.classList.toggle("hidden");
    }
  });

  /* ---------- Dismissible alerts ---------- */
  document.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-dismiss]");
    if (btn) btn.closest("[role=alert]").remove();
  });

  /* ---------- Confirmation for destructive actions ---------- */
  document.addEventListener("submit", (e) => {
    const form = e.target;
    const msg = form.getAttribute("data-confirm") || (e.submitter && e.submitter.getAttribute("data-confirm"));
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  /* ---------- Print ---------- */
  document.addEventListener("click", (e) => {
    if (e.target.closest("[data-print]")) window.print();
  });

  /* ---------- Conditional sections: data-show-when / data-show-values ---------- */
  function applyShowWhen() {
    $$("[data-show-when]").forEach((el) => {
      const source = $(el.getAttribute("data-show-when"));
      if (!source) return;
      const values = el.getAttribute("data-show-values").split(",");
      const visible = values.includes(source.value);
      el.classList.toggle("hidden", !visible);
    });
  }
  document.addEventListener("change", applyShowWhen);

  /* ---------- Period filter: custom range fields ---------- */
  function applyPeriod() {
    $$("[data-period-select]").forEach((sel) => {
      const custom = $$("[data-period-custom]", sel.form);
      custom.forEach((el) => el.classList.toggle("hidden", sel.value !== "custom"));
    });
  }
  document.addEventListener("change", (e) => {
    if (e.target.matches("[data-period-select]")) {
      applyPeriod();
      if (e.target.value !== "custom" && e.target.hasAttribute("data-autosubmit")) e.target.form.submit();
    }
  });

  /* ---------- Category-first vehicle selection ---------- */
  async function loadVehicles(categorySelect, keepValue) {
    const target = $(categorySelect.getAttribute("data-vehicle-target"));
    if (!target) return;
    const category = categorySelect.value;
    const current = keepValue ? target.value : "";
    const placeholder = target.getAttribute("data-placeholder") || "Select vehicle";
    if (!category) {
      target.innerHTML = `<option value="">Select a vehicle category first</option>`;
      target.disabled = !target.hasAttribute("data-allow-empty-category");
      if (target.hasAttribute("data-allow-empty-category")) {
        target.innerHTML = `<option value="">— No vehicle (general) —</option>`;
      }
      target.dispatchEvent(new Event("vehicles:loaded"));
      return;
    }
    target.disabled = true;
    target.innerHTML = `<option value="">Loading…</option>`;
    try {
      const url = `${categorySelect.getAttribute("data-vehicle-url")}?category=${encodeURIComponent(category)}`;
      const res = await fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" });
      if (!res.ok) throw new Error(res.statusText);
      const data = await res.json();
      const opts = [`<option value="">${data.vehicles.length ? placeholder : "No active vehicles in this category"}</option>`];
      data.vehicles.forEach((v) => {
        const sel = String(v.id) === current ? " selected" : "";
        opts.push(`<option value="${v.id}" data-hourly-rate="${v.hourly_rate}" data-daily-rate="${v.daily_rate}"${sel}>${escapeHtml(v.label)}</option>`);
      });
      target.innerHTML = opts.join("");
      target.disabled = false;
    } catch (err) {
      target.innerHTML = `<option value="">Could not load vehicles — reload the page</option>`;
    }
    target.dispatchEvent(new Event("vehicles:loaded"));
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  }
  document.addEventListener("change", (e) => {
    if (e.target.matches("[data-vehicle-category]")) loadVehicles(e.target, false);
  });

  /* ---------- Income amount calculation ---------- */
  function initIncomeForm(form) {
    const billing = $("#id_billing_type", form);
    const qty = $("#id_quantity", form);
    const rate = $("#id_rate", form);
    const amount = $("#id_amount", form);
    const vehicle = $("#id_vehicle", form);
    const preview = $("[data-amount-preview]", form);
    const received = $("#id_received_now", form);
    const balance = $("[data-balance-preview]", form);
    if (!billing || !amount) return;
    let rateTouched = !!(rate && rate.value);
    if (rate) rate.addEventListener("input", () => { rateTouched = true; });

    const fmt = (n) => "₹ " + Number(n || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function fillRate() {
      if (!vehicle || !rate || rateTouched) return;
      const opt = vehicle.selectedOptions[0];
      if (!opt) return;
      const value = billing.value === "HOURLY" ? opt.dataset.hourlyRate : billing.value === "DAILY" ? opt.dataset.dailyRate : "";
      if (value && Number(value) > 0) rate.value = value;
    }
    function recalc() {
      const quantityBased = billing.value === "HOURLY" || billing.value === "DAILY";
      amount.readOnly = quantityBased;
      amount.classList.toggle("bg-slate-50", quantityBased);
      if (quantityBased) {
        const q = parseFloat(qty && qty.value) || 0;
        const r = parseFloat(rate && rate.value) || 0;
        if (billing.value === "HOURLY") {
          // hours.minutes: 1.30 = 1 hr 30 min; same rounding as Money::multiplyHours
          const hundredths = Math.round(q * 100);
          const minutes = Math.floor(hundredths / 100) * 60 + (hundredths % 100);
          amount.value = minutes && r && hundredths % 100 < 60 ? (Math.round(minutes * Math.round(r * 100) / 60) / 100).toFixed(2) : "";
        } else {
          amount.value = q && r ? (Math.round(q * r * 100) / 100).toFixed(2) : "";
        }
      }
      if (preview) preview.textContent = fmt(amount.value);
      if (balance && received) {
        const due = (parseFloat(amount.value) || 0) - (parseFloat(received.value) || 0);
        balance.textContent = fmt(due);
        balance.classList.toggle("text-red-600", due < 0);
      }
    }
    [billing, qty, rate, amount, received].forEach((el) => el && el.addEventListener("input", recalc));
    billing.addEventListener("change", () => { rateTouched = false; fillRate(); recalc(); });
    if (vehicle) {
      vehicle.addEventListener("change", () => { rateTouched = false; fillRate(); recalc(); });
      vehicle.addEventListener("vehicles:loaded", () => { fillRate(); recalc(); });
    }
    recalc();
  }

  /* ---------- Copy a read-only value (works without HTTPS too) ---------- */
  document.addEventListener("click", (e) => {
    const button = e.target.closest("[data-copy]");
    if (!button) return;
    const input = $(button.dataset.copy);
    if (!input) return;
    input.select();
    const label = $("[data-copy-label]", button);
    const done = () => {
      if (!label) return;
      label.textContent = "Copied";
      setTimeout(() => { label.textContent = "Copy"; }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done);
    } else if (document.execCommand("copy")) {
      done();
    }
  });

  /* ---------- Driver attendance sheet ---------- */
  function initAttendanceSheet(form) {
    $$("[data-attendance-row]", form).forEach((row) => {
      const status = $("[data-attendance-status]", row);
      const times = $$("[data-attendance-time]", row);
      // Absent or on leave: no punch times. Disabled inputs are not submitted.
      function sync() {
        const off = status.value === "ABSENT" || status.value === "LEAVE";
        times.forEach((t) => {
          t.disabled = off;
          if (off) t.value = "";
        });
      }
      status.addEventListener("change", sync);
      times.forEach((t) => t.addEventListener("input", () => {
        if (!status.value && t.value) status.value = "PRESENT";
      }));
      sync();
    });
  }

  /* ---------- Payment form: open invoices for the selected customer ---------- */
  function initPaymentForm(form) {
    const customer = $("#id_customer", form);
    const income = $("#id_income", form);
    const hint = $("[data-due-hint]", form);
    const amount = $("#id_amount", form);
    if (!customer || !income) return;
    const url = income.getAttribute("data-invoices-url");

    function showDue() {
      const opt = income.selectedOptions[0];
      if (!hint) return;
      if (opt && opt.dataset.due) {
        hint.textContent = `Balance due on this invoice: ₹ ${Number(opt.dataset.due).toLocaleString("en-IN", { minimumFractionDigits: 2 })}`;
        if (amount) amount.max = opt.dataset.due;
      } else {
        hint.textContent = "No invoice selected — this will be recorded as an advance / on-account payment.";
        if (amount) amount.removeAttribute("max");
      }
    }
    async function load(keep) {
      const current = keep ? income.value : "";
      if (!customer.value) {
        income.innerHTML = `<option value="">Select a customer first</option>`;
        showDue();
        return;
      }
      income.innerHTML = `<option value="">Loading…</option>`;
      try {
        const extra = income.getAttribute("data-include") ? `&include=${income.getAttribute("data-include")}` : "";
        const res = await fetch(`${url}?customer=${customer.value}${extra}`, { credentials: "same-origin" });
        const data = await res.json();
        const opts = [`<option value="">— Advance / on-account (no invoice) —</option>`];
        data.invoices.forEach((inv) => {
          const sel = String(inv.id) === current ? " selected" : "";
          opts.push(`<option value="${inv.id}" data-due="${inv.due}"${sel}>${escapeHtml(inv.label)}</option>`);
        });
        income.innerHTML = opts.join("");
      } catch (err) {
        income.innerHTML = `<option value="">Could not load invoices</option>`;
      }
      showDue();
    }
    customer.addEventListener("change", () => load(false));
    income.addEventListener("change", showDue);
    showDue();
  }

  /* ---------- Select all checkboxes ---------- */
  document.addEventListener("change", (e) => {
    if (e.target.matches("[data-check-all]")) {
      const group = e.target.getAttribute("data-check-all");
      $$(`input[type=checkbox][data-check-group="${group}"]`).forEach((cb) => { cb.checked = e.target.checked; });
    }
  });

  document.addEventListener("DOMContentLoaded", () => {
    icons();
    applyShowWhen();
    applyPeriod();
    $$("[data-vehicle-category]").forEach((sel) => {
      const target = $(sel.getAttribute("data-vehicle-target"));
      // Server renders the correct options already; only lock the field when no category chosen.
      if (target && !sel.value && !target.hasAttribute("data-allow-empty-category")) target.disabled = true;
    });
    $$("form[data-income-form]").forEach(initIncomeForm);
    $$("form[data-payment-form]").forEach(initPaymentForm);
    $$("form[data-attendance-sheet]").forEach(initAttendanceSheet);
  });

  window.ERP = { icons };
})();
