<?php $title = 'Paper Copy'; require __DIR__ . '/../partials/head.php'; ?>
<body>
<div class="auth-wrap">
  <div class="auth-card" style="max-width:760px">
    <div class="brand">
      <span class="mark"></span>
      <span>LOCIFY</span>
    </div>
    <h1 style="font-size:1.2rem; margin-bottom:0.2rem">Paper Copy / የወረቀት ቅጂ</h1>
    <p class="muted" style="margin-bottom:1rem">
      Print an official paper copy of an issued document. Enter the document number or the verification code.
    </p>

    <div id="alert"></div>

    <form id="paper-form">
      <label for="number">Document number (LOC-DOC-YYYY-XXXXXX)</label>
      <input id="number" name="number" placeholder="LOC-DOC-2026-000001" style="font-family:var(--mono)">
      <p class="muted" style="text-align:center; margin:0.6rem 0">— or —</p>
      <label for="code">Verification code</label>
      <input id="code" name="code" placeholder="XXXX-XXXX-XXXX" style="font-family:var(--mono); letter-spacing:0.1em">
      <button class="btn" style="width:100%; justify-content:center; margin-top:1.2rem" type="submit" id="lookup-btn">
        Load document / ሰነዱን መጫን
      </button>
    </form>

    <div id="paper" class="hidden"></div>

    <p class="muted mt-2" style="text-align:center"><a href="/">Home</a></p>
  </div>
</div>

<script src="/assets/js/api.js"></script>
<script src="/assets/js/qrcode.js"></script>
<script>
const paperForm = document.getElementById("paper-form");
const alertBox = document.getElementById("alert");
const paperBox = document.getElementById("paper");

const ethMonths = ["መስከረም","ጥቅምት","ህዳር","ታህሳስ","ጥር","የካቲት","መጋቢት","ሚያዚያ","ግንቦት","ሰኔ","ሐምሌ","ነሃሴ"];

function ethDate(value) {
  if (!value) return "—";
  const ymd = String(value).split(" ")[0].split("-");
  if (ymd.length < 3) return value;
  const m = parseInt(ymd[1], 10);
  return ethMonths[(m - 1) % 12] + " " + parseInt(ymd[2], 10) + ", " + ymd[0];
}

function render(d) {
  const name = [d.citizen.first_name, d.citizen.middle_name, d.citizen.last_name].filter(Boolean).join(" ");
  paperBox.className = "cert";
  paperBox.innerHTML = "";
  paperBox.append(
    el("div", { class: "cert-head" }, [
      el("div", { class: "cert-brand", text: "LOCIFY" }),
      el("div", { class: "cert-title", text: d.title || "Official Document" }),
    ]),
    el("div", { class: "cert-body" }, [
      el("div", { class: "cert-qr" }, [el("canvas", { id: "paper-qr" })]),
      el("div", { class: "cert-fields" }, [
        row("Recipient / ተቀባይ", name),
        row("Document type", d.document_type),
        row("Document number", d.document_number),
        row("Issuing unit", d.issuing_unit),
        row("Issued (E.C.)", ethDate(d.issued_ethiopian)),
        row("Issued (G.C.)", d.issued_gregorian || "—"),
        row("Verification code", d.verification_code),
        el("p", { class: "muted", style: "font-size:0.8rem; margin-top:0.8rem",
          text: "Verify at " + d.verify_url.replace("https://", "").replace("http://", "") }),
      ]),
    ]),
    el("div", { class: "cert-foot", text: "Official copy produced by LOCIFY. Authenticity can be verified by scanning the QR code." }),
    el("div", { class: "cert-actions no-print" }, [
      el("button", { class: "btn", text: "Print / አትም", onclick: () => window.print() }),
    ])
  );
  QR.drawInto(document.getElementById("paper-qr"), d.verification_code, 6, 4);
}

function row(label, value) {
  return el("div", { class: "row spread", style: "padding:0.35rem 0; border-bottom:1px solid var(--line)" },
    [el("span", { class: "muted", text: label }), el("span", { class: "mono", text: value })]);
}

paperForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const btn = document.getElementById("lookup-btn");
  btn.disabled = true;
  paperBox.className = "hidden";
  const q = new URLSearchParams();
  const n = document.getElementById("number").value.trim();
  const c = document.getElementById("code").value.trim().toUpperCase();
  if (n) q.set("number", n);
  if (c) q.set("code", c);
  if (!n && !c) { showAlert(alertBox, "Enter a document number or verification code", "error"); btn.disabled = false; return; }
  try {
    const data = await API.get("/paper?" + q.toString());
    showAlert(alertBox, "", "");
    alertBox.innerHTML = "";
    render(data);
  } catch (err) {
    alertBox.innerHTML = "";
    showAlert(alertBox, err.message, "error");
  } finally {
    btn.disabled = false;
  }
});

(function autoLoad() {
  const q = new URLSearchParams(window.location.search);
  const number = q.get("number") || "";
  const code = q.get("code") || "";
  if (!number && !code) return;
  if (number) document.getElementById("number").value = number;
  if (code) document.getElementById("code").value = code;
  paperForm.dispatchEvent(new Event("submit"));
})();
</script>
<style>
.cert { border: 2px solid var(--ink-900); border-radius: 10px; padding: 1.4rem; margin-top: 1.2rem; background: #fff; }
.cert-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--ink-900); padding-bottom: 0.6rem; margin-bottom: 1rem; }
.cert-brand { font-weight: 800; letter-spacing: 0.12em; }
.cert-title { font-weight: 700; }
.cert-body { display: flex; gap: 1.2rem; align-items: stretch; }
.cert-qr canvas { width: 168px; height: 168px; image-rendering: pixelated; }
.cert-fields { flex: 1; }
.cert-foot { margin-top: 1.2rem; font-size: 0.78rem; color: var(--ink-600); text-align: center; }
.cert-actions { margin-top: 1rem; display: flex; justify-content: center; }
@media print {
  body { background: #fff; }
  .auth-card { border: none; box-shadow: none; max-width: none; padding: 0; }
  .auth-card > *:not(.cert) { display: none !important; }
  .cert { border: 2px solid #000; box-shadow: none; border-radius: 0; margin-top: 0; }
  .no-print { display: none !important; }
  @page { size: A4; margin: 12mm; }
}
</style>
</body>
</html>