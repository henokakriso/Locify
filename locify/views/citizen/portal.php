<?php $title = 'Citizen Portal'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="mark"></span><span>LOCIFY</span></div>
    <nav class="nav" id="nav">
      <a href="#" data-view="services" class="active">Services / አገልግሎቶች</a>
      <a href="#" data-view="applications">My Applications / ማመልከቻዎቼ</a>
      <a href="#" data-view="track">Track by Service ID / ክትትል</a>
      <a href="#" data-view="documents">My Documents / ሰነዶቼ</a>
      <a href="#" data-view="appointments">Appointments / ቀጠሮ</a>
      <a href="#" data-view="complaints">Complaints / ቅሬታ</a>
      <a href="#" data-view="notifications">Notifications / ማሳወቂያ <span id="notif-badge" class="nav-badge" hidden></span></a>
      <a href="#" data-view="messages">Messages / መልዕክቶች <span id="chat-badge" class="nav-badge" hidden></span></a>
      <a href="#" data-view="profile">My Profile / መገለጫ</a>
      <a href="#" id="change-password-link">Change Password / የይለፍ ቃል</a>
      <a href="#" id="logout-link">Logout / ውጣ</a>
    </nav>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1 id="page-title">Services</h1>
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
  services: "Available Services", applications: "My Applications", track: "Track an Application",
  documents: "My Documents", appointments: "Appointments", complaints: "Complaints",
  notifications: "Notifications", messages: "Messages", profile: "My Profile",
};

// Holds the uuid to highlight after a create (post-create navigation).
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

async function refreshNotifBadge() {
  try {
    const data = await API.get("/notifications");
    const unread = (data.notifications || []).filter(n => n.status !== "read").length;
    const badge = document.getElementById("notif-badge");
    if (!badge) return;
    if (unread > 0) {
      badge.hidden = false;
      badge.textContent = unread;
    } else {
      badge.hidden = true;
    }
  } catch (_) { /* badge is best-effort */ }
}

async function loadServices() {
  const data = await API.get("/services");
  content.innerHTML = "";
  content.append(el("h2", { text: "Request a government service" }));
  for (const s of data.services) {
    const tags = [el("span", { class: "badge badge-blue", text: s.currency + " " + s.fee_amount })];
    if (s.issuance_mode) tags.push(el("span", { class: "badge", text: s.issuance_mode.replace(/_/g, " ") }));
    if (s.sla_hours) tags.push(el("span", { class: "badge badge-gold", text: "≈ " + Math.round(s.sla_hours / 24) + " day SLA" }));
    const card = el("div", { class: "card" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: s.local_name || s.name }),
        el("div", { class: "row", style: "gap:0.35rem" }, tags),
      ]),
      el("p", { class: "muted mt-1", text: s.description || "" }),
      el("div", { class: "row mt-1" }, [
        el("span", { class: "muted", text: "Provided by: " + s.admin_unit_name }),
      ]),
      el("button", {
        class: "btn btn-sm mt-2",
        text: "Apply now",
        onclick: () => showApplicationForm(s),
      }),
    ]);
    content.append(card);
  }
}

// Render fields defined by the service catalog (fields_json from the schema).
async function buildFormFields(fields, values = {}, onchange) {
  const wrap = el("div", { class: "grid grid-2" });
  for (const f of (fields || [])) {
    const req = f.required ? " (required)" : "";
    const label = el("label", { text: (f.label || f.name) + req });
    let control;
    if (f.type === "select") {
      control = el("select", {
        name: f.name, required: !!f.required,
        onchange: onchange || null,
      }, (f.options || []).map(o => el("option", { value: o, text: o })));
      if (values[f.name]) control.value = values[f.name];
    } else if (f.type === "textarea") {
      control = el("textarea", { name: f.name, rows: 3, required: !!f.required, onchange: onchange || null });
      control.value = values[f.name] || "";
    } else if (f.type === "document") {
      control = el("select", { name: f.name, required: !!f.required, onchange: onchange || null },
        [el("option", { value: "", text: "— select your issued document —" })]);
      const docs = await API.get("/documents").catch(() => ({ documents: [] }));
      for (const d of (docs.documents || []).filter(x => ["issued", "verified", "printed"].includes(x.status))) {
        control.append(el("option", { value: d.uuid, text: d.document_number + " (" + d.document_type + ")" }));
      }
      if (values[f.name]) control.value = values[f.name];
    } else {
      control = el("input", { name: f.name, type: "text", required: !!f.required, onchange: onchange || null });
      control.value = values[f.name] || "";
    }
    wrap.append(label, control);
  }
  return wrap;
}

