<?php $title = 'Officer Dashboard'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="mark"></span><span>LOCIFY</span></div>
    <nav class="nav" id="nav">
      <div class="section-label">Operations</div>
      <a href="#" data-view="overview">Overview / አጠቃላይ</a>
      <a href="#" data-view="queue">Queue / ወረፋ</a>
      <a href="#" data-view="applications">Applications / ማመልከቻዎች</a>
      <a href="#" data-view="citizens">Citizens / ዜጎች</a>
      <a href="#" data-view="households">Households / ቤተሰብ</a>
      <a href="#" data-view="documents">Documents / ሰነዶች</a>
      <a href="#" data-view="payments">Payments / ክፍያዎች</a>
      <div class="section-label">Oversight</div>
      <a href="#" data-view="reports">Reports / ሪፖርት</a>
      <a href="#" data-view="complaints">Complaints / ቅሬታዎች</a>
      <a href="#" data-view="messages">Messages / መልዕክቶች <span id="chat-badge" class="nav-badge" hidden></span></a>
      <a href="#" data-view="audit">Audit Log / ኦዲት</a>
      <a href="#" id="change-password-link">Change Password / የይለፍ ቃል</a>
      <a href="#" id="logout-link">Logout / ውጣ</a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1 id="page-title">Overview</h1>
      <span class="user-chip" id="user-chip">Loading…</span>
    </div>
    <div id="alert"></div>
    <div id="content"></div>
  </main>
</div>

<script src="/assets/js/api.js"></script>
<script>
const content = document.getElementById("content");
const alertBox = document.getElementById("alert");
const pageTitle = document.getElementById("page-title");
const views = document.querySelectorAll("#nav a[data-view]");

const TITLES = {
  overview: "Overview", queue: "Queue Management", applications: "Applications",
  citizens: "Citizen Records", households: "Households & Community", documents: "Documents",
  payments: "Payments", reports: "Reports", complaints: "Complaints",
  messages: "Messages", audit: "Audit Log",
};

// Holds the uuid to flash-highlight after a create (post-create navigation).
let pendingHighlight = null;
let queueTimer = null;

function guard() {
  if (!API.isLoggedIn()) window.location.href = "/login";
}

function stopQueuePoll() {
  if (queueTimer) { clearInterval(queueTimer); queueTimer = null; }
}

function applyHighlight(container) {
  if (!pendingHighlight) return;
  const row = container.querySelector("[data-uuid=\"" + pendingHighlight + "\"]");
  pendingHighlight = null;
  if (row) {
    row.scrollIntoView({ block: "center", behavior: "smooth" });
    row.classList.add("flash-row");
    setTimeout(() => row.classList.remove("flash-row"), 3000);
  }
}

// ---------------- Overview / KPIs ----------------
function overviewTiles(dash) {
  return [
    ["Applications total", dash.applications_total],
    ["In review", dash.applications_in_review],
    ["Citizens", dash.citizens_total],
    ["Pending verification", dash.citizens_pending],
    ["Documents", dash.documents_total],
    ["Issued documents", dash.documents_issued],
    ["Payments today", dash.payments_today],
    ["Revenue (ETB)", Number(dash.payments_revenue_total).toLocaleString()],
    ["Open complaints", dash.complaints_open],
    ["Queue waiting", dash.tickets_waiting],
  ];
}

function renderOverview(dash) {
  const grid = el("div", { class: "grid grid-3 mt-1" });
  for (const [label, value] of overviewTiles(dash)) {
    grid.append(el("div", { class: "stat stat-kpi" }, [
      el("div", { class: "value", text: String(value) }),
      el("div", { class: "label", text: label }),
    ]));
  }
  content.append(el("h2", { text: "Key performance indicators" }), grid);
  if (dash.by_unit && dash.by_unit.length) {
    const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Kebele" }), el("th", { text: "Applications" }), el("th", { text: "In review" }),
      el("th", { text: "Citizens" }), el("th", { text: "Issued docs" }), el("th", { text: "Complaints" }),
      el("th", { text: "Queue" }),
    ])])]);
    const tbody = el("tbody");
    for (const u of dash.by_unit) {
      tbody.append(el("tr", {}, [
        el("td", { text: u.name }),
        el("td", { text: u.applications }),
        el("td", { text: u.in_review }),
        el("td", { text: u.citizens }),
        el("td", { text: u.documents_issued }),
        el("td", { text: u.complaints }),
        el("td", { text: u.tickets_waiting }),
      ]));
    }
    table.append(tbody);
    content.append(table);
  }
  content.append(el("p", { class: "muted mt-2", text: "Data updates on every visit. Refresh the page to see the latest counts." }));
}

