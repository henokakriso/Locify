<?php $title = 'Create Account'; require __DIR__ . '/../partials/head.php'; ?>
<body class="auth-page">
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="brand center"><span class="mark"></span><span>LOCIFY</span></div>
      <h1 class="center">Citizen Portal Registration</h1>
      <p class="muted center">Register to apply for digital kebele services. A kebele officer verifies your identity before you can apply.</p>
      <div id="alert"></div>
      <form id="register-form">
        <label>First name (required)</label>
        <input name="first_name" required maxlength="80" autocomplete="given-name">
        <label>Middle name</label>
        <input name="middle_name" maxlength="80" autocomplete="additional-name">
        <label>Last name (required)</label>
        <input name="last_name" required maxlength="80" autocomplete="family-name">
        <div class="grid grid-2">
          <div>
            <label>Date of birth (E.C.)</label>
            <input name="dob_eth" type="text" placeholder="YYYY-MM-DD (Ethiopian calendar)">
          </div>
          <div>
            <label>Sex</label>
            <select name="sex">
              <option value="F">Female</option>
              <option value="M">Male</option>
              <option value="O">Other</option>
            </select>
          </div>
        </div>
        <label>Phone number</label>
        <input name="phone" type="tel" maxlength="20" autocomplete="tel">
        <label>Kebele</label>
        <select name="admin_unit_id" id="kebele-select" required>
          <option value="">Loading kebeles…</option>
        </select>
        <div class="grid grid-2">
          <div>
            <label>House number</label>
            <input name="house_no" maxlength="40">
          </div>
          <div>
            <label>Village / area</label>
            <input name="village" maxlength="80">
          </div>
        </div>
        <label>Username (required)</label>
        <input name="username" required minlength="3" maxlength="40" autocomplete="username">
        <label>Password (min 8 characters)</label>
        <input name="password" type="password" required minlength="8" autocomplete="new-password">
        <button class="btn btn-gold mt-2" type="submit">Create account</button>
      </form>
      <p class="muted center mt-2">Already registered? <a href="/login">Sign in</a></p>
    </div>
  </div>
  <script src="/assets/js/api.js"></script>
  <script>
    const alertBox = document.getElementById("alert");
    const form = document.getElementById("register-form");
    const kebeleSelect = document.getElementById("kebele-select");

    fetch(API.base + "/portal/units")
      .then(r => r.json())
      .then(d => {
        kebeleSelect.innerHTML = "";
        for (const u of (d.units || [])) {
          kebeleSelect.append(el("option", { value: u.id, text: (u.local_name || u.name) + " (" + u.code + ")" }));
        }
      })
      .catch(() => { kebeleSelect.innerHTML = '<option value="">Kebele list unavailable — try again later</option>'; });

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const body = {};
      for (const f of form.elements) {
        if (f.name) body[f.name] = f.value;
      }
      try {
        const res = await fetch(API.base + "/portal/register", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!res.ok) {
          const details = data.error?.details ? Object.entries(data.error.details).map(([k, v]) => k + ": " + v).join("; ") : "";
          showAlert(alertBox, (data.error?.message || "Registration failed") + (details ? " — " + details : ""));
          return;
        }
        showAlert(alertBox, data.message || "Account created.", "success");
        setTimeout(() => { window.location.href = "/login"; }, 2500);
      } catch (err) {
        showAlert(alertBox, err.message || "Network error");
      }
    });
  </script>
</body>
</html>