function showApplicationForm(service, existing = {}, title = null) {
  content.innerHTML = "";
  const form = el("form", {}, [
    el("h2", { text: title || ((service.local_name || service.name) + " — Application") }),
  ]);
  buildFormFields(service.fields, existing).then(wrap => form.insertBefore(wrap, null));
  form.append(
    el("label", { class: "mt-2", text: "Comments / additional information" }),
    el("textarea", { name: "form", rows: 3, placeholder: "Optional details for this application" }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Submit application" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadServices }),
    ]),
  );
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const formData = {};
    for (const f of form.elements) {
      if (f.name && f.name !== "form" && f.type !== "submit") formData[f.name] = f.value;
    }
    const payload = {
      service_id: service.id,
      form: Object.keys(formData).length ? formData : { note: form.elements.form.value },
    };
    if (formData.requested_document) {
      payload.requested_document_id = formData.requested_document;
      delete payload.form.requested_document;
    }
    try {
      const created = await API.post("/services/applications", payload);
      pendingHighlight = created.uuid || created.id;
      showAlert(alertBox, "Application submitted: " + created.application_number, "success");
      refreshNotifBadge();
      switchView("applications");
    } catch (err) {
      showAlert(alertBox, err.message);
    }
  });
  content.append(form);
}

async function loadApplications() {
  const data = await API.get("/services/applications");
  content.innerHTML = "";
  content.append(el("h2", { text: "Application tracking" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Number" }), el("th", { text: "Service" }),
    el("th", { text: "Status" }), el("th", { text: "Submitted" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const a of data.applications) {
    const actions = [];
    actions.push(el("button", { class: "btn btn-sm btn-ghost", text: "Details", onclick: () => showApplicationDetails(a.uuid) }));
    if (a.status === "needs_correction") {
      actions.push(el("button", { class: "btn btn-sm btn-gold", text: "Fix & resubmit", onclick: () => showCorrectionForm(a.uuid) }));
    }
    if (a.status === "submitted" || a.status === "in_review" || a.status === "received" || a.status === "verification") {
      actions.push(el("button", {
        class: "btn btn-sm btn-danger", text: "Cancel",
        onclick: async () => {
          try { await API.put("/services/applications/" + a.uuid + "/step", { action: "cancel", comments: "Cancelled by applicant" }); loadApplications(); }
          catch (err) { showAlert(alertBox, err.message); }
        },
      }));
    }
    tbody.append(el("tr", { "data-uuid": a.uuid }, [
      el("td", { class: "mono", text: a.application_number }),
      el("td", { text: a.service_name }),
      el("td", {}, [badge(a.status)]),
      el("td", { class: "muted", text: fmtDate(a.created_at) }),
      el("td", {}, actions),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

function timelineHtml(history) {
  const ol = el("ol", { class: "timeline mt-2" });
  for (const h of history) {
    ol.append(el("li", {}, [
      el("div", { class: "row spread" }, [
        el("strong", { text: h.status.replace(/_/g, " ") }),
        el("span", { class: "muted", text: fmtDate(h.created_at) }),
      ]),
      h.notes ? el("p", { class: "muted", text: h.notes }) : null,
    ]));
  }
  return ol;
}

async function showApplicationDetails(uuidOrNumber) {
  content.innerHTML = "";
  try {
    const a = await API.get("/services/applications/" + encodeURIComponent(uuidOrNumber));
    content.append(el("h2", { text: a.application_number }));
    const meta = el("div", { class: "card mt-1" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: a.service_name + " (" + (a.service_code || "SVC") + ")" }),
        el("span", {}, [badge(a.status)]),
      ]),
      el("p", { class: "muted mt-1", text: "Issuance: " + (a.issuance_mode || "—").replace(/_/g, " ") }),
      el("p", { class: "muted", text: "Submitted: " + fmtDate(a.submitted_at) + " · Due: " + fmtDate(a.due_at) }),
      a.overdue ? el("p", { class: "alert alert-error mt-1", text: "This application is past its SLA deadline." }) : null,
      a.status_notes ? el("p", { class: "mt-1", text: a.status_notes }) : null,
      a.correction_deadline ? el("p", { class: "muted", text: "Correction deadline: " + fmtDate(a.correction_deadline) }) : null,
    ]);
    content.append(meta);

    content.append(el("h3", { class: "mt-2", text: "Timeline / የሂደት ታሪክ" }));
    content.append(timelineHtml(a.history || []));

    if ((a.attachments || []).length) {
      content.append(el("h3", { class: "mt-2", text: "Supporting documents" }));
      for (const att of a.attachments) {
        content.append(el("div", { class: "card mt-1 row spread" }, [
          el("div", {}, [
            el("strong", { text: att.original_name || att.document_type }),
            el("p", { class: "muted", text: att.document_type + " · " + (att.mime_type) + " · " + Math.round(att.size_bytes / 1024) + " KB" }),
          ]),
          badge(att.verification_status === "verified" ? "verified" : (att.verification_status === "rejected" ? "rejected" : "pending")),
        ]));
      }
    }

    const openUpload = ["submitted", "received", "verification", "document_check", "officer_review", "needs_correction", "review_required", "on_hold"].includes(a.status);
    if (openUpload) {
      const upForm = el("form", { class: "card mt-2" }, [
        el("h3", { text: "Upload a supporting document" }),
        el("label", { text: "File (PDF, JPG, PNG — max 8 MB)" }),
        el("input", { type: "file", name: "file", accept: "application/pdf,image/jpeg,image/png", required: true }),
        el("label", { text: "Document type" }),
        el("input", { name: "document_type", placeholder: "e.g. proof_of_address, id_card" }),
        el("button", { class: "btn mt-1", type: "submit", text: "Upload" }),
      ]);
      upForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const fd = new FormData();
        fd.append("file", upForm.elements.file.files[0]);
        if (upForm.elements.document_type.value) fd.append("document_type", upForm.elements.document_type.value);
        try {
          const res = await fetch(API.base + "/applications/" + a.uuid + "/documents", {
            method: "POST",
            headers: { "Authorization": "Bearer " + API.getToken() },
            body: fd,
          });
          const data = await res.json();
          if (!res.ok) throw new Error(data?.error?.message || "Upload failed");
          showAlert(alertBox, "Uploaded — pending officer verification.", "success");
          showApplicationDetails(a.uuid);
        } catch (err) { showAlert(alertBox, err.message); }
      });
      content.append(upForm);
    }

    content.append(el("div", { class: "row mt-2" }, [
      el("button", { class: "btn btn-ghost", text: "Back", onclick: loadApplications }),
    ]));
  } catch (err) { showAlert(alertBox, err.message); }
}

async function showCorrectionForm(appUuid) {
  const a = await API.get("/services/applications/" + appUuid);
  const service = (await API.get("/services")).services.find(s => s.id && (a.service_name === s.name || a.service_name === s.local_name));
  content.innerHTML = "";
  content.append(el("h2", { text: "Correct application " + a.application_number }));
  content.append(el("div", { class: "alert alert-gold", text: "Reason: " + (a.correction_reason || a.status_notes || "") }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { id: "correction-fields" }),
    el("label", { class: "mt-2", text: "What did you change?" }),
    el("textarea", { name: "comments", rows: 3, required: true, placeholder: "Describe the correction you made" }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn btn-gold", type: "submit", text: "Submit corrections" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadApplications }),
    ]),
  ]);
  const values = { ...(a.form_data || {}) };
  if (a.requested_document_id && !values.requested_document) values.requested_document = a.requested_document_id;
  (service ? buildFormFields(service.fields, values) : Promise.resolve(el("p", { class: "muted", text: "Form unavailable" })))
    .then(wrap => form.querySelector("#correction-fields").append(wrap));
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const formData = {};
    for (const f of form.elements) {
      if (f.name && f.name !== "comments" && f.type !== "submit") formData[f.name] = f.value;
    }
    try {
      await API.put("/services/applications/" + appUuid + "/step", {
        action: "submit-correction",
        comments: form.elements.comments.value,
        form: formData,
      });
      showAlert(alertBox, "Corrections submitted — your application is under review again.", "success");
      showApplicationDetails(appUuid);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function loadProfile() {
  content.innerHTML = "";
  try {
    const p = await API.get("/portal/profile");
    content.append(el("h2", { text: "My profile" }));
    const card = el("div", { class: "card mt-1" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: p.name || "—" }),
        badge(p.status),
      ]),
      p.national_id_mask ? el("p", { class: "muted mt-1", text: "National ID: " + p.national_id_mask }) : null,
      el("p", { class: "muted", text: "Date of birth: " + (p.dob_eth ? p.dob_eth + " (E.C.)" : "—") + (p.dob_greg ? " / " + p.dob_greg + " (G.C.)" : "") }),
      el("p", { class: "muted", text: "Sex: " + (p.sex || "—") }),
      el("p", { class: "muted", text: "Phone (masked): " + (p.phone_mask || "—") }),
    ]);
    content.append(card);
    if (p.address) {
      content.append(el("div", { class: "card mt-1" }, [
        el("h3", { text: "Address" }),
        el("p", { text: p.address.admin_unit_local_name || p.address.admin_unit_name }),
        el("p", { class: "muted", text: "Code: " + (p.address.admin_unit_code || "—") }),
        el("p", { class: "muted", text: "Village: " + (p.address.village || "—") + " · House: " + (p.address.house_no || "—") }),
      ]));
    }
    if (p.status === "pending_verification") {
      content.append(el("div", { class: "alert alert-gold mt-1", text: "Your identity is awaiting verification by a kebele officer. You can browse services now, but applying requires verification." }));
    }
  } catch (err) { showAlert(alertBox, err.message); }
}