async function loadOverview() {
  content.innerHTML = "";
  const unitSel = el("select", { id: "unit-filter" }, [
    el("option", { value: "", text: "All kebeles" }),
  ]);
  let dash;
  try {
    dash = await API.get("/reports/dashboard");
    for (const u of dash.by_unit || []) {
      unitSel.append(el("option", { value: u.id, text: u.name }));
    }
  } catch (err) { showAlert(alertBox, err.message); return; }
  unitSel.addEventListener("change", async () => {
    const q = unitSel.value ? "?unit=" + encodeURIComponent(unitSel.value) : "";
    try {
      const filtered = await API.get("/reports/dashboard" + q);
      content.innerHTML = "";
      content.append(el("div", { class: "row mt-1" }, [unitSel]));
      renderOverview(filtered);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(el("div", { class: "row mt-1" }, [unitSel]));
  renderOverview(dash);
}

// ---------------- Queue board ----------------
async function loadQueue(autopoll = false) {
  if (!autopoll) { content.innerHTML = ""; }
  const offices = await API.get("/admin/offices");
  const office = offices.offices[0];
  if (!office) { content.append(el("p", { class: "muted", text: "No office configured." })); return; }
  const [status, board] = await Promise.all([
    API.get("/queue/status?office_id=" + office.id),
    API.get("/queue/board?office_id=" + office.id),
  ]);
  if (autopoll) {
    const container = document.getElementById("queue-board");
    if (!container) return;
    container.replaceChildren();
    renderQueueBoard(container, office, status, board);
    return;
  }
  content.append(el("h2", { text: "Office: " + office.name }));
  const container = el("div", { id: "queue-board" });
  renderQueueBoard(container, office, status, board);
  content.append(container);
  stopQueuePoll();
  queueTimer = setInterval(() => loadQueue(true), 6000);
}

function renderQueueBoard(container, office, status, board) {
  const big = el("div", { class: "grid grid-3 mt-1" }, [
    el("div", { class: "stat stat-now" }, [
      el("div", { class: "value", text: status.now_serving ?? "—" }),
      el("div", { class: "label", text: "Now serving / አሁን ተጠርቷል" }),
    ]),
    el("div", { class: "stat stat-next" }, [
      el("div", { class: "value", text: status.next_ticket ?? "—" }),
      el("div", { class: "label", text: "Next ticket / ቀጣይ" }),
    ]),
    el("div", { class: "stat" }, [
      el("div", { class: "value", text: status.waiting }),
      el("div", { class: "label", text: "Waiting / በመጠባበቅ ላይ" }),
    ]),
  ]);
  container.append(big);

  const actions = el("div", { class: "row mt-2" }, [
    el("button", { class: "btn btn-gold btn-lg", text: "Issue ticket", onclick: async () => {
      try {
        const t = await API.post("/queue/tickets", { office_id: office.id });
        showAlert(alertBox, "Ticket #" + t.ticket_number + " issued", "success");
        loadQueue();
      } catch (err) { showAlert(alertBox, err.message); }
    }}),
    el("button", { class: "btn btn-lg", text: "Call next", onclick: async () => {
      try {
        const t = await API.post("/queue/next", { office_id: office.id });
        showAlert(alertBox, "Now serving ticket #" + t.ticket_number, "success");
        loadQueue();
      } catch (err) { showAlert(alertBox, err.message); }
    }}),
  ]);
  container.append(actions);

  if (!board.tickets.length) {
    container.append(el("p", { class: "muted mt-2", text: "No tickets waiting or being served right now." }));
    return;
  }
  container.append(el("h3", { class: "mt-2", text: "Waiting list" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Ticket" }), el("th", { text: "Priority" }), el("th", { text: "Status" }), el("th", { text: "Issued" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const t of board.tickets) {
    const actionsCell = el("td");
    if (t.status === "called") {
      actionsCell.append(
        el("button", { class: "btn btn-sm", text: "Complete", onclick: async () => {
          try { await API.post("/queue/tickets/" + t.id, { action: "complete" }); showAlert(alertBox, "Ticket completed", "success"); loadQueue(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}),
        el("button", { class: "btn btn-sm btn-danger", text: "No-show", onclick: async () => {
          try { await API.post("/queue/tickets/" + t.id, { action: "no_show" }); showAlert(alertBox, "Marked no-show", "success"); loadQueue(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}),
      );
    }
    tbody.append(el("tr", {}, [
      el("td", { class: "mono", text: "#" + t.ticket_number }),
      el("td", {}, [badge(t.priority)]),
      el("td", {}, [badge(t.status)]),
      el("td", { class: "muted", text: fmtDate(t.created_at) }),
      actionsCell,
    ]));
  }
  table.append(tbody);
  container.append(table);
}

// ---------------- Applications ----------------
async function loadApplications() {
  const data = await API.get("/services/applications");
  content.innerHTML = "";
  content.append(el("h2", { text: "Applications" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Number" }), el("th", { text: "Service" }), el("th", { text: "Status" }),
    el("th", { text: "Step" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const a of data.applications) {
    tbody.append(el("tr", { "data-uuid": a.uuid }, [
      el("td", { class: "mono", text: a.application_number }),
      el("td", { text: a.service_name }),
      el("td", {}, [badge(a.status)]),
      el("td", { class: "muted", text: a.current_step || "—" }),
      el("td", {}, [
        el("button", { class: "btn btn-sm", text: "View", onclick: () => showApplication(a) }),
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

async function showApplication(app) {
  stopQueuePoll();
  content.innerHTML = "";
  content.append(el("h2", { text: app.application_number + " — " + app.service_name }));
  const detail = await API.get("/services/applications/" + app.uuid);
  content.append(el("div", { class: "card mt-1" }, [
    el("div", { class: "row spread" }, [
      el("strong", { text: detail.service_name }),
      el("span", {}, [badge(detail.status)]),
    ]),
    el("p", { class: "muted mt-1", text: "Submitted: " + fmtDate(detail.submitted_at) + " · Current step: " + (detail.current_step || "—") }),
  ]));
  content.append(el("h3", { class: "mt-2", text: "Workflow steps" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Step" }), el("th", { text: "Status" }), el("th", { text: "Started" }), el("th", { text: "Comments" }),
  ])])]);
  const tbody = el("tbody");
  for (const s of detail.steps || []) {
    tbody.append(el("tr", {}, [
      el("td", { class: "mono", text: s.step_id }),
      el("td", {}, [badge(s.status)]),
      el("td", { class: "muted", text: fmtDate(s.started_at) }),
      el("td", { class: "muted", text: s.comments || "—" }),
    ]));
  }
  table.append(tbody);
  content.append(table);
  const actions = el("div", { class: "row mt-2" });
  const approveBtn = el("button", { class: "btn btn-sm", text: "Approve", onclick: async () => {
    try { await API.put("/services/applications/" + app.uuid + "/step", { action: "approve" }); showAlert(alertBox, "Step approved", "success"); showApplication(app); }
    catch (err) { showAlert(alertBox, err.message); }
  }});
  const rejectBtn = el("button", { class: "btn btn-sm btn-danger", text: "Reject", onclick: async () => {
    try { await API.put("/services/applications/" + app.uuid + "/step", { action: "reject" }); showAlert(alertBox, "Application rejected", "success"); loadApplications(); }
    catch (err) { showAlert(alertBox, err.message); }
  }});
  actions.append(approveBtn, rejectBtn,
    el("button", {
      class: "btn btn-gold", text: "Create document",
      onclick: async () => {
        try {
          const d = await API.post("/documents", { application_uuid: app.uuid, document_type: "certificate", title: app.service_name + " certificate" });
          pendingHighlight = d.uuid;
          showAlert(alertBox, "Draft " + d.document_number + " created. Opening Documents view…", "success");
          switchView("documents");
        } catch (err) { showAlert(alertBox, err.message); }
      },
    }),
    el("button", { class: "btn btn-ghost", text: "Back", onclick: loadApplications }));
  content.append(actions);
}

// ---------------- Citizens ----------------
async function loadCitizens() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Citizen search" }));
  const form = el("form", { class: "row" }, [
    el("input", { name: "q", placeholder: "National ID or phone number", style: "max-width:320px" }),
    el("button", { class: "btn", type: "submit", text: "Search" }),
  ]);
  const results = el("div", { class: "mt-2" });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const q = form.elements.q.value.trim();
    results.innerHTML = "";
    try {
      const data = await API.get("/citizens/search?national_id=" + encodeURIComponent(q) + "&phone=" + encodeURIComponent(q));
      if (!data.count) { results.append(el("p", { class: "muted", text: "No citizen found." })); return; }
      const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
        el("th", { text: "Name" }), el("th", { text: "NID" }), el("th", { text: "Status" }), el("th", { text: "DOB (E.C.)" }), el("th", { text: "Actions" }),
      ])])]);
      const tbody = el("tbody");
      for (const c of data.results) {
        const actions = [];
        if (c.status === "pending_verification") {
          actions.push(el("button", { class: "btn btn-sm", text: "Verify", onclick: async () => {
            try { await API.post("/citizens/" + c.uuid + "/verify"); await API.post("/citizens/" + c.uuid + "/verify-approve", { approved: true }); showAlert(alertBox, "Citizen verified", "success"); loadCitizens(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}));
        }
        actions.push(el("button", { class: "btn btn-sm btn-ghost", text: "Edit", onclick: () => showCitizenEdit(c) }));
        tbody.append(el("tr", { "data-uuid": c.uuid }, [
          el("td", { text: c.name }), el("td", { class: "mono", text: c.national_id_mask || "—" }),
          el("td", {}, [badge(c.status)]), el("td", { class: "muted", text: c.dob_eth || "—" }),
          el("td", {}, actions),
        ]));
      }
      table.append(tbody);
      results.append(table);
      applyHighlight(results);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form, results);

  content.append(el("h2", { class: "mt-2", text: "Register citizen" }));
  const regForm = el("form", {}, [
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "First name" }), el("input", { name: "first_name", required: true }),
        el("label", { text: "Middle name" }), el("input", { name: "middle_name" }),
        el("label", { text: "Last name" }), el("input", { name: "last_name", required: true }),
        el("label", { text: "Name (local script)" }), el("input", { name: "local_name" }),
      ]),
      el("div", {}, [
        el("label", { text: "Date of birth (E.C., YYYY-MM-DD)" }), el("input", { name: "dob_eth", placeholder: "2010-01-01" }),
        el("label", { text: "Sex" }), el("select", { name: "sex" }, ["M", "F", "O"].map(s => el("option", { value: s, text: s }))),
        el("label", { text: "Phone (+251…)" }), el("input", { name: "phone", placeholder: "+251911234567" }),
        el("label", { text: "National ID" }), el("input", { name: "national_id" }),
        el("label", { text: "Address" }), el("input", { name: "address", placeholder: "Village / house no" }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Create record" }),
  ]);
  regForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/citizens", {
        first_name: regForm.elements.first_name.value,
        middle_name: regForm.elements.middle_name.value,
        last_name: regForm.elements.last_name.value,
        local_name: regForm.elements.local_name.value,
        dob_eth: regForm.elements.dob_eth.value,
        sex: regForm.elements.sex.value,
        phone: regForm.elements.phone.value,
        national_id: regForm.elements.national_id.value,
        address: { village: regForm.elements.address.value },
      });
      pendingHighlight = created.uuid;
      showAlert(alertBox, "Citizen record created. Showing record…", "success");
      if (created.uuid) {
        form.elements.q.value = regForm.elements.national_id.value;
        form.dispatchEvent(new Event("submit"));
        regForm.reset();
      } else {
        regForm.reset();
      }
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(regForm);
}

function showCitizenEdit(citizen) {
  stopQueuePoll();
  content.innerHTML = "";
  content.append(el("h2", { text: "Edit citizen — " + citizen.name }));
  const form = el("form", {}, [
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "First name" }), el("input", { name: "first_name", value: citizen.first_name || "" }),
        el("label", { text: "Middle name" }), el("input", { name: "middle_name", value: citizen.middle_name || "" }),
        el("label", { text: "Last name" }), el("input", { name: "last_name", value: citizen.last_name || "" }),
      ]),
      el("div", {}, [
        el("label", { text: "Sex" }), el("select", { name: "sex" }, ["M", "F", "O"].map(s => el("option", { value: s, text: s, selected: citizen.sex === s }))),
        el("label", { text: "Phone (+251…)" }), el("input", { name: "phone", value: citizen.phone_masked || "" }),
        el("label", { text: "Status" }), el("select", { name: "status" }, ["active", "inactive", "archived"].map(s => el("option", { value: s, text: s, selected: citizen.status === s }))),
      ]),
    ]),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Save changes" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadCitizens }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.put("/citizens/" + citizen.uuid, {
        first_name: form.elements.first_name.value || null,
        middle_name: form.elements.middle_name.value || null,
        last_name: form.elements.last_name.value || null,
        sex: form.elements.sex.value,
        phone: form.elements.phone.value || null,
        status: form.elements.status.value,
      });
      showAlert(alertBox, "Citizen record updated", "success");
      loadCitizens();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

// ---------------- Households & Community ----------------
async function loadHouseholds() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Households & community records" }));
  content.append(el("p", { class: "muted", text: "Search a citizen to view or edit their household (spouse, children, head of household, guardians)." }));

  const form = el("form", {}, [
    el("label", { text: "National ID or phone" }),
    el("div", { class: "row" }, [
      el("input", { name: "q", placeholder: "e.g. NP-2026-12345 / +2519…", required: true }),
      el("button", { class: "btn", type: "submit", text: "Find citizen" }),
    ]),
  ]);
  const results = el("div", { class: "mt-2" });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    results.innerHTML = "";
    try {
      const data = await API.get("/citizens/search?national_id=" + encodeURIComponent(form.elements.q.value) +
        "&phone=" + encodeURIComponent(form.elements.q.value));
      const list = data.results || [];
      if (list.length === 0) {
        results.append(el("p", { class: "muted", text: "No citizen found. Register them under Citizens first." }));
        return;
      }
      const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
        el("th", { text: "Name" }), el("th", { text: "National ID" }), el("th", { text: "Family size" }), el("th", {}),
      ])])]);
      const tbody = el("tbody");
      for (const c of list) {
        let familyCount = 0;
        try {
          const rel = await API.get("/citizens/" + c.uuid + "/relationships");
          familyCount = (rel.relationships || []).length;
        } catch (_) { /* permission or scope */ }
        const openBtn = el("button", { class: "btn btn-sm", text: "Open household", onclick: () => showHousehold(c) });
        tbody.append(el("tr", {}, [
          el("td", { text: c.name }), el("td", { class: "mono", text: c.national_id_mask || "—" }),
          el("td", { text: String(familyCount) }), el("td", {}, [openBtn]),
        ]));
      }
      table.append(tbody);
      results.append(table);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form, results);
}

