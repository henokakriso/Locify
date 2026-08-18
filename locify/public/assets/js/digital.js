/* ============================================================
   LOCIFY — Digital Kebele services (admin dashboard)
   Services CRUD · Applications & status tracking · Documents
   (residence certificates / local letters) · Appointments ·
   Digital notifications.
   Depends on api.js (API, el, badge, showAlert, fmtDate).
   ============================================================ */

"use strict";

const DIGITAL_TABS = ["services", "applications", "documents", "printjobs", "appointments", "notifications"];

async function loadDigital() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Digital Kebele services" }));
  content.append(el("p", { class: "muted", text: "Apply for residence certificates and local letters, track status through the official workflow, issue verifiable documents, manage appointments and keep citizens informed with digital notifications." }));
  renderTabBar("services");
  await showServicesTab();
}

function renderTabBar(active) {
  content.querySelector(".tab-bar")?.remove();
  const labels = { services: "Services", applications: "Applications", documents: "Documents", printjobs: "Print queue", appointments: "Appointments", notifications: "Notifications" };
  const bar = el("div", { class: "tab-bar mt-1" });
  for (const t of DIGITAL_TABS) {
    const btn = el("button", {
      class: "tab-btn" + (t === active ? " active" : ""),
      text: labels[t],
      onclick: async () => {
        content.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        btn.classList.add("active");
        content.querySelector("#digital-pane").innerHTML = "";
        const tab = { services: showServicesTab, applications: showApplicationsTab, documents: showDocumentsTab, printjobs: showPrintJobsTab, appointments: showAppointmentsTab, notifications: showNotificationsTab }[t];
        await tab();
      },
    });
    bar.append(btn);
  }
  content.append(bar);
  content.append(el("div", { id: "digital-pane" }));
}

/* ============================ SERVICES ============================ */