async function loadTrack() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Track an application by Service ID" }));
  content.append(el("p", { class: "muted", text: "Enter the Service ID (e.g. LOC-2026-AA-06-01-RES-000001) printed on your receipt or notification." }));
  const form = el("form", { class: "card mt-1", style: "max-width:480px" }, [
    el("label", { text: "Service ID" }),
    el("input", { name: "service_id", placeholder: "LOC-2026-AA-06-01-RES-000001", required: true, style: "font-family:monospace" }),
    el("button", { class: "btn mt-1", type: "submit", text: "Track" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const num = form.elements.service_id.value.trim();
    if (!/^LOC-/.test(num)) { showAlert(alertBox, "That does not look like a Service ID (expected LOC-…)."); return; }
    try {
      const a = await API.get("/services/applications/by-service-id/" + encodeURIComponent(num));
      showApplicationDetails(a.uuid);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

async function loadDocuments() {
  const data = await API.get("/documents/my");
  content.innerHTML = "";
  content.append(el("h2", { text: "My digital documents" }));
  const table = el("table", {}, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Number" }), el("th", { text: "Type" }), el("th", { text: "Status" }), el("th", { text: "Created" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const d of data.documents) {
    tbody.append(el("tr", { "data-uuid": d.uuid }, [
      el("td", { class: "mono", text: d.document_number }),
      el("td", { text: d.title || d.document_type }),
      el("td", {}, [badge(d.status)]),
      el("td", { class: "muted", text: fmtDate(d.created_at) }),
      el("td", {}, [
        el("button", { class: "btn btn-sm btn-ghost", text: "Details", onclick: () => showDocument(d.uuid) }),
        d.verification_code ? el("a", {
          class: "btn btn-sm", text: "Verify", href: "/verify?code=" + encodeURIComponent(d.verification_code), target: "_blank",
        }) : null,
      ]),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
}

async function showDocument(docUuid) {
  content.innerHTML = "";
  try {
    const d = await API.get("/documents/" + docUuid);
    content.append(el("h2", { text: d.title || d.document_number }));
    const card = el("div", { class: "card mt-1" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: d.document_type }),
        el("span", {}, [badge(d.status)]),
      ]),
      el("p", { class: "mt-1 mono", text: "Number: " + d.document_number }),
      el("p", { class: "muted", text: "Issued (E.C.): " + (d.issued_at_eth || "—") }),
      el("p", { class: "muted", text: "Issued (G.C.): " + (d.issued_at_greg || "—") }),
    ]);
    content.append(card);
    if (d.verification_code) {
      content.append(el("div", { class: "card mt-1" }, [
        el("h3", { text: "Verification code (QR)" }),
        el("p", { class: "mono", style: "font-size:1.15rem; letter-spacing:0.12em", text: d.verification_code }),
        el("p", { class: "muted mt-1", text: "Anyone can verify this document at /verify without any personal data." }),
      ]));
    }
    content.append(el("div", { class: "row mt-2" }, [
      el("button", { class: "btn btn-ghost", text: "Back", onclick: loadDocuments }),
    ]));
  } catch (err) { showAlert(alertBox, err.message); }
}

async function loadAppointments() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Book an appointment" }));

  let offices = [];
  let services = [];
  try {
    offices = (await API.get("/offices")).offices || [];
    services = (await API.get("/services")).services || [];
  } catch (_) { /* some citizens may lack these permissions */ }

  const form = el("form", { class: "card mt-1" }, [
    el("h3", { text: "New booking" }),
    el("label", { text: "Office" }),
    el("select", { name: "office_id", required: true }, offices.map(o => el("option", { value: o.id, text: o.name + (o.admin_unit_name ? " — " + o.admin_unit_name : "") }))),
    el("label", { text: "Service" }),
    el("select", { name: "service_id", required: true }, services.map(s => el("option", { value: s.id, text: (s.local_name || s.name) + " (" + s.fee_amount + " ETB)" }))),
    el("label", { text: "Date" }),
    el("input", { name: "date", type: "date", required: true }),
    el("button", { class: "btn mt-1", type: "submit", text: "Show available slots" }),
  ]);
  const slotArea = el("div", { class: "mt-2" });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    slotArea.innerHTML = "";
    try {
      const slots = await API.get("/appointments/slots?office_id=" + form.elements.office_id.value + "&date=" + form.elements.date.value);
      if (!slots.slots.length) { slotArea.append(el("p", { class: "muted", text: "No free slots on this date." })); return; }
      const list = el("div", { class: "grid grid-3" });
      for (const s of slots.slots) {
        list.append(el("button", {
          class: "btn btn-ghost btn-sm", text: s.start.slice(11, 16),
          onclick: async () => {
            try {
              const booked = await API.post("/appointments", {
                office_id: form.elements.office_id.value,
                service_id: form.elements.service_id.value,
                slot_start: s.start, slot_end: s.end,
              });
              pendingHighlight = booked.id;
              showAlert(alertBox, "Appointment booked for " + fmtDate(s.start), "success");
              loadAppointments();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }));
      }
      slotArea.append(el("p", { class: "muted", text: "Click a time to book" }), list);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form, slotArea);

  let mine = [];
  try { mine = (await API.get("/appointments")).appointments || []; } catch (_) { }
  if (mine.length) {
    content.append(el("h3", { class: "mt-2", text: "My appointments" }));
    const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Slot start" }), el("th", { text: "Slot end" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
    ])])]);
    const tbody = el("tbody");
    for (const a of mine) {
      const actions = [];
      if (a.status === "booked") {
        actions.push(el("button", { class: "btn btn-sm btn-danger", text: "Cancel", onclick: async () => {
          try { await API.del("/appointments/" + a.id); showAlert(alertBox, "Appointment cancelled", "success"); loadAppointments(); }
          catch (err) { showAlert(alertBox, err.message); }
        }}));
      }
      tbody.append(el("tr", { "data-uuid": a.id }, [
        el("td", { class: "muted", text: fmtDate(a.slot_start) }),
        el("td", { class: "muted", text: fmtDate(a.slot_end) }),
        el("td", {}, [badge(a.status)]),
        el("td", {}, actions),
      ]));
    }
    table.append(tbody);
    content.append(table);
    applyHighlight(content);
  } else {
    content.append(el("p", { class: "muted mt-2", text: "No appointments yet." }));
  }
}

