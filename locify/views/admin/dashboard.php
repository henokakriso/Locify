<?php $title = 'Administrator'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="mark"></span><span>LOCIFY</span></div>
    <nav class="nav" id="nav">
      <div class="section-label">Administration</div>
      <a href="#" data-view="overview">Overview</a>
      <a href="#" data-view="units" class="active">Administrative Units</a>
      <a href="#" data-view="offices">Offices</a>
      <a href="#" data-view="users">Users & Roles</a>
      <a href="#" data-view="services-config">Services</a>
      <a href="#" data-view="digital">Digital Services</a>
      <a href="#" data-view="institutions">Institutions</a>
      <a href="#" data-view="citizens">Citizens (Import/Export)</a>
      <a href="#" data-view="residents">Residents</a>
      <a href="#" data-view="reports">Reports</a>
      <a href="#" data-view="audit">Audit Log</a>
      <a href="#" id="security-link">Security (2FA)</a>
      <a href="#" id="change-password-link">Change Password</a>
      <a href="#" id="logout-link">Logout</a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1 id="page-title">Administrative Units</h1>
      <span class="user-chip" id="user-chip">Loading…</span>
    </div>
    <div id="alert"></div>
    <div id="content"></div>
  </main>
</div>

<script src="/assets/js/qrcode.js"></script>
<script src="/assets/js/api.js"></script>
<script src="/assets/js/residents.js"></script>
<script src="/assets/js/digital.js"></script>
<script>
const content = document.getElementById("content");
const alertBox = document.getElementById("alert");
const pageTitle = document.getElementById("page-title");
const views = document.querySelectorAll("#nav a[data-view]");

const TITLES = {
  overview: "Overview", units: "Administrative Units", offices: "Offices", users: "Users & Roles",
  "services-config": "Service Configuration", institutions: "Institutions",
  "digital": "Digital Kebele Services",
  citizens: "Citizens (Import/Export)", residents: "Resident Management",
  reports: "Reports", audit: "Audit Log",
};

// Holds the id/uuid to flash-highlight after a create (post-create navigation).
let pendingHighlight = null;

