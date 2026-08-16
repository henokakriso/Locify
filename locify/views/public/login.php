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

      <button class="btn" style="width:100%; justify-content:center; margin-top:1.3rem" type="submit" id="submit-btn">
        Sign in / ግባ
      </button>
    </form>
    <p class="muted mt-2" style="text-align:center">
      <a href="/verify">Verify a document</a> · <a href="/">Home</a>
    </p>
  </div>
</div>

<script src="/assets/js/api.js"></script>
<script>
const form = document.getElementById("login-form");
const alertBox = document.getElementById("alert");
const btn = document.getElementById("submit-btn");

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  btn.disabled = true;
  try {
    await API.login(
      document.getElementById("username").value.trim(),
      document.getElementById("password").value
    );
    const user = API.getUser();
    const role = user.roles[0]?.name || "citizen";
    const target = role === "citizen" ? "/portal"
      : (["system_admin", "kebele_admin", "woreda_admin"].includes(role) ? "/admin" : "/officer");
    window.location.href = target;
  } catch (err) {
    showAlert(alertBox, err.message, "error");
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