async function loadComplaints() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Submit a complaint" }));
  const form = el("form", { class: "card" }, [
    el("label", { text: "Category" }),
    el("select", { name: "category" }, [
      el("option", { value: "service_delay", text: "Service delay" }),
      el("option", { value: "officer_behavior", text: "Officer behavior" }),
      el("option", { value: "document_error", text: "Document error" }),
      el("option", { value: "bribery_fraud", text: "Bribery / fraud" }),
      el("option", { value: "other", text: "Other" }),
    ]),
    el("label", { text: "Priority" }),
    el("select", { name: "priority" }, ["low", "medium", "high", "critical"].map(p => el("option", { value: p, text: p }))),
    el("label", { text: "Description" }),
    el("textarea", { name: "description", rows: 5, required: true }),
    el("label", { class: "row", style: "gap:0.4rem" }, [
      el("input", { type: "checkbox", name: "anonymous", style: "width:auto" }),
      el("span", { text: "Submit anonymously" }),
    ]),
    el("button", { class: "btn mt-1", type: "submit", text: "Submit complaint" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/complaints", {
        category: form.elements.category.value,
        priority: form.elements.priority.value,
        description: form.elements.description.value,
        anonymous: form.elements.anonymous.checked,
      });
      pendingHighlight = created.id;
      showAlert(alertBox, "Complaint submitted. SLA: " + (form.elements.priority.value === "critical" ? "24h" : "3–14 days"), "success");
      form.reset();
      loadComplaints();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  let mine = [];
  try { mine = (await API.get("/complaints")).complaints || []; } catch (_) { }
  if (mine.length) {
    content.append(el("h3", { class: "mt-2", text: "My complaints & status" }));
    const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "Category" }), el("th", { text: "Priority" }), el("th", { text: "Status" }), el("th", { text: "SLA deadline" }),
    ])])]);
    const tbody = el("tbody");
    for (const c of mine) {
      tbody.append(el("tr", { "data-uuid": c.id }, [
        el("td", { text: c.category.replace(/_/g, " ") }),
        el("td", {}, [badge(c.priority)]),
        el("td", {}, [badge(c.status)]),
        el("td", { class: "muted", text: fmtDate(c.sla_deadline) }),
      ]));
    }
    table.append(tbody);
    content.append(table);
    applyHighlight(content);
  }
}

