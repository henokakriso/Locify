<?php $title = 'Document Verification'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand">
      <span class="mark"></span>
      <span>LOCIFY</span>
    </div>
    <h1 style="font-size:1.2rem; margin-bottom:0.2rem">Document Verification / ሰነድ ማረጋገጫ</h1>
    <p class="muted" style="margin-bottom:1rem">
      Enter the verification code from the document's QR code.
      No personal data is displayed.
    </p>

    <div id="alert"></div>

    <form id="verify-form">
      <label for="code">Verification code / የማረጋገጫ ኮድ</label>
      <input id="code" name="code" placeholder="XXXX-XXXX-XXXX" style="font-family:var(--mono); letter-spacing:0.1em" required>
      <button class="btn" style="width:100%; justify-content:center; margin-top:1.2rem" type="submit" id="verify-btn">
        Verify / አረጋግጥ
      </button>
    </form>

    <div id="result" class="hidden"></div>

    <p class="muted mt-2" style="text-align:center"><a href="/">Home</a></p>
  </div>
</div>

<script src="/assets/js/api.js"></script>
<script>
const form = document.getElementById("verify-form");
const alertBox = document.getElementById("alert");
const resultBox = document.getElementById("result");
const btn = document.getElementById("verify-btn");

function row(label, value) {
  return el("div", { class: "row spread", style: "padding:0.3rem 0; border-bottom:1px solid var(--line)" },
    [el("span", { class: "muted", text: label }), el("span", { class: "mono", text: value })]);
}

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  btn.disabled = true;
  resultBox.className = "hidden";
  const code = document.getElementById("code").value.trim().toUpperCase();
  try {
    const data = await API.get("/documents/verify?code=" + encodeURIComponent(code));
    resultBox.className = "verify-result";
    resultBox.innerHTML = "";
    if (data.status === "valid") {
      resultBox.append(
        el("div", { class: "seal seal-valid", text: "✓" }),
        el("h2", { text: "Document is authentic" }),
        row("Document type", data.document_type),
        row("Document number", data.document_number),
        row("Issuing authority", data.issuing_authority),
        row("Office", data.office),
        row("Issue date (E.C.)", data.issue_date_eth),
        row("Issue date (G.C.)", data.issue_date_greg),
        el("p", { class: "muted mt-2", text: "Hash: " + (data.document_hash || "—") })
      );
    } else {
      const seal = data.status === "revoked" || data.status === "expired"
        ? "⛔" : "✕";
      resultBox.append(
        el("div", { class: "seal seal-invalid", text: seal }),
        el("h2", { text: "Document not valid — " + data.status })
      );
    }
  } catch (err) {
    showAlert(alertBox, err.message, "error");
    resultBox.className = "hidden";
  } finally {
    btn.disabled = false;
  }
});
</script>
</body>
</html>
