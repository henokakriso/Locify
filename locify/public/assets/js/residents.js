/* ============================================================
   LOCIFY — Resident management UI (admin dashboard)
   Depends on api.js (API, el, badge, showAlert, fmtDate).
   ============================================================ */

"use strict";

let residentUnits = [];

function unitOptions(units) {
  return (units || []).filter(u => u.status === "active").map(u =>
    el("option", { value: u.id, text: u.type + ": " + u.name }));
}

async function loadResidents() {
  const units = await API.get("/admin/units");
  residentUnits = units.admin_units || [];
  content.innerHTML = "";
  content.append(el("h2", { text: "Resident management" }));
  const row = el("div", { class: "row mt-1" }, [
    el("button", { class: "btn btn-gold", text: "Register resident", onclick: showRegisterResident }),
    el("button", { class: "btn", text: "Households", onclick: showHouseholds }),
    el("button", { class: "btn", text: "Search resident", onclick: showResidentSearch }),
  ]);
  content.append(row);
  content.append(el("p", { class: "muted mt-2",
    text: "Register residents with residence and household records; record move-in/move-out history; manage family households and verify resident documents." }));
}

function showRegisterResident() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Register resident" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "First name *" }), el("input", { name: "first_name", required: true }),
        el("label", { text: "Middle name" }), el("input", { name: "middle_name" }),
        el("label", { text: "Last name *" }), el("input", { name: "last_name", required: true }),
      ]),
      el("div", {}, [
        el("label", { text: "National ID" }), el("input", { name: "national_id" }),
        el("label", { text: "Date of birth (Ethiopian)" }), el("input", { name: "dob_eth", placeholder: "YYYY-MM-DD" }),
        el("label", { text: "Sex" }),
        el("select", { name: "sex" }, ["", "M", "F", "O"].map(s => el("option", { value: s, text: s }))),
      ]),
      el("div", {}, [
        el("label", { text: "Administrative unit *" }),
        el("select", { name: "admin_unit_id", required: true }, unitOptions(residentUnits)),
        el("label", { text: "Village / sub-locality" }), el("input", { name: "village" }),
        el("label", { text: "House number" }), el("input", { name: "house_no" }),
      ]),
    ]),
    el("div", { class: "grid grid-3 mt-1" }, [
      el("div", {}, [
        el("label", { text: "Household number (optional)" }),
        el("input", { name: "household_no", placeholder: "HH-...-000123" }),
      ]),
      el("div", {}, [
        el("label", { text: "Moved-in on (defaults to today)" }),
        el("input", { name: "moved_on", placeholder: "YYYY-MM-DD" }),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Register resident" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const res = await API.post("/residents", {
        first_name: form.elements.first_name.value.trim(),
        middle_name: form.elements.middle_name.value.trim() || null,
        last_name: form.elements.last_name.value.trim(),
        national_id: form.elements.national_id.value.trim() || null,
        dob_eth: form.elements.dob_eth.value.trim() || null,
        sex: form.elements.sex.value || null,
        household_no: form.elements.household_no.value.trim() || null,
        moved_on: form.elements.moved_on.value.trim() || null,
        address: {
          admin_unit_id: form.elements.admin_unit_id.value,
          village: form.elements.village.value.trim() || null,
          house_no: form.elements.house_no.value.trim() || null,
        },
      });
      showAlert(alertBox, "Resident registered with move-in record"
        + (res.household_id ? " (household attached)" : ""), "success");
      showResidentProfile(res.uuid);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
}

function showResidentSearch() {
  content.innerHTML = "";
  content.append(el("h2", { text: "Search resident" }));
  content.append(el("p", { class: "muted", text: "Search by exact national ID (names are encrypted at rest)." }));
  const form = el("form", { class: "card mt-1 row", style: "gap:.5rem" }, [
    el("input", { name: "national_id", placeholder: "National ID", required: true, style: "max-width:280px" }),
    el("button", { class: "btn", type: "submit", text: "Search" }),
  ]);
  const resultsBox = el("div", { class: "mt-1" });
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const data = await API.get("/citizens/search?national_id=" + encodeURIComponent(form.elements.national_id.value.trim()));
      resultsBox.innerHTML = "";
      if (!data.results.length) {
        resultsBox.append(el("p", { class: "muted", text: "No resident found with that national ID." }));
        return;
      }
      for (const r of data.results) {
        const btn = el("button", { class: "btn btn-sm btn-ghost", text: "Profile" });
        btn.addEventListener("click", () => showResidentProfile(r.uuid));
        resultsBox.append(el("div", { class: "card mt-1 row", style: "justify-content:space-between" }, [
          el("div", {}, [
            el("strong", { text: r.name }),
            el("span", { class: "mono muted", text: "  NID " + (r.national_id_mask || "—") }),
            badge(r.status),
          ]),
          btn,
        ]));
      }
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);
  content.append(resultsBox);
}