async function loadNotifications() {
  const data = await API.get("/notifications");
  content.innerHTML = "";
  content.append(el("h2", { text: "Notifications" }));
  if (data.notifications.length) {
    content.append(el("div", { class: "row mt-1" }, [
      el("button", { class: "btn btn-sm btn-ghost", text: "Mark all as read", onclick: async () => {
        try { await API.post("/notifications/read-all"); loadNotifications(); refreshNotifBadge(); }
        catch (err) { showAlert(alertBox, err.message); }
      }}),
    ]));
  }
  for (const n of data.notifications) {
    const btn = n.status === "read" ? null : el("button", { class: "btn btn-sm", text: "Mark read", onclick: async () => {
      try { await API.post("/notifications/" + n.id); loadNotifications(); refreshNotifBadge(); }
      catch (err) { showAlert(alertBox, err.message); }
    }});
    content.append(el("div", { class: "card mt-1" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: (n.subject || n.channel) + (n.status === "read" ? " ✓" : "") }),
        el("span", { class: "muted", text: fmtDate(n.created_at) }),
      ]),
      el("p", { class: "mt-1", text: n.body }),
      btn ? el("div", { class: "mt-1" }, [btn]) : null,
    ].filter(Boolean)));
  }
}

async function refreshChatBadge() {
  try {
    const data = await API.get("/chat/conversations");
    const unread = (data.conversations || []).filter(c => c.unread > 0).reduce((a, b) => a + (b.unread || 0), 0);
    const badge = document.getElementById("chat-badge");
    if (!badge) return;
    badge.hidden = unread === 0;
    badge.textContent = unread;
  } catch (_) { /* badge is best-effort */ }
}