function guard() {
  if (!API.isLoggedIn()) window.location.href = "/login";
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

async function loadOverview() {
  const dash = await API.get("/reports/dashboard");
  content.innerHTML = "";
  content.append(el("h2", { text: "Key performance indicators" }));
  const unitSel = el("select", { id: "unit-filter" }, [
    el("option", { value: "", text: "All kebeles" }),
  ]);
  for (const u of dash.by_unit || []) {
    unitSel.append(el("option", { value: u.id, text: u.name }));
  }
  unitSel.addEventListener("change", async () => {
    if (unitSel.dataset.locked) return;
    unitSel.dataset.locked = "1";
    const q = unitSel.value ? "?unit=" + encodeURIComponent(unitSel.value) : "";
    try {
      const filtered = await API.get("/reports/dashboard" + q);
      renderOverviewTiles(filtered);
    } catch (err) { showAlert(alertBox, err.message); }
    finally { delete unitSel.dataset.locked; }
  });
  content.append(el("div", { class: "row mt-1" }, [unitSel]));
  renderOverviewTiles(dash);

  function renderOverviewTiles(d) {
    content.querySelectorAll(".overview-tiles").forEach(n => n.remove());
    const tiles = [
      ["Applications total", d.applications_total],
      ["In review", d.applications_in_review],
      ["Citizens", d.citizens_total],
      ["Pending verification", d.citizens_pending],
      ["Documents", d.documents_total],
      ["Issued documents", d.documents_issued],
      ["Payments today", d.payments_today],
      ["Revenue (ETB)", Number(d.payments_revenue_total).toLocaleString()],
      ["Open complaints", d.complaints_open],
      ["Queue waiting", d.tickets_waiting],
    ];
    const grid = el("div", { class: "grid grid-3 mt-1 overview-tiles" });
    for (const [label, value] of tiles) {
      grid.append(el("div", { class: "stat stat-kpi" }, [
        el("div", { class: "value", text: String(value) }),
        el("div", { class: "label", text: label }),
      ]));
    }
    content.append(grid);
    content.append(el("p", { class: "muted mt-2", text: "Live figures across all administrative units in scope." }));
  }
}

async function loadUnits() {
  const data = await API.get("/admin/units");
  content.innerHTML = "";
  content.append(el("h2", { text: "Administrative hierarchy" }));
  content.append(el("h3", { class: "mt-2", text: "Add administrative unit" }));
  const createForm = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Name" }), el("input", { name: "name", required: true }),
        el("label", { text: "Local name" }), el("input", { name: "local_name" }),
      ]),
      el("div", {}, [
        el("label", { text: "Code" }), el("input", { name: "code", placeholder: "ET-AA-06-02" }),
        el("label", { text: "Type" }),
        el("select", { name: "type" },
          ["federal", "region", "zone", "woreda", "kebele", "other"].map(t =>
            el("option", { value: t, text: t }))),
      ]),
      el("div", {}, [
        el("label", { text: "Parent unit" }),
        el("select", { name: "parent_id" }, [
          el("option", { value: "", text: "— none —" }),
          ...data.admin_units.map(u => el("option", { value: u.id, text: u.type + ": " + u.name })),
        ]),
        el("label", { text: "Status" }),
        el("select", { name: "status" }, ["active", "inactive"].map(s => el("option", { value: s, text: s }))),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Create unit" }),
  ]);
  createForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/admin/units", {
        name: createForm.elements.name.value,
        local_name: createForm.elements.local_name.value || null,
        code: createForm.elements.code.value || null,
        type: createForm.elements.type.value,
        parent_id: createForm.elements.parent_id.value || null,
        status: createForm.elements.status.value,
      });
      showAlert(alertBox, "Administrative unit created", "success");
      loadUnits();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(createForm);
  content.append(el("h3", { class: "mt-3", text: "Existing units" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Name" }), el("th", { text: "Local name" }), el("th", { text: "Code" }),
    el("th", { text: "Type" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const u of data.admin_units) {
    tbody.append(el("tr", {}, [
      el("td", { text: u.name }),
      el("td", { text: u.local_name || "—" }),
      el("td", { class: "mono muted", text: u.code || "—" }),
      el("td", {}, [el("span", { class: "badge badge-blue", text: u.type })]),
      el("td", {}, [badge(u.status)]),
      el("td", {}, [
        el("button", { class: "btn btn-sm btn-ghost", text: "Edit", onclick: () => showUnitEdit(u) }),
        el("button", {
          class: "btn btn-sm " + (u.status === "active" ? "btn-danger" : "btn"),
          text: u.status === "active" ? "Deactivate" : "Activate",
          onclick: async () => {
            try {
              await API.put("/admin/units/" + u.id, { status: u.status === "active" ? "inactive" : "active" });
              loadUnits();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }),
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
}

function showUnitEdit(unit) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Edit administrative unit" }));
  const form = el("form", { class: "card" }, [
    el("label", { text: "Name" }), el("input", { name: "name", value: unit.name, required: true }),
    el("label", { text: "Local name" }), el("input", { name: "local_name", value: unit.local_name || "" }),
    el("label", { text: "Code" }), el("input", { name: "code", value: unit.code || "" }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Save" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadUnits }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.put("/admin/units/" + unit.id, {
        name: form.elements.name.value,
        local_name: form.elements.local_name.value || null,
        code: form.elements.code.value || null,
      });
      showAlert(alertBox, "Administrative unit updated", "success");
      loadUnits();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function loadOffices() {
  const data = await API.get("/admin/offices");
  content.innerHTML = "";
  content.append(el("h2", { text: "Offices" }));
  const form = el("form", { class: "card" }, [
    el("h3", { text: "Register office" }),
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "Name" }), el("input", { name: "name", required: true }),
        el("label", { text: "Address" }), el("input", { name: "address" }),
      ]),
      el("div", {}, [
        el("label", { text: "Admin unit ID" }), el("input", { name: "admin_unit_id", required: true, placeholder: "UUID" }),
        el("label", { text: "Daily capacity" }), el("input", { name: "capacity", type: "number", value: 20 }),
      ]),
    ]),
    el("button", { class: "btn mt-1", type: "submit", text: "Create office" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/admin/offices", {
        name: form.elements.name.value,
        address: form.elements.address.value,
        admin_unit_id: form.elements.admin_unit_id.value,
        capacity: parseInt(form.elements.capacity.value, 10),
      });
      pendingHighlight = created.id;
      showAlert(alertBox, "Office created — listed below.", "success");
      loadOffices();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Name" }), el("th", { text: "Unit" }), el("th", { text: "Address" }), el("th", { text: "Active" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const o of data.offices) {
    tbody.append(el("tr", { "data-uuid": o.id }, [
      el("td", { text: o.name }),
      el("td", { text: o.admin_unit_name }),
      el("td", { class: "muted", text: o.address || "—" }),
      el("td", {}, [badge(o.is_active ? "active" : "inactive")]),
      el("td", {}, [
        el("button", { class: "btn btn-sm btn-ghost", text: "Edit", onclick: () => showOfficeEdit(o) }),
        el("button", {
          class: "btn btn-sm " + (o.is_active ? "btn-danger" : "btn"),
          text: o.is_active ? "Deactivate" : "Activate",
          onclick: async () => {
            try {
              await API.put("/admin/offices/" + o.id, { is_active: o.is_active ? 0 : 1 });
              loadOffices();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }),
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

function showOfficeEdit(office) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Edit office" }));
  const form = el("form", { class: "card" }, [
    el("label", { text: "Name" }), el("input", { name: "name", value: office.name, required: true }),
    el("label", { text: "Address" }), el("input", { name: "address", value: office.address || "" }),
    el("label", { text: "Daily capacity" }), el("input", { name: "capacity", type: "number", value: office.capacity || 20 }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Save" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadOffices }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.put("/admin/offices/" + office.id, {
        name: form.elements.name.value,
        address: form.elements.address.value || null,
        capacity: parseInt(form.elements.capacity.value, 10),
      });
      showAlert(alertBox, "Office updated", "success");
      loadOffices();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function loadUsers() {
  const data = await API.get("/admin/users");
  content.innerHTML = "";
  content.append(el("h2", { text: "Users & roles" }));
  const form = el("form", { class: "card" }, [
    el("h3", { text: "Create officer account" }),
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "Username" }), el("input", { name: "username", required: true }),
        el("label", { text: "Password (12+ chars, upper/lower/number/symbol)" }), el("input", { name: "password", type: "password", required: true }),
      ]),
      el("div", {}, [
        el("label", { text: "Role" }),
        el("select", { name: "role" }, ["registration_officer", "document_officer", "verification_officer", "records_officer", "finance_officer", "supervisor", "kebele_admin", "woreda_admin"].map(r => el("option", { value: r, text: r }))),
        el("label", { text: "Admin unit ID" }), el("input", { name: "admin_unit_id", required: true, placeholder: "UUID" }),
      ]),
    ]),
    el("button", { class: "btn mt-1", type: "submit", text: "Create user" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/auth/register-user", {
        username: form.elements.username.value,
        password: form.elements.password.value,
        role: form.elements.role.value,
        admin_unit_id: form.elements.admin_unit_id.value,
      });
      pendingHighlight = created.user_id;
      showAlert(alertBox, "Officer account created (MFA required on first use) — listed below.", "success");
      loadUsers();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Username" }), el("th", { text: "Role" }), el("th", { text: "Unit" }),
    el("th", { text: "Status" }), el("th", { text: "Last login" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const u of data.users) {
    tbody.append(el("tr", { "data-uuid": u.id }, [
      el("td", { class: "mono", text: "••••" + u.username_hash.slice(0, 8) }),
      el("td", {}, [el("span", { class: "badge badge-blue", text: u.role_name })]),
      el("td", { text: u.admin_unit_name }),
      el("td", {}, [badge(u.status)]),
      el("td", { class: "muted", text: fmtDate(u.last_login) }),
      el("td", {}, [
        el("button", {
          class: "btn btn-sm " + (u.status === "active" ? "btn-danger" : "btn"),
          text: u.status === "active" ? "Deactivate" : "Activate",
          onclick: async () => {
            const isSelf = u.id === API.getUser()?.user_id;
            if (isSelf && u.status === "active" &&
                !confirm("This is YOUR account. Deactivating it logs you out immediately.\n\n"
                       + "If you are the last active administrator, the platform will reject the change.")) {
              return;
            }
            try {
              await API.put("/admin/users/" + u.id + "/status", { status: u.status === "active" ? "inactive" : "active" });
              if (isSelf) {
                showAlert(alertBox, "Your account was deactivated — you are now logged out.", "success");
                API.logout();
                return;
              }
              loadUsers();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }),
        el("button", { class: "btn btn-sm btn-ghost", text: "Edit role", onclick: () => showRoleEdit(u) }),
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

function showRoleEdit(user) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Change role — ••••" + user.username_hash.slice(0, 8) }));
  const form = el("form", { class: "card" }, [
    el("label", { text: "Role" }),
    el("select", { name: "role" }, ["citizen", "registration_officer", "document_officer", "verification_officer", "records_officer", "finance_officer", "supervisor", "kebele_admin", "woreda_admin"].map(r => el("option", { value: r, text: r, selected: r === (user.role_name || "") }))),
    el("label", { text: "Admin unit ID" }), el("input", { name: "admin_unit_id", required: true }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Assign role" }),
      el("button", {
        class: "btn btn-danger", type: "button", text: "Revoke current role",
        onclick: async () => {
          try {
            await API.del("/admin/users/roles", { user_id: user.id, role: user.role_name });
            showAlert(alertBox, "Role revoked", "success");
            loadUsers();
          } catch (err) { showAlert(alertBox, err.message); }
        },
      }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadUsers }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/admin/users/roles", {
        user_id: user.id,
        role: form.elements.role.value,
        admin_unit_id: form.elements.admin_unit_id.value,
      });
      showAlert(alertBox, "Role assigned", "success");
      loadUsers();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function loadServicesConfig() {
  const services = await API.get("/services");
  content.innerHTML = "";
  content.append(el("h2", { text: "Service configuration" }));
  const form = el("form", { class: "card" }, [
    el("h3", { text: "Add service to catalog" }),
    el("div", { class: "grid grid-2" }, [
      el("div", {}, [
        el("label", { text: "Name" }), el("input", { name: "name", required: true }),
        el("label", { text: "Description" }), el("textarea", { name: "description", rows: 2 }),
        el("label", { text: "Fee (ETB)" }), el("input", { name: "fee_amount", type: "number", step: "0.01", value: 0 }),
      ]),
      el("div", {}, [
        el("label", { text: "Admin unit ID" }), el("input", { name: "admin_unit_id", required: true, placeholder: "UUID" }),
        el("label", { text: "Workflow ID (optional)" }), el("input", { name: "workflow_id", placeholder: "UUID" }),
        el("label", { text: "Required documents (comma separated)" }), el("input", { name: "required_docs", placeholder: "identity_document, photo" }),
      ]),
    ]),
    el("button", { class: "btn mt-1", type: "submit", text: "Add service" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/admin/services", {
        name: form.elements.name.value,
        description: form.elements.description.value,
        fee_amount: parseFloat(form.elements.fee_amount.value || 0),
        admin_unit_id: form.elements.admin_unit_id.value,
        workflow_id: form.elements.workflow_id.value || null,
        required_docs: form.elements.required_docs.value.split(",").map(s => s.trim()).filter(Boolean),
      });
      pendingHighlight = created.service_id || created.id;
      showAlert(alertBox, "Service added — listed below.", "success");
      loadServicesConfig();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  content.append(el("h3", { class: "mt-2", text: "Catalog" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Name" }), el("th", { text: "Unit" }), el("th", { text: "Fee" }), el("th", { text: "Active" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const s of services.services) {
    tbody.append(el("tr", { "data-uuid": s.id }, [
      el("td", { text: s.local_name || s.name }),
      el("td", { text: s.admin_unit_name || "—" }),
      el("td", { text: (s.currency || "ETB") + " " + s.fee_amount }),
      el("td", {}, [badge(s.is_active ? "active" : "inactive")]),
      el("td", {}, [
        el("button", { class: "btn btn-sm btn-ghost", text: "Edit", onclick: () => showServiceEdit(s) }),
        el("button", {
          class: "btn btn-sm " + (s.is_active ? "btn-danger" : "btn"),
          text: s.is_active ? "Disable" : "Enable",
          onclick: async () => {
            try {
              await API.put("/admin/services/" + s.id, { is_active: s.is_active ? 0 : 1 });
              loadServicesConfig();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }),
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

function showServiceEdit(svc) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Edit service" }));
  const form = el("form", { class: "card" }, [
    el("label", { text: "Name" }), el("input", { name: "name", value: svc.name, required: true }),
    el("label", { text: "Description" }), el("textarea", { name: "description", rows: 2, text: "" , value: svc.description || "" }),
    el("label", { text: "Fee (ETB)" }), el("input", { name: "fee_amount", type: "number", step: "0.01", value: svc.fee_amount }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Save" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadServicesConfig }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.put("/admin/services/" + svc.id, {
        name: form.elements.name.value,
        description: form.elements.description.value || null,
        fee_amount: parseFloat(form.elements.fee_amount.value || 0),
      });
      showAlert(alertBox, "Service updated", "success");
      loadServicesConfig();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
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

async function loadInstitutions() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Government institutions" }));
  content.append(el("p", { class: "muted", text: "Institutions request access to issued documents via verification code. Approve requests to let them pull full records." }));

  let data = { institutions: [], requests: [] };
  try {
    const [insts, reqs] = await Promise.all([
      API.get("/institutions"),
      API.get("/institutions/requests"),
    ]);
    data = { institutions: insts.institutions || [], requests: reqs.requests || [] };
  } catch (err) { showAlert(alertBox, err.message); }

  if (data.requests.length) {
    content.append(el("h3", { class: "mt-2", text: "Pending document requests" }));
    for (const r of data.requests) {
      content.append(el("div", { class: "card mt-1" }, [
        el("div", { class: "row spread" }, [
          el("strong", { text: r.institution_name + " — " + r.document_number }),
          el("span", { class: "muted", text: fmtDate(r.created_at) }),
        ]),
        el("p", { class: "muted mt-1", text: "Purpose: " + (r.purpose || "—") }),
        el("div", { class: "row mt-1" }, [
          el("button", { class: "btn btn-sm btn-gold", text: "Approve", onclick: async () => {
            try { await API.put("/institutions/requests/" + r.id, { decision: "approved" }); showAlert(alertBox, "Approved — institution can now pull the full record", "success"); loadInstitutions(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}),
          el("button", { class: "btn btn-sm btn-ghost", text: "Reject", onclick: async () => {
            try { await API.put("/institutions/requests/" + r.id, { decision: "rejected" }); loadInstitutions(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}),
        ]),
      ]));
    }
  }

  content.append(el("h3", { class: "mt-2", text: "Registered institutions" }));
  if (data.institutions.length) {
    const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Name" }), el("th", { text: "Category" }), el("th", { text: "API token" }),
      el("th", { text: "Status" }), el("th", { text: "Actions" }),
    ])])]);
    const tbody = el("tbody");
    for (const i of data.institutions) {
      tbody.append(el("tr", { "data-uuid": i.id }, [
        el("td", {}, [el("strong", { text: i.name }), el("div", { class: "muted", text: i.short_name || i.contact || "" })]),
        el("td", { text: i.category }),
        el("td", {}, [i.has_token ? el("span", { class: "badge badge-green", text: "issued" }) : el("span", { class: "badge badge-gray", text: "none" })]),
        el("td", {}, [i.is_active ? el("span", { class: "badge badge-green", text: "active" }) : el("span", { class: "badge badge-red", text: "inactive" })]),
        el("td", {}, [
          el("button", { class: "btn btn-sm", text: "Issue token", onclick: async () => {
            try {
              const t = await API.post("/admin/institutions/" + i.id + "/token");
              showAlert(alertBox, "Token (shown once): " + t.token + " — grant header Authorization: Bearer <token>", "success");
            } catch (err) { showAlert(alertBox, err.message); }
          }}),
          i.is_active ? el("button", { class: "btn btn-sm btn-ghost", text: "Deactivate", onclick: async () => {
            try { await API.put("/admin/institutions/" + i.id, { is_active: 0 }); loadInstitutions(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}) : el("button", { class: "btn btn-sm btn-gold", text: "Activate", onclick: async () => {
            try { await API.put("/admin/institutions/" + i.id, { is_active: 1 }); loadInstitutions(); }
            catch (err) { showAlert(alertBox, err.message); }
          }}),
        ]),
      ]));
    }
    table.append(tbody);
    content.append(table);
    applyHighlight(content);
  }

  const form = el("form", { class: "card mt-2" }, [
    el("h3", { text: "Register institution" }),
    el("label", { text: "Name" }), el("input", { name: "name", required: true }),
    el("label", { text: "Short name (optional)" }), el("input", { name: "short_name" }),
    el("label", { text: "Contact (optional)" }), el("input", { name: "contact" }),
    el("label", { text: "Category" }),
    el("select", { name: "category" }, [
      "kebele", "woreda", "zone", "region", "federal_agency", "ministry", "other_gov",
    ].map(c => el("option", { value: c, text: c }))),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Register" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/admin/institutions", {
        name: form.elements.name.value,
        short_name: form.elements.short_name.value,
        contact: form.elements.contact.value,
        category: form.elements.category.value,
      });
      pendingHighlight = null;
      showAlert(alertBox, "Institution registered", "success");
      loadInstitutions();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

const LOADERS = {
  overview: loadOverview, units: loadUnits, offices: loadOffices, users: loadUsers,
  "services-config": loadServicesConfig, institutions: loadInstitutions,
  citizens: loadCitizens, residents: loadResidents, digital: loadDigital,
  reports: loadReports, audit: loadAudit,
};

const CSV_TEMPLATE = "national_id,first_name,middle_name,last_name,local_name,dob_eth,sex,phone,email,village,house_no,admin_unit_code\n"
  + "1234567890123,Abebe,Bekele,Tesfaye,,2008-04-12,M,,,Kebele 02,041,ET-AA-06-02\n"
  + ",Almaz,,Kebede,,1995-09-03,F,0911223344,,Kebele 01,012,\n";

async function loadCitizens() {
  const [units, dash] = await Promise.all([API.get("/admin/units"), API.get("/reports/dashboard")]);
  content.innerHTML = "";
  content.append(el("h2", { text: "Citizen registry — bulk operations" }));
  content.append(el("p", { class: "muted", text: "Import digitizes paper records in batches (max 500 rows). Every row is validated and audited; duplicates and bad rows are reported, never silently dropped." }));

  content.append(el("h3", { class: "mt-2", text: "Import CSV" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Target administrative unit" }),
        el("select", { name: "admin_unit_id" },
          units.admin_units.filter(u => u.type === "kebele" || u.status === "active")
            .map(u => el("option", { value: u.id, text: u.type + ": " + u.name }))),
        el("p", { class: "muted", style: "font-size:.78rem; margin-top:.35rem" },
          "Rows may override the unit per-line with admin_unit_code."),
      ]),
      el("div", { style: "grid-column: span 2" }, [
        el("label", { text: "CSV content" }),
        el("textarea", { name: "csv", rows: 6, style: "font-family:monospace; font-size:.8rem", required: true, placeholder: CSV_TEMPLATE }),
        el("p", { class: "muted", style: "font-size:.78rem; margin-top:.35rem" },
          "Columns: " + "national_id, first_name (required), middle_name, last_name (required), local_name, dob_eth (YYYY-MM-DD, Ethiopian calendar), sex (M/F/O), phone, email, village, house_no, admin_unit_code"),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Import CSV" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    form.querySelector("button").disabled = true;
    try {
      const res = await API.post("/citizens/import", {
        csv: form.elements.csv.value,
        admin_unit_id: form.elements.admin_unit_id.value,
      });
      const created = res.created?.length || 0;
      const errors = res.errors || [];
      content.querySelector("#import-result")?.remove();
      const box = el("div", { id: "import-result", class: "card mt-1" }, [
        el("h4", { text: "Result: " + created + " imported, " + errors.length + " errors" }),
      ]);
      if (errors.length) {
        const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
          el("th", { text: "Line" }), el("th", { text: "Problem" }),
        ])])]);
        const tbody = el("tbody");
        for (const err of errors.slice(0, 50)) {
          tbody.append(el("tr", {}, [el("td", { text: "#" + err.line }), el("td", { text: err.error })]));
        }
        table.append(tbody);
        box.append(table);
      }
      content.append(box);
      showAlert(alertBox, "Import finished", "success");
    } catch (err) { showAlert(alertBox, err.message); }
    finally { form.querySelector("button").disabled = false; }
  });
  content.append(form);

  content.append(el("h3", { class: "mt-2", text: "Export CSV" }));
  content.append(el("p", { class: "muted", text: "All citizens in your administrative scope, decrypted names, national IDs masked." }));
  content.append(el("div", { class: "row mt-1" }, [
    el("button", {
      class: "btn", text: "Download citizens.csv (" + (dash.citizens_total || 0) + " records)",
      onclick: async (e) => {
        const btnEl = e.target;
        btnEl.disabled = true;
        try {
          const res = await fetch(API.base + "/citizens/export", {
            headers: { Authorization: "Bearer " + API.getToken() },
          });
          if (!res.ok) throw new Error("Export failed (" + res.status + ")");
          const blob = await res.blob();
          const a = document.createElement("a");
          a.href = URL.createObjectURL(blob);
          a.download = "locify-citizens.csv";
          a.click();
          URL.revokeObjectURL(a.href);
        } catch (err) { showAlert(alertBox, err.message); }
        finally { btnEl.disabled = false; }
      },
    }),
  ]));
}

let setupSecret = null;

async function loadSecurity() {
  const user = API.getUser();
  content.innerHTML = "";
  content.append(el("h2", { text: "Security — two-factor authentication" }));
  content.append(el("p", { class: "muted", text: "When enabled, every sign-in requires a code from your authenticator app. Recovery codes are issued once and stored only as hashes — print them and keep them offline." }));

  const card = el("div", { class: "card mt-1", style: "max-width:560px" }, []);
  const mfaOn = !!user.mfa_enabled;

  if (mfaOn) {
    const remaining = await API.get("/auth/mfa/recovery");
    card.append(el("h4", { text: "2FA is active" }));
    card.append(el("div", { class: "badge badge-green", text: "Enabled" }));
    card.append(el("p", { class: "muted mt-1", text: "Recovery codes remaining: " + remaining.remaining_codes }));
    const disableForm = el("form", { class: "mt-2" }, [
      el("label", { text: "Current authenticator code (to disable)" }),
      el("div", { class: "row" }, [
        el("input", { name: "code", inputmode: "numeric", required: true, style: "max-width:180px" }),
        el("button", { class: "btn btn-danger", type: "submit", text: "Disable 2FA" }),
      ]),
    ]);
    disableForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      try {
        await API.post("/auth/mfa/disable", { code: disableForm.elements.code.value.trim() });
        showAlert(alertBox, "Two-factor authentication disabled", "success");
        API.getUser().mfa_enabled = false;
        loadSecurity();
      } catch (err) { showAlert(alertBox, err.message); }
    });
    card.append(disableForm);
  } else {
    card.append(el("h4", { text: "2FA is not enabled" }));
    const setupBtn = el("button", {
      class: "btn btn-gold mt-1", text: "Start setup",
      onclick: async (e) => {
        e.target.disabled = true;
        try {
          const setup = await API.post("/auth/mfa/setup", {});
          setupSecret = setup.secret;
          renderSetup(card, setup);
        } catch (err) { showAlert(alertBox, err.message); }
        finally { e.target.disabled = false; }
      },
    });
    card.append(setupBtn);
  }
  content.append(card);
}

function renderSetup(card, setup) {
  card.innerHTML = "";
  card.append(el("h4", { text: "Scan this QR code with your authenticator app" }));
  card.append(el("p", { class: "muted", text: "Google Authenticator, Authy, Microsoft Authenticator or any standard TOTP app." }));

  const canvas = el("canvas", { style: "max-width:220px; max-height:220px; image-rendering:pixelated; margin-top:.5rem" });
  if (window.QR) QR.drawInto(canvas, setup.otpauth_url, 4, 2);
  card.append(canvas);

  card.append(el("p", { class: "mono muted mt-1", text: "Manual entry: " + setup.secret }));

  const confirmForm = el("form", { class: "mt-2" }, [
    el("label", { text: "Enter the 6-digit code from your app" }),
    el("div", { class: "row" }, [
      el("input", { name: "code", inputmode: "numeric", required: true, style: "max-width:180px" }),
      el("button", { class: "btn btn-gold", type: "submit", text: "Activate 2FA" }),
    ]),
  ]);
  confirmForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const res = await API.post("/auth/mfa/enable", {
        secret: setupSecret,
        code: confirmForm.elements.code.value.trim(),
      });
      card.innerHTML = "";
      card.append(el("h4", { class: "badge badge-green", text: "2FA enabled" }));
      card.append(el("p", { class: "mt-1", text: "Write these recovery codes down now — they are shown only once:" }));
      const codes = el("pre", { class: "mono card mt-1", style: "padding:1rem; line-height:1.7" });
      res.recovery_codes.forEach(c => codes.append(c + "\n"));
      card.append(codes);
      card.append(el("p", { class: "muted", text: "Keep them offline. Each code works once to sign in if you lose your phone." }));
      API.getUser().mfa_enabled = true;
      setupSecret = null;
    } catch (err) { showAlert(alertBox, err.message); }
  });
  card.append(confirmForm);
}

function showChangePassword() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Change password" }));
  const form = el("form", { class: "card mt-1", style: "max-width:420px" }, [
    el("label", { text: "Current password" }), el("input", { name: "current_password", type: "password", required: true }),
    el("label", { text: "New password" }), el("input", { name: "new_password", type: "password", required: true }),
    el("label", { text: "Confirm new password" }), el("input", { name: "confirm", type: "password", required: true }),
    el("button", { class: "btn mt-1", type: "submit", text: "Update password" }),
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

views.forEach((link) => {
  link.addEventListener("click", async (e) => {
    e.preventDefault();
    views.forEach(v => v.classList.remove("active"));
    link.classList.add("active");
    pageTitle.textContent = TITLES[link.dataset.view];
    alertBox.innerHTML = "";
    try { await LOADERS[link.dataset.view](); }
    catch (err) { showAlert(alertBox, err.message); }
  });
});

document.getElementById("change-password-link").addEventListener("click", (e) => {
  e.preventDefault();
  views.forEach(v => v.classList.remove("active"));
  pageTitle.textContent = "Change Password";
  alertBox.innerHTML = "";
  showChangePassword();
});

document.getElementById("security-link").addEventListener("click", (e) => {
  e.preventDefault();
  views.forEach(v => v.classList.remove("active"));
  pageTitle.textContent = "Security (2FA)";
  alertBox.innerHTML = "";
  loadSecurity().catch(err => showAlert(alertBox, err.message));
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