async function showServicesTab() {
  const pane = document.getElementById("digital-pane");
  const [catalog, workflows, units] = await Promise.all([
    API.get("/services"),
    API.get("/workflows"),
    API.get("/admin/units"),
  ]);
  const activeUnits = (units.admin_units || []).filter(u => u.status === "active");

  pane.innerHTML = "";
  pane.append(el("h3", { text: "Service catalog" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Name *" }), el("input", { name: "name", required: true, placeholder: "Residence Certificate" }),
        el("label", { text: "Local name (Amharic)" }), el("input", { name: "local_name" }),
        el("label", { text: "Description" }), el("textarea", { name: "description", rows: 2 }),
      ]),
      el("div", {}, [
        el("label", { text: "Administrative unit *" }),
        el("select", { name: "admin_unit_id", required: true }, activeUnits.map(u => el("option", { value: u.id, text: u.type + ": " + (u.local_name || u.name) }))),
        el("label", { text: "Workflow" }),
        el("select", { name: "workflow_id" }, [el("option", { value: "", text: "— none —" })].concat(workflows.workflows.map(w => el("option", { value: w.id, text: w.name + " (v" + w.version + ")" })))),
        el("label", { text: "Required documents (comma separated)" }),
        el("input", { name: "required_docs", placeholder: "identity_document, photo" }),
      ]),
      el("div", {}, [
        el("label", { text: "Fee (ETB)" }), el("input", { name: "fee_amount", type: "number", step: "0.01", value: "0" }),
        el("label", { text: "Slot duration (minutes)" }), el("input", { name: "slot_duration_min", type: "number", value: "20" }),
        el("div", { class: "row mt-2" }, [
          el("label", { class: "inline", text: "Active" }), el("input", { name: "is_active", type: "checkbox", checked: "checked" }),
        ]),
        el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Add service" }),
      ]),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const res = await API.post("/admin/services", {
        name: form.elements.name.value.trim(),
        local_name: form.elements.local_name.value.trim() || null,
        description: form.elements.description.value.trim() || null,
        admin_unit_id: form.elements.admin_unit_id.value,
        workflow_id: form.elements.workflow_id.value || null,
        required_docs: form.elements.required_docs.value.split(",").map(s => s.trim()).filter(Boolean),
        fee_amount: parseFloat(form.elements.fee_amount.value || 0),
        slot_duration_min: parseInt(form.elements.slot_duration_min.value || 20, 10),
        is_active: form.elements.is_active.checked ? 1 : 0,
      });
      showAlert(alertBox, "Service added to catalog.", "success");
      showServicesTab();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.append(form);

  const table = el("table", { class: "mt-2" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Service" }), el("th", { text: "Unit" }), el("th", { text: "Workflow" }),
    el("th", { text: "Fee" }), el("th", { text: "Required docs" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const s of catalog.services) {
    const editBtn = el("button", { class: "btn btn-sm btn-ghost", text: "Edit" });
    editBtn.addEventListener("click", () => showServiceEditor(s, activeUnits, workflows.workflows));
    tbody.append(el("tr", { "data-uuid": s.id }, [
      el("td", {}, [el("strong", { text: s.name }), el("div", { class: "muted", style: "font-size:.78rem", text: s.local_name || "" })]),
      el("td", { text: s.admin_unit_name || "—" }),
      el("td", { text: s.workflow_name || "—" }),
      el("td", { text: (s.currency || "ETB") + " " + Number(s.fee_amount || 0).toFixed(2) }),
      el("td", { class: "mono muted", style: "font-size:.78rem", text: (s.required_docs || []).join(", ") || "—" }),
      el("td", {}, [badge(s.is_active ? "active" : "inactive")]),
      el("td", {}, [
        editBtn,
        el("button", {
          class: "btn btn-sm " + (s.is_active ? "btn-danger" : ""),
          text: s.is_active ? "Disable" : "Enable",
          onclick: async () => {
            try {
              await API.put("/admin/services/" + s.id, { is_active: s.is_active ? 0 : 1 });
              showAlert(alertBox, s.is_active ? "Service disabled" : "Service enabled", "success");
              showServicesTab();
            } catch (err) { showAlert(alertBox, err.message); }
          },
        }),
      ]),
    ]));
  }
  table.append(tbody);
  pane.append(table);
}

function showServiceEditor(svc, activeUnits, workflows) {
  const pane = document.getElementById("digital-pane");
  const form = el("form", { class: "card mt-2" }, [
    el("h3", { text: "Edit service — " + svc.name }),
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Name *" }), el("input", { name: "name", value: svc.name, required: true }),
        el("label", { text: "Description" }), el("textarea", { name: "description", rows: 2, text: svc.description || "" }),
      ]),
      el("div", {}, [
        el("label", { text: "Required documents (comma separated)" }),
        el("input", { name: "required_docs", value: (svc.required_docs || []).join(", ") }),
      ]),
      el("div", {}, [
        el("label", { text: "Fee (ETB)" }), el("input", { name: "fee_amount", type: "number", step: "0.01", value: svc.fee_amount }),
        el("label", { text: "Currency" }), el("input", { name: "currency", value: svc.currency || "ETB" }),
        el("div", { class: "row mt-2" }, [
          el("label", { class: "inline", text: "Active" }), el("input", { name: "is_active", type: "checkbox", checked: svc.is_active ? "checked" : null }),
        ]),
      ]),
    ]),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Save changes" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: showServicesTab }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.put("/admin/services/" + svc.id, {
        name: form.elements.name.value.trim(),
        description: form.elements.description.value.trim() || null,
        required_docs: form.elements.required_docs.value.split(",").map(s => s.trim()).filter(Boolean),
        fee_amount: parseFloat(form.elements.fee_amount.value || 0),
        currency: form.elements.currency.value.trim() || "ETB",
        is_active: form.elements.is_active.checked ? 1 : 0,
      });
      showAlert(alertBox, "Service updated.", "success");
      showServicesTab();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.querySelector("table")?.remove();
  pane.insertBefore(form, pane.querySelector(".tab-note") ?? null);
}

/* ============================ APPLICATIONS ============================ */

let appsCache = { services: [], citizens: {} };

