<?php $title = 'Login'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand">
      <span class="mark"></span>
      <span>LOCIFY</span>
    </div>
    <h1 style="font-size:1.2rem; margin-bottom:1rem">Sign in / ይግቡ</h1>
    <div id="alert"></div>
    <form id="login-form" novalidate>
      <label for="username">Username / የተጠቃሚ ስም</label>
      <input id="username" name="username" autocomplete="username" required>

      <label for="password">Password / የይለፍ ቃል</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>

      <div id="mfa-field" class="mfa-field" hidden>
        <label for="code">2FA code / የሁለት ደረጃ ኮድ</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code or recovery code">
        <p class="muted" style="font-size:.78rem; margin-top:.35rem">Enter the code from your authenticator app, or a recovery code.</p>
      </div>

      <button class="btn" style="width:100%; justify-content:center; margin-top:1.3rem" type="submit" id="submit-btn">
        Sign in / ግባ
      </button>
    </form>
    <p class="muted mt-2" style="text-align:center">
      <a href="/register">Create an account</a> · <a href="/verify">Verify a document</a> · <a href="/">Home</a>
    </p>
  </div>
</div>

<script src="/assets/js/api.js"></script>
<script>
const form = document.getElementById("login-form");
const alertBox = document.getElementById("alert");
const btn = document.getElementById("submit-btn");
const mfaField = document.getElementById("mfa-field");
const codeInput = document.getElementById("code");
let mfaToken = null;

function redirectAfterLogin() {
  const user = API.getUser();
  const role = user.roles[0]?.name || "citizen";
  const target = role === "citizen" ? "/portal"
    : (["system_admin", "kebele_admin", "woreda_admin"].includes(role) ? "/admin" : "/officer");
  window.location.href = target;
}

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  btn.disabled = true;
  try {
    if (mfaToken !== null) {
      await API.verifyMfa(mfaToken, codeInput.value.trim());
      redirectAfterLogin();
      return;
    }
    await API.login(
      document.getElementById("username").value.trim(),
      document.getElementById("password").value
    );
    redirectAfterLogin();
  } catch (err) {
    if (err.code === "MFA_REQUIRED" && err.data?.error?.details?.mfa_token) {
      mfaToken = err.data.error.details.mfa_token;
      mfaField.hidden = false;
      codeInput.focus();
      showAlert(alertBox, err.message, "info");
      btn.textContent = "Verify / አረጋግጥ";
    } else {
      showAlert(alertBox, err.message, "error");
    }
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
