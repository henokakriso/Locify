<?php $title = 'Citizen Portal'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><span class="mark"></span><span>LOCIFY</span></div>
    <nav class="nav" id="nav">
      <a href="#" data-view="services" class="active">Services / አገልግሎቶች</a>
      <a href="#" data-view="applications">My Applications / ማመልከቻዎቼ</a>
      <a href="#" data-view="documents">My Documents / ሰነዶቼ</a>
      <a href="#" data-view="appointments">Appointments / ቀጠሮ</a>
      <a href="#" data-view="complaints">Complaints / ቅሬታ</a>
      <a href="#" data-view="notifications">Notifications / ማሳወቂያ <span id="notif-badge" class="nav-badge" hidden></span></a>
      <a href="#" data-view="messages">Messages / መልዕክቶች <span id="chat-badge" class="nav-badge" hidden></span></a>
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
  services: "Available Services", applications: "My Applications", documents: "My Documents",
  appointments: "Appointments", complaints: "Complaints", notifications: "Notifications",
  messages: "Messages",
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
    const card = el("div", { class: "card" }, [
      el("div", { class: "row spread" }, [
        el("strong", { text: s.local_name || s.name }),
        el("span", { class: "badge badge-green", text: s.currency + " " + s.fee_amount }),
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

function showApplicationForm(service) {
  content.innerHTML = "";
  const form = el("form", {}, [
    el("h2", { text: (service.local_name || service.name) + " — Application" }),
    el("label", { text: "Comments / additional information" }),
    el("textarea", { name: "form", rows: 4, placeholder: "Optional details for this application" }),
    el("div", { class: "row mt-2" }, [
      el("button", { class: "btn", type: "submit", text: "Submit application" }),
      el("button", { class: "btn btn-ghost", type: "button", text: "Back", onclick: loadServices }),
    ]),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const created = await API.post("/services/applications", {
        service_id: service.id,
        form: { note: form.elements.form.value },
      });
      pendingHighlight = created.uuid || created.id;
      showAlert(alertBox, "Application submitted — showing it under My Applications.", "success");
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
    el("th", { text: "Status" }), el("th", { text: "Step" }), el("th", { text: "Submitted" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const a of data.applications) {
    const actions = [];
    if (a.status === "submitted" || a.status === "in_review") {
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
      el("td", { class: "muted", text: a.current_step || "—" }),
      el("td", { class: "muted", text: fmtDate(a.created_at) }),
      el("td", {}, actions),
    ]));
  }
  table.append(tbody);
  content.append(table);
  applyHighlight(content);
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
        d.status === "issued" ? el("a", {
          class: "btn btn-sm", text: "Verify", href: "/verify", target: "_blank",
          onclick: undefined,
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
  services: loadServices, applications: loadApplications, documents: loadDocuments,
  appointments: loadAppointments, complaints: loadComplaints, notifications: loadNotifications,
  messages: loadMessages,
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