async function showApplicationsTab() {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const [services, apps] = await Promise.all([API.get("/services"), API.get("/services/applications")]);
  appsCache.services = services.services || [];

  pane.append(el("h3", { text: "Submit application" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Service *" }),
        el("select", { name: "service_id", required: true }, appsCache.services.map(s => el("option", { value: s.id, text: s.name }))),
      ]),
      el("div", {}, [
        el("label", { text: "Citizen national ID" }),
        el("div", { class: "row", style: "gap:.4rem" }, [
          el("input", { name: "nid", placeholder: "Search national ID", style: "flex:1" }),
          el("button", { class: "btn btn-sm", type: "button", text: "Find", onclick: findCitizenForApp }),
        ]),
        el("input", { name: "citizen_uuid", placeholder: "Citizen UUID (filled by search)", style: "margin-top:.4rem" }),
        el("input", { name: "requested_document_id", placeholder: "Original doc UUID (copy / reissue only)", style: "margin-top:.4rem" }),
      ]),
      el("div", {}, [
        el("label", { text: "Form data (JSON, optional)" }),
        el("textarea", { name: "form", rows: 3, placeholder: '{"purpose": "loan application"}' }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Submit application" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      let parsed = {};
      if (form.elements.form.value.trim()) {
        try { parsed = JSON.parse(form.elements.form.value); }
        catch { showAlert(alertBox, "Form data must be valid JSON"); return; }
      }
      const res = await API.post("/services/applications", {
        service_id: form.elements.service_id.value,
        citizen_uuid: form.elements.citizen_uuid.value.trim() || null,
        requested_document_id: form.elements.requested_document_id.value.trim() || null,
        form: parsed,
      });
      showAlert(alertBox, "Application " + res.application_number + " submitted — notification sent to citizen.", "success");
      showApplicationsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.append(form);

  async function findCitizenForApp() {
    const nid = form.elements.nid.value.trim();
    if (!nid) return;
    try {
      const data = await API.get("/citizens/search?national_id=" + encodeURIComponent(nid));
      if (!data.results.length) { showAlert(alertBox, "No citizen with that national ID."); return; }
      const c = data.results[0];
      form.elements.citizen_uuid.value = c.uuid;
      form.elements.citizen_uuid.setAttribute("placeholder", c.name);
      showAlert(alertBox, "Citizen found: " + c.name, "success");
    } catch (err) { showAlert(alertBox, err.message); }
  }

  pane.append(el("h3", { class: "mt-2", text: "Applications & status tracking" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Number" }), el("th", { text: "Service" }), el("th", { text: "Citizen" }),
    el("th", { text: "Status" }), el("th", { text: "Submitted" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const a of apps.applications || []) {
    const viewBtn = el("button", { class: "btn btn-sm btn-ghost", text: "Track" });
    viewBtn.addEventListener("click", () => showApplicationDetail(a.uuid));
    tbody.append(el("tr", { "data-uuid": a.uuid }, [
      el("td", { class: "mono", text: a.application_number }),
      el("td", { text: a.service_name }),
      el("td", { text: a.citizen_name || "—" }),
      el("td", {}, [badge(a.status)]),
      el("td", { text: fmtDate(a.created_at) }),
      el("td", {}, [viewBtn]),
    ]));
  }
  table.append(tbody);
  pane.append(table);
  if (!(apps.applications || []).length) {
    pane.append(el("p", { class: "muted mt-1", text: "No applications yet — submit the first one above." }));
  }
}

async function showApplicationDetail(uuid) {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const app = await API.get("/services/applications/" + uuid);
  pane.append(el("h3", { text: "Application " + app.application_number }));
  pane.append(el("p", { class: "muted", text: app.service_name + " — submitted " + fmtDate(app.submitted_at) }));

  pane.append(el("div", { class: "card mt-1" }, [
    el("div", { class: "row", style: "gap:.5rem; align-items:center; flex-wrap:wrap" }, [
      badge(app.status),
      el("span", { class: "muted", text: app.current_step ? "current step: " + app.current_step : "workflow complete" }),
      el("button", { class: "btn btn-sm btn-ghost", text: "Back", onclick: showApplicationsTab }),
    ]),
  ]));

  pane.append(el("h3", { class: "mt-2", text: "Workflow progress" }));
  const stepsBox = el("div", { class: "card mt-1", style: "padding:1rem" });
  const steps = app.steps || [];
  const statuses = ["pending", "in_progress", "completed"];
  const curIdx = Math.max(0, steps.findIndex(s => s.status === "in_progress"));
  steps.forEach((s, i) => {
    const done = s.status === "completed";
    const activeStep = s.status === "in_progress";
    stepsBox.append(el("div", { class: "status-step " + (done ? "done" : activeStep ? "active" : ""), style: "margin-top:.35rem" }, [
      el("span", { class: "dot" }),
      el("div", {}, [
        el("strong", { text: s.step_name }),
        el("div", { class: "muted", style: "font-size:.8rem", text: (s.completed_at ? "Completed " + fmtDate(s.completed_at) : s.started_at ? "Started " + fmtDate(s.started_at) : "") + (s.comments ? " — " + s.comments : "") }),
      ]),
    ]));
  });
  pane.append(stepsBox);

  if (app.history && app.history.length) {
    pane.append(el("h3", { class: "mt-2", text: "Status history" }));
    const hist = el("ul", { class: "timeline mt-1" });
    for (const h of app.history) {
      const li = el("li", {}, [
        el("strong", { text: (h.previous_status ? h.previous_status + " → " : "") + h.status }),
        el("div", { class: "muted", style: "font-size:.8rem", text: fmtDate(h.created_at) + (h.notes ? " — " + h.notes : "") }),
      ]);
      hist.append(li);
    }
    pane.append(hist);
  }

  if ((app.attachments || []).length) {
    pane.append(el("h3", { class: "mt-2", text: "Attachments" }));
    const attTable = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
      el("th", { text: "File" }), el("th", { text: "Type" }), el("th", { text: "Size" }), el("th", { text: "Status" }), el("th", { text: "Verification" }),
    ])])]);
    const attBody = el("tbody");
    for (const att of app.attachments || []) {
      attBody.append(el("tr", {}, [
        el("td", { text: att.original_name || att.document_type }),
        el("td", { class: "mono muted", text: att.mime_type }),
        el("td", { text: att.size_bytes ? Math.round(att.size_bytes / 1024) + " KB" : "—" }),
        el("td", {}, [badge(att.verification_status)]),
        el("td", { class: "row", style: "gap:.35rem" }, [
          el("button", { class: "btn btn-sm", text: "Verify", disabled: att.verification_status !== "pending" ? "disabled" : null, onclick: () => reviewUpload(att.id, "verify") }),
          el("button", { class: "btn btn-sm btn-danger", text: "Reject", disabled: att.verification_status !== "pending" ? "disabled" : null, onclick: () => reviewUpload(att.id, "reject") }),
        ]),
      ]));
    }
    attTable.append(attBody);
    pane.append(attTable);
  }

  async function reviewUpload(id, action) {
    try {
      await API.post("/applications/" + uuid + "/documents/" + id + "/review", { action });
      showAlert(alertBox, "Attachment " + action + "d.", "success");
      showApplicationDetail(uuid);
    } catch (err) { showAlert(alertBox, err.message); }
  }

  pane.append(el("h3", { class: "mt-2", text: "Officer actions" }));
  const actions = el("div", { class: "card mt-1 row", style: "gap:.5rem; flex-wrap:wrap; align-items:center" }, [
    el("input", { name: "comments", placeholder: "Comments / notes", style: "flex:1; min-width:220px" }),
    el("button", { class: "btn", text: "Next step", onclick: () => advanceApp("next") }),
    el("button", { class: "btn", text: "Hold", onclick: () => advanceApp("hold") }),
    el("button", { class: "btn", text: "Resume", onclick: () => advanceApp("resume") }),
    el("button", { class: "btn", text: "Request correction", onclick: () => advanceApp("request-correction") }),
    el("button", { class: "btn btn-gold", text: "Mark ready", onclick: () => advanceApp("mark-ready") }),
    el("button", { class: "btn btn-gold", text: "Complete", onclick: () => advanceApp("complete") }),
    el("button", { class: "btn", text: "Approve", onclick: () => advanceApp("approve") }),
    el("button", { class: "btn btn-danger", text: "Reject", onclick: () => advanceApp("reject") }),
    el("button", { class: "btn btn-ghost", text: "Return", onclick: () => advanceApp("return") }),
    el("button", { class: "btn btn-ghost", text: "Cancel application", onclick: () => advanceApp("cancel") }),
    el("button", { class: "btn btn-gold", text: "Create document", onclick: createDocumentForApp }),
  ]);
  pane.append(actions);

  async function advanceApp(action) {
    const body = {
      action,
      comments: actions.querySelector("[name=comments]").value.trim() || null,
    };
    if (action === "request-correction") {
      const deadline = prompt("Correction deadline (YYYY-MM-DD, optional):");
      if (deadline && !/^\d{4}-\d{2}-\d{2}$/.test(deadline)) { showAlert(alertBox, "Deadline must be YYYY-MM-DD"); return; }
      body.correction_deadline = deadline || null;
    }
    try {
      const res = await API.put("/services/applications/" + uuid + "/step", body);
      showAlert(alertBox, "Status: " + res.status + (res.current_step_name ? " → " + res.current_step_name : ""), "success");
      showApplicationDetail(uuid);
    } catch (err) { showAlert(alertBox, err.message); }
  }

  async function createDocumentForApp() {
    const type = prompt("Document type (residence_certificate / local_letter / certificate):", "residence_certificate");
    if (!type) return;
    try {
      const res = await API.post("/documents", {
        application_uuid: uuid,
        document_type: type,
        title: app.service_name + " — " + app.application_number,
        fields: {},
      });
      showAlert(alertBox, "Draft " + res.document_number + " created — sign and issue it in the Documents tab.", "success");
      showDocumentsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  }
}

/* ============================ DOCUMENTS ============================ */

async function showDocumentsTab() {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";

  pane.append(el("h3", { text: "Verify a document (public)" }));
  const vform = el("form", { class: "card mt-1 row", style: "gap:.5rem" }, [
    el("input", { name: "code", placeholder: "XXXX-XXXX-XXXX", class: "mono", style: "max-width:220px" }),
    el("button", { class: "btn", type: "submit", text: "Verify" }),
    el("div", { id: "verify-result" }),
  ]);
  vform.addEventListener("submit", async (e) => {
    e.preventDefault();
    const box = vform.querySelector("#verify-result");
    box.innerHTML = "";
    try {
      const res = await API.get("/documents/verify?code=" + encodeURIComponent(vform.elements.code.value.trim()));
      if (res.status === "valid") {
        box.append(badge("valid"), el("span", { class: "muted", text: "  " + res.document_type + " · " + res.document_number + " · issued by " + (res.issuing_authority || res.office || "LOCIFY") }));
      } else {
        box.append(el("span", { class: "badge badge-red", text: res.status }));
      }
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.append(vform);

  const docs = await API.get("/documents/office");
  pane.append(el("h3", { class: "mt-2", text: "Issued documents & certificates" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Number" }), el("th", { text: "Type" }), el("th", { text: "Citizen" }),
    el("th", { text: "Verification code" }), el("th", { text: "Status" }), el("th", { text: "Created" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const d of docs.documents || []) {
    const row = el("tr", { "data-uuid": d.uuid }, [
      el("td", { class: "mono", text: d.document_number }),
      el("td", { text: d.document_type }),
      el("td", { text: d.citizen_name || "—" }),
      el("td", { class: "mono", text: d.verification_code || "—" }),
      el("td", {}, [badge(d.status)]),
      el("td", { text: fmtDate(d.created_at) }),
      el("td", { class: "row", style: "gap:.35rem" }, [
        el("button", { class: "btn btn-sm btn-ghost", text: "View", onclick: () => showDocumentDetail(d.uuid) }),
        el("button", { class: "btn btn-sm", text: "Sign", disabled: d.status !== "draft" ? "disabled" : null, onclick: () => docAction(d.uuid, "sign") }),
        el("button", { class: "btn btn-sm", text: "Issue", disabled: !["signed", "draft"].includes(d.status) ? "disabled" : null, onclick: () => docAction(d.uuid, "issue") }),
        el("button", { class: "btn btn-sm", text: "Print", disabled: !["signed", "issued", "printed"].includes(d.status) ? "disabled" : null, onclick: () => createPrintJob(d.uuid, d.document_number) }),
        el("button", { class: "btn btn-sm btn-danger", text: "Revoke", disabled: !["issued", "verified", "signed"].includes(d.status) ? "disabled" : null, onclick: () => docAction(d.uuid, "revoke") }),
      ]),
    ]);
    tbody.append(row);
  }
  table.append(tbody);
  pane.append(table);
  if (!(docs.documents || []).length) {
    pane.append(el("p", { class: "muted mt-1", text: "No documents yet — create one from an application." }));
  }

  async function docAction(uuid, action) {
    try {
      let res;
      if (action === "revoke") {
        const reason = prompt("Reason for revocation:");
        if (!reason) return;
        res = await API.post("/documents/" + uuid + "/revoke", { reason });
      } else {
        res = await API.post("/documents/" + uuid + "/" + action, {});
      }
      if (res.verification_code) {
        showAlert(alertBox, "Signed — verification code " + res.verification_code, "success");
      } else {
        showAlert(alertBox, "Document " + res.status + ".", "success");
      }
      showDocumentsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  }

  async function createPrintJob(docUuid, docNumber) {
    const reason = prompt("Print reason (original / copy / reissue / duplicate):", "original");
    if (!reason || !["original", "copy", "reissue", "duplicate"].includes(reason)) { showAlert(alertBox, "Reason must be original, copy, reissue or duplicate."); return; }
    const reprint_reason = reason === "original" ? null : (prompt("Reprint reason:") || "");
    try {
      const res = await API.post("/documents/" + docUuid + "/print-jobs", { reason, reprint_reason });
      showAlert(alertBox, "Print job " + res.job_number + " queued.", "success");
    } catch (err) { showAlert(alertBox, err.message); }
  }
}

/* ============================ PRINT QUEUE ============================ */

async function showPrintJobsTab() {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const data = await API.get("/print-jobs");

  pane.append(el("h3", { text: "Print queue" }));
  pane.append(el("p", { class: "muted", text: (data.pending || 0) + " job(s) pending — queued or printing." }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Job" }), el("th", { text: "Document" }), el("th", { text: "Application" }), el("th", { text: "Citizen" }),
    el("th", { text: "Reason" }), el("th", { text: "Status" }), el("th", { text: "Attempts" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const j of data.print_jobs || []) {
    tbody.append(el("tr", {}, [
      el("td", { class: "mono", text: j.job_number }),
      el("td", { class: "mono", text: j.document_number }),
      el("td", { class: "mono", text: j.application_number || "—" }),
      el("td", { text: j.citizen_name || "—" }),
      el("td", { text: j.reason + (j.reprint_reason ? " — " + j.reprint_reason : "") }),
      el("td", {}, [badge(j.status)]),
      el("td", { text: j.attempts }),
      el("td", { class: "row", style: "gap:.35rem" }, [
        el("button", { class: "btn btn-sm", text: "Start", disabled: !["queued", "quality_failed"].includes(j.status) ? "disabled" : null, onclick: () => jobAction(j.id, "start") }),
        el("button", { class: "btn btn-sm", text: "Complete", disabled: j.status !== "printing" ? "disabled" : null, onclick: () => jobAction(j.id, "complete") }),
        el("button", { class: "btn btn-sm btn-danger", text: "Fail quality", disabled: j.status !== "printing" ? "disabled" : null, onclick: () => jobAction(j.id, "fail") }),
        el("button", { class: "btn btn-sm btn-ghost", text: "Cancel", disabled: ["printed", "cancelled"].includes(j.status) ? "disabled" : null, onclick: () => jobAction(j.id, "cancel") }),
      ]),
    ]));
  }
  table.append(tbody);
  pane.append(table);
  if (!(data.print_jobs || []).length) {
    pane.append(el("p", { class: "muted mt-1", text: "No print jobs yet — queue one from the Documents tab." }));
  }

  async function jobAction(id, action) {
    const body = { action };
    if (action === "fail") {
      const reason = prompt("Reason for print quality failure:");
      if (!reason) return;
      body.reprint_reason = reason;
    }
    try {
      const res = await API.post("/print-jobs/" + id + "/update", body);
      showAlert(alertBox, "Print job " + res.job_number + " → " + (res.status || action) + ".", "success");
      showPrintJobsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  }
}

async function showDocumentDetail(uuid) {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const doc = await API.get("/documents/" + uuid);
  pane.append(el("h3", { text: "Document " + doc.document_number }));
  pane.append(el("button", { class: "btn btn-ghost mt-1", text: "Back", onclick: showDocumentsTab }));
  const card = el("div", { class: "card mt-1" });
  card.append(el("div", { class: "grid grid-3" }, [
    el("div", {}, [
      el("label", { text: "Type" }), el("div", { class: "mono", text: doc.document_type }),
      el("label", { text: "Title" }), el("div", { text: doc.title }),
      el("label", { text: "Status" }), el("div", {}, [badge(doc.status)]),
    ]),
    el("div", {}, [
      el("label", { text: "Verification code" }), el("div", { class: "mono", text: doc.verification_code || "not signed yet" }),
      el("label", { text: "Document hash (SHA-256)" }),
      el("div", { class: "mono muted", style: "font-size:.72rem; word-break:break-all", text: doc.file_hash || "—" }),
    ]),
    el("div", {}, [
      el("label", { text: "Issued (Ethiopian)" }), el("div", { text: doc.issued_at_eth || "—" }),
      el("label", { text: "Issued (Gregorian)" }), el("div", { text: doc.issued_at_greg || "—" }),
    ]),
  ]));
  if (doc.signature) {
    card.append(el("div", { class: "mt-1" }, [
      el("strong", { text: "Signed by: " }), el("span", { text: doc.signature.signer }),
      el("span", { class: "muted", text: "  @ " + fmtDate(doc.signature.signed_at) + "  (" + doc.signature.hash_algorithm + ")" }),
    ]));
  }
  if (doc.verification_code) {
    const verifyUrl = API.getVerifyUrl ? API.getVerifyUrl(doc.verification_code) : (window.location.origin + "/verify?code=" + encodeURIComponent(doc.verification_code));
    card.append(el("div", { class: "mt-2" }, [
      el("label", { text: "Verification QR" }),
      el("div", {}, [el("canvas", { id: "doc-qr", width: "140", height: "140" })]),
      el("p", { class: "muted mono", style: "font-size:.75rem", text: verifyUrl }),
    ]));
    setTimeout(() => { if (window.QR) QR.drawInto(document.getElementById("doc-qr"), verifyUrl, 4, 2); }, 50);
  }
  pane.append(card);
}

/* ============================ APPOINTMENTS ============================ */

async function showAppointmentsTab() {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const [offices, services, appts] = await Promise.all([
    API.get("/offices"),
    API.get("/services"),
    API.get("/appointments"),
  ]);

  pane.append(el("h3", { text: "Book an appointment" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Office *" }),
        el("select", { name: "office_id", required: true }, offices.offices.map(o => el("option", { value: o.id, text: (o.admin_unit_name || "") + " — " + o.name }))),
        el("label", { text: "Service *" }),
        el("select", { name: "service_id", required: true }, (services.services || []).map(s => el("option", { value: s.id, text: s.name }))),
      ]),
      el("div", {}, [
        el("label", { text: "Date" }), el("input", { name: "date", type: "date", value: new Date().toISOString().slice(0, 10) }),
        el("label", { text: "Citizen UUID (book on behalf of citizen)" }),
        el("input", { name: "citizen_uuid", placeholder: "UUID" }),
        el("p", { class: "muted", style: "font-size:.75rem; margin-top:.35rem", text: "Find via Residents → Search." }),
      ]),
      el("div", {}, [
        el("label", { text: "Available slots" }),
        el("button", { class: "btn btn-sm", type: "button", text: "Load slots", onclick: loadSlots }),
        el("div", { id: "slot-picker", class: "row mt-1", style: "gap:.4rem; flex-wrap:wrap" }),
        el("input", { name: "slot_start", style: "display:none" }),
        el("input", { name: "slot_end", style: "display:none" }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Book appointment" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!form.elements.slot_start.value) { showAlert(alertBox, "Load slots and pick one first."); return; }
    try {
      const res = await API.post("/appointments", {
        office_id: form.elements.office_id.value,
        service_id: form.elements.service_id.value,
        slot_start: form.elements.slot_start.value,
        slot_end: form.elements.slot_end.value,
        citizen_uuid: form.elements.citizen_uuid.value.trim() || null,
      });
      showAlert(alertBox, "Appointment booked (" + res.status + ").", "success");
      showAppointmentsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.append(form);

  async function loadSlots() {
    const officeId = form.elements.office_id.value;
    const date = form.elements.date.value;
    if (!officeId) return;
    try {
      const data = await API.get("/appointments/slots?office_id=" + encodeURIComponent(officeId) + "&date=" + encodeURIComponent(date));
      const picker = form.querySelector("#slot-picker");
      picker.innerHTML = "";
      if (!data.slots.length) { picker.append(el("p", { class: "muted", text: "No free slots on " + date })); return; }
      for (const s of data.slots) {
        const btn = el("button", { class: "btn btn-sm btn-ghost", type: "button", text: s.start.slice(11, 16) + "–" + s.end.slice(11, 16) });
        btn.addEventListener("click", () => {
          picker.querySelectorAll("button").forEach(b => b.classList.remove("btn-gold"));
          btn.classList.add("btn-gold");
          form.elements.slot_start.value = s.start;
          form.elements.slot_end.value = s.end;
        });
        picker.append(btn);
      }
    } catch (err) { showAlert(alertBox, err.message); }
  }

  pane.append(el("h3", { class: "mt-2", text: "Appointments" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Office" }), el("th", { text: "Citizen" }), el("th", { text: "Slot" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const a of appts.appointments || []) {
    tbody.append(el("tr", {}, [
      el("td", { text: a.office_name || "—" }),
      el("td", { text: a.citizen_name || "—" }),
      el("td", { class: "mono", text: fmtDate(a.slot_start) }),
      el("td", {}, [badge(a.status)]),
      el("td", { class: "row", style: "gap:.35rem" }, [
        el("button", { class: "btn btn-sm", text: "Confirm", disabled: a.status !== "booked" ? "disabled" : null, onclick: async () => {
          try { await API.post("/appointments/" + a.id + "/confirm", {}); showAppointmentsTab(); } catch (err) { showAlert(alertBox, err.message); }
        } }),
        el("button", { class: "btn btn-sm", text: "Check in", disabled: a.status !== "confirmed" ? "disabled" : null, onclick: async () => {
          try { await API.post("/appointments/" + a.id + "/check-in", {}); showAppointmentsTab(); } catch (err) { showAlert(alertBox, err.message); }
        } }),
        el("button", { class: "btn btn-sm", text: "Finish", disabled: a.status !== "checked_in" ? "disabled" : null, onclick: async () => {
          try { await API.post("/appointments/" + a.id + "/finish", { outcome: "complete" }); showAppointmentsTab(); } catch (err) { showAlert(alertBox, err.message); }
        } }),
        el("button", { class: "btn btn-sm btn-ghost", text: "Cancel", disabled: !["booked", "confirmed"].includes(a.status) ? "disabled" : null, onclick: async () => {
          try { await API.del("/appointments/" + a.id); showAppointmentsTab(); } catch (err) { showAlert(alertBox, err.message); }
        } }),
      ]),
    ]));
  }
  table.append(tbody);
  pane.append(table);
  if (!(appts.appointments || []).length) {
    pane.append(el("p", { class: "muted mt-1", text: "No appointments yet." }));
  }
}

/* ============================ NOTIFICATIONS ============================ */

async function showNotificationsTab() {
  const pane = document.getElementById("digital-pane");
  pane.innerHTML = "";
  const inbox = await API.get("/notifications");

  pane.append(el("h3", { text: "Send notification" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Recipient citizen UUID" }), el("input", { name: "citizen_id", placeholder: "UUID" }),
        el("label", { text: "Recipient user UUID (optional override)" }), el("input", { name: "user_id", placeholder: "UUID" }),
      ]),
      el("div", {}, [
        el("label", { text: "Subject" }), el("input", { name: "subject", placeholder: "Residence certificate ready" }),
        el("label", { text: "Channel" }),
        el("select", { name: "channel" }, ["in_app", "sms", "email"].map(c => el("option", { value: c, text: c }))),
      ]),
      el("div", {}, [
        el("label", { text: "Body *" }), el("textarea", { name: "body", rows: 3, required: true, placeholder: "Your certificate LOC-DOC-... is ready for pickup." }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Send notification" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const res = await API.post("/notifications", {
        citizen_id: form.elements.citizen_id.value.trim() || null,
        user_id: form.elements.user_id.value.trim() || null,
        subject: form.elements.subject.value.trim() || null,
        body: form.elements.body.value.trim(),
        channel: form.elements.channel.value,
      });
      showAlert(alertBox, "Notification sent (" + res.status + ").", "success");
      showNotificationsTab();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  pane.append(form);

  pane.append(el("h3", { class: "mt-2", text: "Inbox" }));
  const row = el("div", { class: "row mt-1", style: "justify-content:space-between" }, [
    el("span", { class: "muted", text: (inbox.notifications || []).length + " notification(s)" }),
    el("button", { class: "btn btn-sm", text: "Mark all read", onclick: async () => {
      try { await API.post("/notifications/read-all", {}); showNotificationsTab(); } catch (err) { showAlert(alertBox, err.message); }
    } }),
  ]);
  pane.append(row);
  for (const n of inbox.notifications || []) {
    const card = el("div", { class: "card mt-1 row", style: "justify-content:space-between; gap:1rem" }, [
      el("div", {}, [
        el("div", { class: "row", style: "gap:.5rem; align-items:center" }, [
          badge(n.channel),
          badge(n.status === "read" ? "read" : "unread"),
          el("strong", { text: n.subject || "Notification" }),
          el("span", { class: "muted", style: "font-size:.78rem", text: fmtDate(n.created_at) }),
        ]),
        el("div", { class: "mt-1", text: n.body }),
      ]),
      el("button", {
        class: "btn btn-sm btn-ghost",
        text: "Mark read",
        disabled: n.status === "read" ? "disabled" : null,
        onclick: async () => {
          try { await API.post("/notifications/" + n.id, {}); showNotificationsTab(); } catch (err) { showAlert(alertBox, err.message); }
        },
      }),
    ]);
    pane.append(card);
  }
  if (!(inbox.notifications || []).length) {
    pane.append(el("p", { class: "muted mt-1", text: "No notifications yet." }));
  }
}