// ---------------- Messages (chat with offices) ----------------
async function loadMessages() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Messages with your kebele" }));
  let convs = [];
  try {
    convs = (await API.get("/chat/conversations")).conversations || [];
  } catch (err) { showAlert(alertBox, err.message); }
  if (!convs.length) {
    content.append(el("p", { class: "muted mt-1", text: "No conversations yet." }));
  } else {
    for (const c of convs) {
      content.append(el("div", { class: "card mt-1" }, [
        el("div", { class: "row spread" }, [
          el("strong", { text: c.subject }),
          el("span", {}, [badge(c.status), c.unread > 0 ? el("span", { class: "badge badge-gold", text: c.unread + " new" }) : null]),
        ]),
        el("p", { class: "muted mt-1", text: "With: " + (c.unit_name || "Office") }),
        el("div", { class: "row mt-1" }, [
          el("button", { class: "btn btn-sm", text: "Open thread", onclick: () => showThread(c.id) }),
        ]),
      ]));
    }
  }
  content.append(el("h3", { class: "mt-2", text: "Start a new conversation" }));
  const units = (await apiSafe("/chat/units", { units: [] })).units || [];
  const form = el("form", { class: "card mt-1" }, [
    el("label", { text: "Office" }),
    el("select", { name: "admin_unit_id" }, units.map(u => el("option", { value: u.id, text: u.name + (u.office_name ? " — " + u.office_name : "") }))),
    el("label", { text: "Subject" }), el("input", { name: "subject", maxlength: 255, required: true }),
    el("label", { text: "Message" }), el("textarea", { name: "message", maxlength: 600, rows: 3, required: true }),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Send" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/chat/conversations", {
        admin_unit_id: form.elements.admin_unit_id.value,
        subject: form.elements.subject.value,
        message: form.elements.message.value,
      });
      showAlert(alertBox, "Conversation opened", "success");
      loadMessages();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
  refreshChatBadge();
}