function showResidentProfile(uuid) {
  content.innerHTML = "";
  content.append(el("h2", { text: "Resident profile" }));
  content.append(el("div", { id: "resident-box" }, [el("p", { class: "muted", text: "Loading…" })]));
  content.append(el("button", { class: "btn btn-ghost mt-1", text: "Back to search", onclick: showResidentSearch }));
  Promise.all([
    API.get("/residents/" + uuid),
    API.get("/residents/" + uuid + "/history"),
    API.get("/residents/" + uuid + "/verifications"),
  ]).then(([profile, hist, verif]) => renderResidentProfile(uuid, profile, hist, verif))
    .catch(err => showAlert(alertBox, err.message));
}

function renderResidentProfile(uuid, profile, hist, verif) {
  const box = document.getElementById("resident-box");
  if (!box) return;
  box.innerHTML = "";

  box.append(el("div", { class: "card" }, [
    el("h3", { text: profile.name || "—" }),
    el("div", { class: "row mt-1", style: "gap:.5rem; flex-wrap:wrap" }, [
      badge(profile.status),
      el("span", { class: "badge badge-blue", text: "ID " + (profile.national_id_mask || "—") }),
      el("span", {
        class: "badge badge-" + (profile.identity_verification === "success" ? "green" : "gold"),
        text: "identity: " + profile.identity_verification,
      }),
      el("span", { class: "muted", text: "DOB (Eth) " + (profile.dob_eth || "—") }),
      el("span", { class: "muted", text: "Sex " + (profile.sex || "—") }),
    ]),
    el("div", { class: "mt-1" }, [
      el("strong", { text: "Current residence: " }),
      el("span", { text: [profile.admin_unit_name, profile.residence?.village, profile.residence?.house_no ? "House " + profile.residence.house_no : null].filter(Boolean).join(" · ") || "—" }),
    ]),
    el("div", { class: "mt-1" }, [
      el("strong", { text: "Households: " }),
      el("span", { text: profile.households?.length
        ? profile.households.map(h => h.household_no + " (" + h.member_role + ")").join(", ")
        : "none" }),
    ]),
  ]));

  box.append(el("h3", { class: "mt-2", text: "Record a move" }));
  const moveForm = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Type" }),
        el("select", { name: "move_type" }, ["move_in", "move_out", "transfer"].map(t =>
          el("option", { value: t, text: t.replace(/_/g, " ") }))),
      ]),
      el("div", {}, [
        el("label", { text: "Target unit (move-in / transfer)" }),
        el("select", { name: "to_admin_unit_id", disabled: true }, unitOptions(residentUnits)),
      ]),
      el("div", {}, [
        el("label", { text: "Effective date" }), el("input", { name: "moved_on", placeholder: "YYYY-MM-DD" }),
      ]),
    ]),
    el("label", { class: "mt-1", text: "Reason" }), el("input", { name: "reason" }),
    el("button", { class: "btn btn-gold mt-1", type: "submit", text: "Record move" }),
  ]);
  const typeSel = moveForm.elements.move_type;
  const toSel = moveForm.elements.to_admin_unit_id;
  const syncTarget = () => { toSel.disabled = typeSel.value === "move_out"; };
  typeSel.addEventListener("change", syncTarget);
  moveForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/residents/" + uuid + "/move", {
        move_type: typeSel.value,
        to_admin_unit_id: toSel.disabled ? null : toSel.value,
        moved_on: moveForm.elements.moved_on.value || null,
        reason: moveForm.elements.reason.value.trim() || null,
      });
      showAlert(alertBox, "Move recorded.", "success");
      showResidentProfile(uuid);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  box.append(moveForm);

  box.append(el("h3", { class: "mt-2", text: "History timeline" }));
  const list = el("ul", { class: "card mt-1", style: "list-style:none; padding:1rem" });
  for (const item of hist.timeline || []) {
    const label = {
      residence: "Residence",
      move: "Move: " + (item.data.move_type || "").replace(/_/g, " "),
      verification: "Identity check",
      document: "Document",
    }[item.type] || item.type;
    let detail = "";
    if (item.type === "move") {
      detail = "From " + (item.data.from || "outside system") + " to " + (item.data.to || "—")
        + (item.data.reason ? " (" + item.data.reason + ")" : "");
    } else if (item.type === "residence") {
      detail = [item.data.admin_unit, item.data.village, item.data.house_no ? "House " + item.data.house_no : null].filter(Boolean).join(" · ")
        + (item.data.ended_at ? " — until " + item.data.ended_at : " — current");
    } else if (item.type === "document") {
      detail = item.data.document_number + " · " + item.data.document_type + " · " + item.data.status;
    } else {
      detail = (item.data.verification_type || "") + " · " + (item.data.status || "")
        + (item.data.verified_at ? " · " + fmtDate(item.data.verified_at) : "");
    }
    list.append(el("li", { class: "mt-1" }, [
      el("strong", { text: item.date + " — " + label }),
      el("div", { class: "muted", text: detail }),
    ]));
  }
  box.append(list);

  box.append(el("h3", { class: "mt-2", text: "Documents & verification" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Document" }), el("th", { text: "Type" }), el("th", { text: "Status" }), el("th", { text: "Verification code" }),
  ])])]);
  const tbody = el("tbody");
  for (const d of verif.documents || []) {
    tbody.append(el("tr", {}, [
      el("td", { class: "mono", text: d.document_number }),
      el("td", { text: d.document_type }),
      el("td", {}, [badge(d.status)]),
      el("td", { class: "mono muted", text: d.verification_code || "—" }),
    ]));
  }
  table.append(tbody);
  box.append(table);
  if (!(verif.documents || []).length) {
    box.append(el("p", { class: "muted mt-1", text: "No documents issued yet." }));
  }
}