async function showHousehold(citizen) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Household — " + citizen.name }));
  const panel = el("div", { class: "mt-2" });
  const relLink = el("a", { href: "#", class: "muted", text: "Back to households", onclick: (ev) => { ev.preventDefault(); loadHouseholds(); } });
  content.append(relLink, panel);
  await renderHousehold(citizen, panel);
}

async function renderHousehold(citizen, panel) {
  panel.innerHTML = "";
  let data;
  try {
    data = await API.get("/citizens/" + citizen.uuid + "/relationships");
  } catch (err) { panel.append(el("p", { class: "muted", text: "Family view unavailable: " + err.message })); return; }

  if (!data.relationships.length) {
    panel.append(el("p", { class: "muted", text: "No household members linked yet." }));
  } else {
    const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Member" }), el("th", { text: "Relation" }), el("th", { text: "Verified" }), el("th", {}),
    ])])]);
    const tbody = el("tbody");
    for (const r of data.relationships) {
      const delBtn = el("button", { class: "btn btn-sm btn-danger", text: "Unlink", onclick: async () => {
        try {
          await API.del("/citizens/" + citizen.uuid + "/relationships/" + r.id);
          renderHousehold(citizen, panel);
        } catch (err) { showAlert(alertBox, err.message); }
      } });
      tbody.append(el("tr", {}, [
        el("td", { text: (r.related_citizen && r.related_citizen.name) || "—" }),
        el("td", { text: r.relation_type }), el("td", { text: r.verified ? "✓" : "—" }),
        el("td", {}, [delBtn]),
      ]));
    }
    table.append(tbody);
    panel.append(table);
  }

  panel.append(el("h3", { class: "mt-3", text: "Link household member" }));
  const form = el("form", {}, [
    el("label", { text: "Related citizen (national ID or phone)" }),
    el("input", { name: "q", placeholder: "e.g. NP-2026-12345", required: true }),
    el("div", { id: "rel-candidate" }),
    el("label", { class: "mt-2", text: "Relationship" }),
    el("select", { name: "relation_type" },
      ["spouse", "parent", "child", "sibling", "household_head", "guardian", "other"].map(t =>
        el("option", { value: t, text: t.replace(/_/g, " ") }))),
    el("label", { class: "row", text: "Verified" , style: "gap:.5rem"},
      el("input", { name: "verified", type: "checkbox" })),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Link member" }),
  ]);
  let selected = null;
  form.elements.q.addEventListener("input", async () => {
    const box = document.getElementById("rel-candidate");
    box.innerHTML = "";
    if (form.elements.q.value.length < 3) return;
    try {
      const data = await API.get("/citizens/search?national_id=" + encodeURIComponent(form.elements.q.value) +
        "&phone=" + encodeURIComponent(form.elements.q.value));
      for (const c of (data.results || []).slice(0, 5)) {
        box.append(el("button", { class: "btn btn-sm btn-ghost", type: "button",
          text: c.name + " (" + (c.national_id_mask || c.uuid.slice(0, 8)) + ")",
          onclick: () => { selected = c; box.innerHTML = ""; box.append(el("span", { class: "badge", text: "Selected: " + c.name })); } }));
      }
    } catch (_) { /* ignore */ }
  });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!selected) {
      const box = document.getElementById("rel-candidate");
      box.append(el("p", { class: "text-danger", text: "Pick a candidate from the list first" }));
      return;
    }
    try {
      await API.post("/citizens/" + citizen.uuid + "/relationships", {
        related_citizen_uuid: selected.uuid,
        relation_type: form.elements.relation_type.value,
        verified: form.elements.verified.checked,
      });
      renderHousehold(citizen, panel);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  panel.append(form);
}