async function showThread(convId) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Thread" }));
  const messages = (await apiSafe("/chat/conversations/" + convId + "/messages", [])).messages || [];
  for (const m of messages) {
    const mine = m.sender_role === "citizen";
    content.append(el("div", { class: "card mt-1 " + (mine ? "msg-mine" : "") }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: mine ? "You" : "Office" }),
        el("span", { class: "muted", text: fmtDate(m.created_at) }),
      ]),
      el("p", { class: "mt-1", text: m.body }),
    ]));
  }
  const form = el("form", { class: "card mt-1" }, [
    el("label", { text: "Reply" }), el("textarea", { name: "body", rows: 2, maxlength: 600, required: true }),
    el("div", { class: "row mt-1" }, [
      el("button", { class: "btn", type: "submit", text: "Send reply" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadMessages }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/chat/conversations/" + convId + "/messages", { body: form.elements.body.value });
      showThread(convId);
      refreshChatBadge();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
  try { await API.post("/chat/conversations/" + convId + "/read"); refreshChatBadge(); } catch (_) { /* best-effort */ }
}

async function apiSafe(path, fallback) {
  try { return await API.get(path); } catch (_) { return fallback; }
}

const LOADERS = {
  services: loadServices, applications: loadApplications, track: loadTrack,
  documents: loadDocuments, appointments: loadAppointments, complaints: loadComplaints,
  notifications: loadNotifications, messages: loadMessages, profile: loadProfile,
};

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

function setActiveView(name) {
  views.forEach(v => v.classList.toggle("active", v.dataset.view === name));
}

async function switchView(name) {
  setActiveView(name);
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
  setActiveView(null);
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
    await loadServices();
    refreshNotifBadge();
    refreshChatBadge();
    setInterval(refreshNotifBadge, 30000);
    setInterval(refreshChatBadge, 30000);
  } catch (err) {
    showAlert(alertBox, err.message);
  }
})();
</script>
</body>
</html>