async function showHouseholds() {
  const data = await API.get("/households");
  content.innerHTML = "";
  content.append(el("h2", { text: "Households (" + (data.count || 0) + ")" }));
  content.append(el("h3", { class: "mt-2", text: "Create household" }));
  const form = el("form", { class: "card mt-1" }, [
    el("div", { class: "grid grid-3" }, [
      el("div", {}, [
        el("label", { text: "Administrative unit *" }),
        el("select", { name: "admin_unit_id", required: true }, unitOptions(residentUnits)),
      ]),
      el("div", {}, [
        el("label", { text: "Head of household citizen UUID *" }),
        el("input", { name: "head_citizen_uuid", required: true, placeholder: "UUID" }),
        el("p", { class: "muted", style: "font-size:.78rem; margin-top:.35rem", text: "Find the UUID via Search resident → profile." }),
      ]),
      el("div", {}, [
        el("label", { text: "Household name" }), el("input", { name: "name" }),
        el("div", { class: "grid grid-2" }, [
          el("div", {}, [el("label", { text: "Village" }), el("input", { name: "village" })]),
          el("div", {}, [el("label", { text: "House no" }), el("input", { name: "house_no" })]),
        ]),
      ]),
    ]),
    el("button", { class: "btn btn-gold mt-2", type: "submit", text: "Create household" }),
  ]);
  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const res = await API.post("/households", {
        admin_unit_id: form.elements.admin_unit_id.value,
        head_citizen_uuid: form.elements.head_citizen_uuid.value.trim(),
        name: form.elements.name.value.trim() || null,
        village: form.elements.village.value.trim() || null,
        house_no: form.elements.house_no.value.trim() || null,
      });
      showAlert(alertBox, "Household " + res.household_no + " created", "success");
      showHouseholds();
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(form);

  content.append(el("h3", { class: "mt-2", text: "Existing households" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Household no" }), el("th", { text: "Name" }), el("th", { text: "Unit" }),
    el("th", { text: "Members" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const h of data.households || []) {
    const viewBtn = el("button", { class: "btn btn-sm btn-ghost", text: "View members" });
    viewBtn.addEventListener("click", () => showHouseholdDetail(h.id));
    tbody.append(el("tr", {}, [
      el("td", { class: "mono", text: h.household_no }),
      el("td", { text: h.name || "—" }),
      el("td", { text: h.admin_unit_name }),
      el("td", { text: String(h.member_count || 0) }),
      el("td", {}, [badge(h.status)]),
      el("td", {}, [viewBtn]),
    ]));
  }
  table.append(tbody);
  content.append(table);
}

async function showHouseholdDetail(id) {
  const data = await API.get("/households/" + id);
  const h = data.household;
  content.innerHTML = "";
  content.append(el("h2", { text: "Household " + h.household_no }));
  content.append(el("p", { class: "muted", text: [h.name, h.admin_unit_name, h.village ? "Village " + h.village : null, h.house_no ? "House " + h.house_no : null].filter(Boolean).join(" · ") || " " }));
  content.append(el("button", { class: "btn btn-ghost mt-1", text: "Back to households", onclick: showHouseholds }));

  content.append(el("h3", { class: "mt-2", text: "Add member" }));
  const addForm = el("form", { class: "card mt-1 row", style: "gap:.5rem; flex-wrap:wrap" }, [
    el("input", { name: "citizen_uuid", placeholder: "Citizen UUID", required: true, style: "max-width:300px" }),
    el("select", { name: "member_role" }, ["other", "spouse", "child", "parent", "sibling", "head"].map(r =>
      el("option", { value: r, text: r }))),
    el("button", { class: "btn", type: "submit", text: "Add member" }),
  ]);
  addForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await API.post("/households/" + id + "/members", {
        citizen_uuid: addForm.elements.citizen_uuid.value.trim(),
        member_role: addForm.elements.member_role.value,
      });
      showAlert(alertBox, "Member added", "success");
      showHouseholdDetail(id);
    } catch (err) { showAlert(alertBox, err.message); }
  });
  content.append(addForm);

  content.append(el("h3", { class: "mt-2", text: "Members" }));
  const table = el("table", { class: "mt-1" }, [el("thead", {}, [el("tr", {}, [
    el("th", { text: "Name" }), el("th", { text: "Role" }), el("th", { text: "Joined" }), el("th", { text: "Status" }), el("th", { text: "Actions" }),
  ])])]);
  const tbody = el("tbody");
  for (const m of h.members || []) {
    const removeBtn = el("button", {
      class: "btn btn-sm btn-danger",
      text: "Remove",
      disabled: m.member_role === "head" ? "disabled" : null,
    });
    removeBtn.addEventListener("click", async () => {
      if (!confirm("Remove " + m.name + " from this household? (history is kept)")) return;
      try {
        await API.del("/households/" + id + "/members/" + m.membership_id);
        showAlert(alertBox, "Member removed", "success");
        showHouseholdDetail(id);
      } catch (err) { showAlert(alertBox, err.message); }
    });
    tbody.append(el("tr", {}, [
      el("td", { text: m.name + (h.head_citizen_uuid === m.citizen_uuid ? " (head)" : "") }),
      el("td", {}, [el("span", { class: "badge badge-blue", text: m.member_role })]),
      el("td", { text: m.joined_at || "—" }),
      el("td", {}, [m.left_at ? badge("inactive") : badge("active")]),
      el("td", {}, [removeBtn]),
    ]));
  }
  table.append(tbody);
  content.append(table);
}