// ---------------- Documents ----------------
async function loadDocuments() {
  const apps = await API.get("/services/applications");
  const data = await API.get("/documents/office");
  content.innerHTML = "";
  content.append(el("h2", { text: "Document workflow" }));
  content.append(el("p", { class: "muted", text: "Create a draft document from an application, then sign, issue or revoke." }));

  const form = el("form", { class: "card mt-1" }, [
    el("h3", { text: "Create document from application" }),
    el("label", { text: "Application" }),
    el("select", { name: "application_uuid" }, (apps.applications || []).map(a => el("option", { value: a.uuid, text: a.application_number + " — " + a.service_name }))),
    el("label", { text: "Document type" }),
    el("input", { name: "document_type", value: "certificate", required: true }),
    el("label", { text: "Title" }), el("input", { name: "title", value: "Official certificate" }),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Create draft" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const d = await API.post("/documents", {
        application_uuid: form.elements.application_uuid.value,
        document_type: form.elements.document_type.value,
        title: form.elements.title.value,
      });
      pendingHighlight = d.uuid;
      showAlert(alertBox, "Draft " + d.document_number + " created — listed below.", "success");
      loadDocuments();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  if (data.documents.length) {
    content.append(el("h3", { class: "mt-2", text: "Documents in this office" }));
    const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Number" }), el("th", { text: "Type" }), el("th", { text: "Status" }), el("th", { text: "Created" }), el("th", { text: "Actions" }),
    ])])]);
    const tbody = el("tbody");
    for (const d of data.documents) {
      const actions = [];
      if (d.status === "draft") {
        actions.push(el("button", { class: "btn btn-sm", text: "Sign", onclick: async () => {
          try { await API.post("/documents/" + d.uuid + "/sign"); showAlert(alertBox, "Document signed", "success"); loadDocuments(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}));
      }
      if (d.status === "signed") {
        actions.push(el("button", { class: "btn btn-sm btn-gold", text: "Issue", onclick: async () => {
          try { await API.post("/documents/" + d.uuid + "/issue"); showAlert(alertBox, "Document issued", "success"); loadDocuments(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}));
      }
      if (d.status === "issued") {
        actions.push(el("button", { class: "btn btn-sm", text: "Paper", onclick: () => {
          window.location.href = "/paper?number=" + encodeURIComponent(d.document_number);
        }}));
        actions.push(el("button", { class: "btn btn-sm btn-danger", text: "Revoke", onclick: async () => {
          try { await API.post("/documents/" + d.uuid + "/revoke", { reason: "Administrative recall" }); showAlert(alertBox, "Document revoked", "success"); loadDocuments(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}));
      }
      tbody.append(el("tr", { "data-uuid": d.uuid }, [
        el("td", { class: "mono", text: d.document_number }),
        el("td", { text: d.title || d.document_type }),
        el("td", {}, [badge(d.status)]),
        el("td", { class: "muted", text: fmtDate(d.created_at) }),
        el("td", {}, actions),
      ]));
    }
    table.append(tbody);
    content.append(table);
    applyHighlight(content);
  }
}

// ---------------- Payments ----------------
async function loadPayments() {
  const data = await API.get("/payments");
  content.innerHTML = "";
  content.append(el("h2", { text: "Payments" }));
  const form = el("form", { class: "card mt-1" }, [
    el("h3", { text: "Initiate payment" }),
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "Amount (ETB)" }), el("input", { name: "amount", type: "number", step: "0.01", required: true }),
      ]),
      el("div", {}, [
        el("label", { text: "Description" }), el("input", { name: "description", value: "Government service fee" }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Initiate payment" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const p = await API.post("/payments/initiate", {
        amount: form.elements.amount.value,
        currency: "ETB",
        description: form.elements.description.value,
      });
      showAlert(alertBox, "Payment " + p.payment_id + " initiated (ref " + p.provider_ref + "). Send to " + p.redirect_url, "success");
      loadPayments();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  const table = el("table", { class: "mt-2" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Ref" }), el("th", { text: "Amount" }), el("th", { text: "Status" }), el("th", { text: "Initiated" }),
  ])])]);
  const tbody = el("tbody");
  for (const p of data.payments) {
    tbody.append(el("tr", { "data-uuid": p.id }, [
      el("td", { class: "mono", text: p.provider_name + " " + p.id.slice(0, 8) }),
      el("td", { text: p.currency + " " + p.amount }),
      el("td", {}, [badge(p.status)]),
      el("td", { class: "muted", text: fmtDate(p.initiated_at) }),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

async function loadReports() {
  const data = await API.get("/reports/service-summary");
  content.innerHTML = "";
  content.append(el("h2", { text: "Service summary (last 30 days)" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Service" }), el("th", { text: "Applications" }), el("th", { text: "Completed" }), el("th", { text: "Avg hours" }),
  ])])]);
  const tbody = el("tbody");
  for (const r of data.by_service) {
    tbody.append(el("tr", {}, [
      el("td", { text: r.service }), el("td", { text: r.applications }),
      el("td", { text: r.completed }), el("td", { text: r.avg_hours ?? "—" }),
    ]));
  }
  table.append(tbody);
  content.append(table);
  content.append(el("div", { class: "grid grid-3 mt-1" }, [
    el("div", { class: "stat" }, [el("div", { class: "value", text: data.complaints.total }), el("div", { class: "label", text: "Complaints" })]),
    el("div", { class: "stat" }, [el("div", { class: "value", text: data.complaints.resolved }), el("div", { class: "label", text: "Resolved" })]),
  ]));
}

async function loadComplaints() {
  const data = await API.get("/complaints");
  content.innerHTML = "";
  content.append(el("h2", { text: "Complaints" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Category" }), el("th", { text: "Priority" }), el("th", { text: "Status" }),
    el("th", { text: "SLA" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const c of data.complaints) {
    const actions = [];
    if (c.status === "submitted") {
      actions.push(el("button", { class: "btn btn-sm btn-gold", text: "Acknowledge", onclick: async () => {
        try { await API.put("/complaints/" + c.id, { action: "acknowledge" }); loadComplaints(); }
        catch (err) { showAlert(alertBox, err.message); }
      }}));
    }
    if (c.status === "acknowledged") {
      actions.push(el("button", { class: "btn btn-sm", text: "Start", onclick: async () => {
        try { await API.put("/complaints/" + c.id, { action: "start" }); loadComplaints(); }
        catch (err) { showAlert(alertBox, err.message); }
      }}));
    }
    if (c.status === "in_progress") {
      actions.push(el("button", { class: "btn btn-sm", text: "Resolve", onclick: async () => {
        const resolution = prompt("Resolution note") || "Resolved by officer";
        try { await API.put("/complaints/" + c.id, { action: "resolve", resolution }); loadComplaints(); }
        catch (err) { showAlert(alertBox, err.message); }
      }}));
      actions.push(el("button", { class: "btn btn-sm btn-danger", text: "Reject", onclick: async () => {
        try { await API.put("/complaints/" + c.id, { action: "reject", resolution: "Complaint rejected" }); loadComplaints(); }
        catch (err) { showAlert(alertBox, err.message); }
      }}));
    }
    tbody.append(el("tr", { "data-uuid": c.id }, [
      el("td", { text: c.category.replace(/_/g, " ") }),
      el("td", {}, [badge(c.priority)]),
      el("td", {}, [badge(c.status)]),
      el("td", { class: "muted", text: fmtDate(c.sla_deadline) }),
      el("td", {}, actions),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

async function loadAudit() {
  const data = await API.get("/audit-logs");
  content.innerHTML = "";
  content.append(el("h2", { text: "Audit log (latest 200)" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Time" }), el("th", { text: "Action" }), el("th", { text: "Resource" }),
    el("th", { text: "Result" }), el("th", { text: "IP" }),
  ])])]);
  const tbody = el("tbody");
  for (const l of data.audit_logs) {
    tbody.append(el("tr", {}, [
      el("td", { class: "muted", text: fmtDate(l.timestamp) }),
      el("td", { class: "mono", text: l.action }),
      el("td", { class: "muted", text: (l.resource_type || "—") + " " + (l.resource_id ? l.resource_id.slice(0, 8) : "") }),
      el("td", {}, [badge(l.result)]),
      el("td", { class: "mono muted", text: l.ip_address || "—" }),
    ]));
  }
  table.append(tbody);
  content.append(table);
}

// ---------------- Change password ----------------
function showChangePassword() {
  stopQueuePoll();
  content.innerHTML = "";
  content.append(el("h2", { text: "Change password" }));
  const form = el("form", { class: "card mt-1", style: "max-width:420px" }, [
    el("label", { text: "Current password" }), el("input", { name: "current_password", type: "password", required: true }),
    el("label", { text: "New password" }), el("input", { name: "new_password", type: "password", required: true }),
    el("label", { text: "Confirm new password" }), el("input", { name: "confirm", type: "password", required: true }),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Update password" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (form.elements.new_password.value !== form.elements.confirm.value) {
      showAlert(alertBox, "New passwords do not match");
      return;
    }
    try {
      await API.post("/auth/change-password", {
        current_password: form.elements.current_password.value,
        new_password: form.elements.new_password.value,
      });
      showAlert(alertBox, "Password updated. Please log in again.", "success");
      await API.logout();
      window.location.href = "/login";
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

// ---------------- Messages (citizen conversations) ----------------
let activeThreadId = null;

async function loadMessages() {
  activeThreadId = null;
  content.innerHTML = "";
  content.append(el("h2", { text: "Citizen messages" }));
  let convs = [];
  try {
    convs = (await API.get("/chat/conversations")).conversations || [];
  } catch (err) { showAlert(alertBox, err.message); }
  if (!convs.length) {
    content.append(el("p", { class: "muted mt-1", text: "No conversations in your area yet." }));
  } else {
    for (const c of convs) {
      content.append(el("div", { class: "card mt-1" }, [
        el("div", { class: "row spread" }, [
          el("strong", { text: c.subject }),
          el("span", {}, [badge(c.status), c.unread > 0 ? el("span", { class: "badge badge-gold", text: c.unread + " new" }) : null]),
        ]),
        el("p", { class: "muted mt-1", text: c.unit_name }),
        el("div", { class: "row mt-1" }, [
          el("button", { class: "btn btn-sm", text: "Open thread", onclick: () => openThread(c.id) }),
          c.status === "open" ? el("button", { class: "btn btn-sm btn-ghost", text: "Close", onclick: async () => {
            try { await API.put("/chat/conversations/" + c.id, {}); loadMessages(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}) : null,
        ]),
      ]));
    }
  }
  refreshChatBadge();
}

async function openThread(convId) {
  activeThreadId = convId;
  await renderThread();
  refreshChatBadge();
}

async function renderThread() {
  if (!activeThreadId) return;
  const messages = (await apiSafe("/chat/conversations/" + activeThreadId + "/messages", { messages: [] })).messages || [];
  content.innerHTML = "";
  content.append(el("h2", { text: "Thread" }));
  content.append(el("p", { class: "muted", text: "Conversation " + activeThreadId }));
  for (const m of messages) {
    const mine = m.sender_role === "officer";
    content.append(el("div", { class: "card mt-1 " + (mine ? "msg-mine" : "") }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: m.sender_role === "citizen" ? "Citizen" : "You (office)" }),
        el("span", { class: "muted", text: fmtDate(m.created_at) }),
      ]),
      el("p", { class: "mt-1", text: m.body }),
    ]));
  }
  const form = el("form", { class: "card mt-1" }, [
    el("label", { text: "Reply" }), el("textarea", { name: "body", rows: 2, maxlength: 600, required: true }),
    el("div", { class: "row mt-1" }, [
      el("button", { class: "btn btn-gold", type: "submit", text: "Send reply" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back to list", onclick: loadMessages }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/chat/conversations/" + activeThreadId + "/messages", { body: form.elements.body.value });
      await renderThread();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function apiSafe(path, fallback) {
  try { return await API.get(path); } catch (_) { return fallback; }
}

async function refreshChatBadge() {
  try {
    const data = await API.get("/chat/conversations");
    const unread = (data.conversations || []).filter(c => c.unread > 0).reduce((a, b) => a + (b.unread || 0), 0);
    const badge = document.getElementById("chat-badge");
    if (!badge) return;
    badge.hidden = unread === 0;
    badge.textContent = unread;
  } catch (_) { /* best-effort */ }
}

const LOADERS = {
  overview: loadOverview, queue: loadQueue, applications: loadApplications,
  citizens: loadCitizens, households: loadHouseholds, documents: loadDocuments,
  payments: loadPayments, reports: loadReports, complaints: loadComplaints,
  messages: loadMessages, audit: loadAudit,
};

async function switchView(name) {
  stopQueuePoll();
  views.forEach(v => v.classList.toggle("active", v.dataset.view === name));
  pageTitle.textContent = TITLES[name];
  alertBox.innerHTML = "";
  try { await LOADERS[name](); }
  catch (err) { showAlert(alertBox, err.message); }
}

views.forEach((link) => {
  link.addEventListener("click", async (e) => {
    e.preventDefault();
    await switchView(link.dataset.view);
  });
});

document.getElementById("change-password-link").addEventListener("click", (e) => {
  e.preventDefault();
  views.forEach(v => v.classList.remove("active"));
  pageTitle.textContent = "Change Password";
  alertBox.innerHTML = "";
  showChangePassword();
});

document.getElementById("logout-link").addEventListener("click", (e) => {
  e.preventDefault();
  API.logout();
});

(async () => {
  guard();
  try {
    await API.fetchMe();
    const user = API.getUser();
    document.getElementById("user-chip").textContent =
      "● " + user.roles.map(r => r.name).join(", ");
    await loadOverview();
  } catch (err) { showAlert(alertBox, err.message); }
})();
</script>
</body>
</html>