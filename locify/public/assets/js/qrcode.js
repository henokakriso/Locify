/* ============================================================
   LOCIFY — Minimal QR code generator (byte mode, ECC level M)
   Self-contained; no external dependencies. Renders to <canvas>.
   Supports QR versions 1–10 (covers any LOCIFY verification code).
   ============================================================ */

"use strict";

const QR = (() => {
  // GF(256) tables with primitive polynomial 0x11D.
  const EXP = new Uint8Array(512);
  const LOG = new Uint8Array(256);
  (function buildGF() {
    let x = 1;
    for (let i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) x ^= 0x11d;
    }
    for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  })();

  // Version info: [total codewords, ecc codewords per block, block layout]
  // Block layout entries are [count of group-1 blocks, data cw each, group-2 blocks, data cw each]
  const VERSIONS = {
    1: [26, 10, [1, 16, 0, 0]],
    2: [44, 16, [1, 28, 0, 0]],
    3: [70, 26, [1, 44, 0, 0]],
    4: [100, 18, [2, 32, 0, 0]],
    5: [134, 24, [2, 43, 0, 0]],
    6: [172, 16, [4, 27, 0, 0]],
    7: [196, 18, [4, 31, 0, 0]],
    8: [242, 22, [2, 38, 2, 39]],
    9: [292, 22, [3, 36, 2, 37]],
    10: [346, 26, [4, 43, 1, 44]],
  };

  const ALIGN = {
    2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34],
    7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50],
  };

  function mul(a, b) { return a === 0 || b === 0 ? 0 : EXP[LOG[a] + LOG[b]]; }

  // Generator polynomial, LEADING coefficient first: gen[0] = x^n term, gen[n] = constant.
  function rsGenerator(n) {
    let gen = [1];
    for (let i = 0; i < n; i++) {
      const next = new Array(gen.length + 1).fill(0);
      for (let j = 0; j < gen.length; j++) {
        next[j] ^= gen[j];                        // x * gen
        next[j + 1] ^= mul(gen[j], EXP[i]);       // alpha^i * gen
      }
      gen = next;
    }
    return gen;
  }

  function rsEncode(data, ecLen) {
    const gen = rsGenerator(ecLen);
    const rem = new Array(ecLen).fill(0);
    for (const byte of data) {
      const factor = byte ^ rem[0];
      rem.shift();
      rem.push(0);
      if (factor !== 0) {
        for (let i = 0; i < ecLen; i++) rem[i] ^= mul(gen[i + 1], factor);
      }
    }
    return rem;
  }

  function chooseVersion(dataBits) {
    for (const v of Object.keys(VERSIONS).map(Number).sort((a, b) => a - b)) {
      const info = VERSIONS[v];
      const [g1c, g1d, g2c, g2d] = info[2];
      const dataCw = g1c * g1d + g2c * g2d;
      const capacityBits = dataCw * 8 - 4 - (v < 10 ? 8 : 16); // mode + count bits
      if (dataBits <= capacityBits) return v;
    }
    throw new Error("QR data too long");
  }

  function encode(text) {
    const bytes = [];
    for (let i = 0; i < text.length; i++) {
      bytes.push(text.charCodeAt(i) & 0xff);
    }
    const version = chooseVersion(bytes.length * 8);
    const info = VERSIONS[version];
    const [g1c, g1d, g2c, g2d] = info[2];
    const totalData = g1c * g1d + g2c * g2d;

    // Byte-mode bit stream: mode(0100) + count + data + terminator + padding
    const bits = [];
    const pushVal = (value, len) => {
      for (let i = len - 1; i >= 0; i--) bits.push((value >> i) & 1);
    };
    pushVal(0x4, 4);
    if (version < 10) pushVal(bytes.length, 8);
    else pushVal(bytes.length, 16);
    for (const b of bytes) pushVal(b, 8);

    let bitIdx = 0;
    const data = [];
    while (data.length < totalData) {
      let byte = 0;
      let filled = 0;
      while (filled < 8) {
        if (bitIdx < bits.length) {
          byte = (byte << 1) | bits[bitIdx++];
        } else {
          break; // terminator ends / nothing left
        }
        filled++;
      }
      if (filled === 0) break;
      byte <<= (8 - filled);
      data.push(byte);
    }
    // Pad with 0xEC / 0x11 alternation
    let pad = 0xec;
    while (data.length < totalData) {
      data.push(pad);
      pad = pad === 0xec ? 0x11 : 0xec;
    }

    // Split into RS blocks, compute ECC, interleave.
    const blocks = [];
    for (const [grpCount, grpData] of [[g1c, g1d], [g2c, g2d]]) {
      if (grpCount === 0) continue;
      for (let i = 0; i < grpCount; i++) {
        blocks.push({ data: data.splice(0, grpData), ecc: [] });
      }
    }
    for (const b of blocks) b.ecc = rsEncode(b.data, info[1]);

    const stream = [];
    const maxData = Math.max(...blocks.map(b => b.data.length));
    for (let i = 0; i < maxData; i++) {
      for (const b of blocks) if (i < b.data.length) stream.push(b.data[i]);
    }
    const maxEcc = Math.max(...blocks.map(b => b.ecc.length));
    for (let i = 0; i < maxEcc; i++) {
      for (const b of blocks) if (i < b.ecc.length) stream.push(b.ecc[i]);
    }

    return buildMatrix(version, stream);
  }

  function buildMatrix(version, stream) {
    const n = 17 + 4 * version;
    const mod = Array.from({ length: n }, () => new Array(n).fill(null));
    const isFunction = Array.from({ length: n }, () => new Array(n).fill(false));

    const set = (r, c, v) => { mod[r][c] = v; isFunction[r][c] = true; };

    // Finder patterns + separators
    for (const [fr, fc] of [[0, 0], [0, n - 7], [n - 7, 0]]) {
      for (let r = -1; r <= 7; r++) {
        for (let c = -1; c <= 7; c++) {
          const rr = fr + r, cc = fc + c;
          if (rr < 0 || rr >= n || cc < 0 || cc >= n) continue;
          const dark = r >= 0 && r <= 6 && c >= 0 && c <= 6 &&
            (r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4));
          set(rr, cc, dark);
        }
      }
    }

    // Alignment patterns (before timing: centers may lie on the timing row/col)
    const align = ALIGN[version];
    if (align) {
      for (const r of align) {
        for (const c of align) {
          if (mod[r][c] !== null) continue; // skip where finder overlaps
          for (let dr = -2; dr <= 2; dr++) {
            for (let dc = -2; dc <= 2; dc++) {
              const dark = Math.max(Math.abs(dr), Math.abs(dc)) !== 1;
              set(r + dr, c + dc, dark);
            }
          }
        }
      }
    }

    // Timing patterns
    for (let i = 8; i < n - 8; i++) {
      if (mod[6][i] === null) set(6, i, i % 2 === 0);
      if (mod[i][6] === null) set(i, 6, i % 2 === 0);
    }

    // Pick the best mask (lowest penalty).
    let best = null;
    let bestPenalty = Infinity;
    for (let mask = 0; mask < 8; mask++) {
      const trial = placeData(version, mod, stream, mask);
      const penalty = penaltyScore(trial, n);
      if (penalty < bestPenalty) { bestPenalty = penalty; best = trial; }
    }
    return { version, size: n, modules: best };
  }

  function placeData(version, functionMap, stream, mask) {
    const n = functionMap.length;
    const mod = functionMap.map(row => row.slice());
    const isFn = functionMap.map(row => row.slice().map(v => v !== null));
    // Reserve format info cells, dark module and (for v>=7) version info cells:
    // they are written after data placement, so no data bit may land there.
    for (let i = 0; i < 15; i++) {
      let vRow;
      if (i < 6) vRow = i;
      else if (i < 8) vRow = i + 1;
      else vRow = n - 15 + i;
      isFn[vRow][8] = true;
      let hCol;
      if (i < 8) hCol = n - i - 1;
      else if (i < 9) hCol = 15 - i;
      else hCol = 15 - i - 1;
      isFn[8][hCol] = true;
    }
    isFn[n - 8][8] = true; // dark module
    if (version >= 7) {
      for (let i = 0; i < 18; i++) {
        const a = Math.floor(i / 3);
        const b = (i % 3) + n - 11;
        isFn[a][b] = true;
        isFn[b][a] = true;
      }
    }
    const dark = (r, c) => (mod[r][c] === true);

    const bitIndex = (i) => {
      const d = i % 8;
      return 7 - d;
    };

    let col = n - 1;
    let upward = true;
    let idx = 0;
    while (col > 0) {
      if (col === 6) col--; // skip timing column
      for (let rowBase = upward ? n - 1 : 0; upward ? rowBase >= 0 : rowBase < n; rowBase += upward ? -1 : 1) {
        for (const dc of [0, -1]) {
          const c = col + dc;
          if (isFn[rowBase][c]) continue;
          let v = null;
          if (idx < stream.length * 8) {
            const byte = stream[Math.floor(idx / 8)];
            v = ((byte >> bitIndex(idx)) & 1) === 1;
            idx++;
          } else {
            v = false; // remainder bits
          }
          // mask
          let m = false;
          switch (mask) {
            case 0: m = (rowBase + c) % 2 === 0; break;
            case 1: m = rowBase % 2 === 0; break;
            case 2: m = c % 3 === 0; break;
            case 3: m = (rowBase + c) % 3 === 0; break;
            case 4: m = (Math.floor(rowBase / 2) + Math.floor(c / 3)) % 2 === 0; break;
            case 5: m = (rowBase * c) % 2 + (rowBase * c) % 3 === 0; break;
            case 6: m = ((rowBase * c) % 2 + (rowBase * c) % 3) % 2 === 0; break;
            case 7: m = ((rowBase + c) % 2 + (rowBase * c) % 3) % 2 === 0; break;
          }
          mod[rowBase][c] = v !== m;
        }
      }
      upward = !upward;
      col -= 2;
    }

    // Format information (ECC level M = 0b00, 15 bits BCH, mask XOR 0x5412)
    // Layout mirrors ISO 18004 / reference implementations:
    //   copy 1 (top-left): col 8 rows 0-5,7,8 (bits 0-7) + row 8 cols 0-5,7 (bits 8-14)
    //   copy 2 (bottom-right): row 8 cols n-1..n-8 (bits 0-7) + col 8 rows n-7..n-1 (bits 8-14)
    const formatBits = formatInfo(mask);
    const darkBit = (i) => ((formatBits >> i) & 1) === 1;
    const bit = 0;
    for (let i = 0; i < 15; i++) {
      // vertical part
      let vRow;
      if (i < 6) vRow = i;
      else if (i < 8) vRow = i + 1;
      else vRow = n - 15 + i;
      mod[vRow][8] = darkBit(i);
      // horizontal part
      let hCol;
      if (i < 8) hCol = n - i - 1;
      else if (i < 9) hCol = 15 - i;
      else hCol = 15 - i - 1;
      mod[8][hCol] = darkBit(i);
    }
    // Dark module
    mod[n - 8][8] = true;

    // Version information (only for version >= 7)
    if (version >= 7) {
      let bits = version << 12;
      const G18 = 0x1f25;
      let d = bits;
      for (let i = 17; i >= 12; i--) {
        if ((d >> i) & 1) d ^= G18 << (i - 12);
      }
      bits = (version << 12) | d;
      for (let i = 0; i < 18; i++) {
        const dark = ((bits >> i) & 1) === 1;
        const a = Math.floor(i / 3);
        const b = (i % 3) + n - 11;
        mod[a][b] = dark;
        mod[b][a] = dark;
      }
    }

    return mod;
  }

  function formatInfo(mask) {
    const ecBits = 0b00; // ECC level M
    const data = (ecBits << 3) | mask;
    let bch = data << 10;
    const gen = 0x537;
    for (let i = 14; i >= 10; i--) {
      if ((bch >> i) & 1) bch ^= gen << (i - 10);
    }
    return ((data << 10) | bch) ^ 0x5412;
  }

  // Reference-compatible penalty: LEVEL 1-4 rules
  function penaltyScore(mod, n) {
    const dark = (r, c) => mod[r][c] === true;
    const darkAt = (r, c) => (r < 0 || r >= n || c < 0 || c >= n) ? true : dark(r, c);
    let score = 0;
    // LEVEL1: cells with >5 same-colored neighbours
    for (let row = 0; row < n; row++) {
      for (let col = 0; col < n; col++) {
        let sameCount = 0;
        const v = dark(row, col);
        for (let r = -1; r <= 1; r++) {
          if (row + r < 0 || n <= row + r) continue;
          for (let c = -1; c <= 1; c++) {
            if (col + c < 0 || n <= col + c) continue;
            if (r === 0 && c === 0) continue;
            if (v === dark(row + r, col + c)) sameCount++;
          }
        }
        if (sameCount > 5) score += 3 + sameCount - 5;
      }
    }
    // LEVEL2: 2x2 blocks all same color
    for (let row = 0; row < n - 1; row++) {
      for (let col = 0; col < n - 1; col++) {
        let count = 0;
        if (dark(row, col)) count++;
        if (dark(row + 1, col)) count++;
        if (dark(row, col + 1)) count++;
        if (dark(row + 1, col + 1)) count++;
        if (count === 0 || count === 4) score += 3;
      }
    }
    // LEVEL3: 1:1:3:1:1 finder-like patterns
    for (let row = 0; row < n; row++) {
      for (let col = 0; col < n - 6; col++) {
        if (dark(row, col) && !dark(row, col + 1) && dark(row, col + 2) &&
            dark(row, col + 3) && dark(row, col + 4) && !dark(row, col + 5) &&
            dark(row, col + 6)) score += 40;
      }
      for (let col = 0; col < n - 6; col++) {
        if (dark(col, row) && !dark(col + 1, row) && dark(col + 2, row) &&
            dark(col + 3, row) && dark(col + 4, row) && !dark(col + 5, row) &&
            dark(col + 6, row)) score += 40;
      }
    }
    // LEVEL4: dark module proportion
    let darkCount = 0;
    for (let col = 0; col < n; col++) {
      for (let row = 0; row < n; row++) {
        if (dark(row, col)) darkCount++;
      }
    }
    const ratio = Math.abs(100 * darkCount / n / n - 50) / 5;
    score += ratio * 10;
    return score;
  }

  /** Draw the QR into a canvas (returns the canvas). */
  function drawInto(canvas, text, scale = 4, quiet = 4) {
    const qr = encode(text);
    const px = (qr.size + 2 * quiet) * scale;
    canvas.width = px;
    canvas.height = px;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = "#000000";
    for (let r = 0; r < qr.size; r++) {
      for (let c = 0; c < qr.size; c++) {
        if (qr.modules[r][c]) {
          ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale);
        }
      }
    }
    return canvas;
  }

  return { encode, drawInto };
})();