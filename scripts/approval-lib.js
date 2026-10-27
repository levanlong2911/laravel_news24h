// Thư viện dùng chung cho approve.js, check-approval.js, check-clean.js, ci-gate.js, code-tree.js.
// Chỉ dùng thư viện có sẵn của Node.js.
//
// "Nguồn" (source) của file:
//   - mặc định: thư mục làm việc trên đĩa
//   - setSource("<git-ref>"): đọc file từ một commit (dùng trong CI: validator chạy từ nhánh main,
//     đọc nội dung PR như DỮ LIỆU, không chạy code của PR)
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { execFileSync } = require("child_process");

const ROOT = path.resolve(__dirname, "..");
const WORK_REL = "docs/work";
const WORK_DIR = path.join(ROOT, "docs", "work");
const HEADER_KEYS = ["Status", "Revision", "Approved-by", "Approved-date", "Approved-hash"];
const HEADER_RE = new RegExp(`^(${HEADER_KEYS.join("|")}):[^\\S\\r\\n]*(.*)$`);

// ---------------------------------------------------------------- git

function git(args, opts = {}) {
  return execFileSync("git", args, {
    cwd: ROOT,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    maxBuffer: 256 * 1024 * 1024,
    ...opts,
  });
}

function gitLines(args) {
  return git(args).split("\n").map((s) => s.trim()).filter(Boolean);
}

// ---------------------------------------------------------------- nguồn file

let SOURCE_REF = null;

function setSource(ref) {
  SOURCE_REF = ref || null;
}

function readRepoFile(rel) {
  rel = rel.split(path.sep).join("/");
  if (SOURCE_REF) {
    try {
      return git(["show", `${SOURCE_REF}:${rel}`]).replace(/\r\n/g, "\n");
    } catch {
      return null;
    }
  }
  const abs = path.join(ROOT, rel);
  if (!fs.existsSync(abs)) return null;
  return fs.readFileSync(abs, "utf8").replace(/\r\n/g, "\n");
}

function repoFileExists(rel) {
  return readRepoFile(rel) !== null;
}

function listSlugs() {
  let names;
  if (SOURCE_REF) {
    try {
      names = gitLines(["ls-tree", "-d", "--name-only", `${SOURCE_REF}:${WORK_REL}`]);
    } catch {
      return [];
    }
  } else {
    if (!fs.existsSync(WORK_DIR)) return [];
    names = fs.readdirSync(WORK_DIR, { withFileTypes: true }).filter((d) => d.isDirectory()).map((d) => d.name);
  }
  return names.filter((n) => !n.startsWith("_"));
}

const taskFile = (slug, name) => `${WORK_REL}/${slug}/${name}`;

// ---------------------------------------------------------------- thiết kế (02)

function archPath(slug) {
  return path.join(WORK_DIR, slug, "02-architecture.md");
}

function readArch(slug) {
  return readRepoFile(taskFile(slug, "02-architecture.md"));
}

function parseHeader(text) {
  const header = {};
  for (const line of text.split("\n")) {
    const m = line.match(HEADER_RE);
    if (m && !(m[1] in header)) header[m[1]] = m[2].trim();
  }
  return header;
}

// Băm nội dung thiết kế, bỏ qua các dòng header. Duyệt không làm đổi hash, mọi chỉnh sửa nội dung đều làm đổi.
function contentHash(text) {
  const body = text
    .replace(/\r\n/g, "\n")
    .split("\n")
    .filter((line) => !HEADER_RE.test(line))
    .map((line) => line.replace(/\s+$/, ""))
    .join("\n")
    .trim();
  return crypto.createHash("sha256").update(body, "utf8").digest("hex").slice(0, 16);
}

function setHeader(text, key, value) {
  const re = new RegExp(`^${key}:[^\\S\\r\\n]*.*$`, "m");
  if (re.test(text)) return text.replace(re, `${key}: ${value}`);
  const lines = text.split("\n");
  let idx = -1;
  lines.forEach((l, i) => {
    if (HEADER_RE.test(l)) idx = i;
  });
  if (idx === -1) idx = lines.findIndex((l) => l.startsWith("# "));
  lines.splice(idx + 1, 0, `${key}: ${value}`);
  return lines.join("\n");
}

function checkApproval(slug) {
  const reasons = [];
  const text = readArch(slug);
  if (text === null) {
    return { ok: false, reasons: [`Không thấy docs/work/${slug}/02-architecture.md. Chạy bước 2 (/architect ${slug}) trước.`] };
  }
  const h = parseHeader(text);
  if (h["Status"] !== "APPROVED") {
    reasons.push(`Status hiện là "${h["Status"] || "(trống)"}", chưa phải APPROVED. Người dùng cần duyệt: node scripts/approve.js ${slug}`);
  }
  if (!h["Approved-by"]) reasons.push("Thiếu Approved-by.");
  if (!h["Approved-hash"]) {
    reasons.push(`Thiếu Approved-hash. Hãy duyệt bằng lệnh: node scripts/approve.js ${slug} (không sửa tay).`);
  } else if (h["Approved-hash"] !== contentHash(text)) {
    reasons.push(
      `Thiết kế đã bị SỬA SAU KHI DUYỆT (hash ${h["Approved-hash"]} ≠ ${contentHash(text)}). ` +
        `Người dùng cần đọc lại và duyệt lại: node scripts/approve.js ${slug}`
    );
  }
  return { ok: reasons.length === 0, reasons };
}

// Mục "## 5." của thiết kế: các đường dẫn trong `backtick` ở cột đầu của bảng.
function parseScope(slug) {
  const text = readArch(slug);
  if (text === null) return null;
  const m = text.match(/^## 5\.[^\n]*\n([\s\S]*?)(?=^## |$(?![\s\S]))/m);
  if (!m) return [];
  const scope = [];
  for (const line of m[1].split("\n")) {
    if (!line.trim().startsWith("|")) continue;
    const firstCell = line.split("|")[1] || "";
    for (const t of firstCell.matchAll(/`([^`]+)`/g)) scope.push(t[1].trim());
  }
  return scope;
}

// Phạm vi của task rút gọn: đường dẫn trong `backtick` ở cột đầu bảng mục "## Thay đổi dự kiến" của 00-lite.md.
function parseLiteScope(slug) {
  const text = readRepoFile(taskFile(slug, "00-lite.md"));
  if (text === null) return null;
  const m = text.replace(/<!--[\s\S]*?-->/g, "").match(/^## Thay đổi dự kiến[^\n]*\n([\s\S]*?)(?=^## |$(?![\s\S]))/m);
  if (!m) return [];
  const scope = [];
  for (const line of m[1].split("\n")) {
    if (!line.trim().startsWith("|")) continue;
    for (const t of (line.split("|")[1] || "").matchAll(/`([^`]+)`/g)) scope.push(t[1].trim());
  }
  return scope;
}

// ---------------------------------------------------------------- dòng máy đọc trong 05 / 06

// "Verdict: PASS" — chỉ nhận dòng đứng riêng, không nằm trong comment của template.
function machineField(text, key) {
  const clean = text.replace(/<!--[\s\S]*?-->/g, "");
  const re = new RegExp(`^${key}:[^\\S\\n]*(\\S+)[^\\S\\n]*$`, "gm");
  let last = null;
  for (const m of clean.matchAll(re)) last = m[1];
  return last;
}

// ---------------------------------------------------------------- phản biện kiến trúc (2b → 2c)

const CRITIQUE_VERDICTS = ["READY", "NEEDS_CHANGES", "REDO"];
const RESPONSE_OK = ["ACCEPTED", "REJECTED", "DEFERRED"]; // DEFERRED = người dùng chấp nhận rủi ro khi duyệt

// Đọc bảng nhận xét FAIL-CLOSED. Một khối bảng được coi là bảng nhận xét nếu cột đầu của header là "Mã"
// hoặc có dòng bắt đầu bằng mã <prefix>-<số>. Trong bảng đó, mọi dòng dữ liệu (không phải header, phân cách,
// hay dòng trống hoàn toàn) PHẢI có mã hợp lệ và không trùng — nếu không thì bị ghi vào `malformed`.
function findingsTable(text, prefix) {
  const codeRe = new RegExp(`^${prefix}-\\d+$`);
  const looseRe = new RegExp(`^${prefix}-`, "i");
  const blocks = [];
  let cur = null;
  for (const line of text.split("\n")) {
    if (line.trim().startsWith("|")) {
      if (!cur) blocks.push((cur = []));
      cur.push(line.split("|").map((c) => c.trim()).slice(1, -1));
    } else cur = null;
  }
  const rows = [];
  const malformed = [];
  const seen = new Set();
  for (const b of blocks) {
    const isFindings = /^mã$/i.test(b[0][0] || "") || b.some((c) => looseRe.test(c[0] || ""));
    if (!isFindings) continue;
    for (const c of b) {
      if (/^mã$/i.test(c[0] || "")) continue; // header
      if (c.every((x) => /^:?-{2,}:?$/.test(x) || x === "")) continue; // phân cách hoặc dòng trống
      const code = c[0] || "";
      if (!codeRe.test(code)) {
        malformed.push(`dòng không có mã ${prefix}-xx hợp lệ: "| ${c.join(" | ").slice(0, 80)} |"`);
        continue;
      }
      if (seen.has(code)) {
        malformed.push(`mã ${code} bị trùng`);
        continue;
      }
      seen.add(code);
      rows.push(c);
    }
  }
  return { rows, malformed };
}

// Thiết kế chỉ được duyệt khi: Codex đã phản biện (02-architecture-critique.md, Verdict hợp lệ, không REDO),
// và MỌI nhận xét BLOCKER/MAJOR (A-xx) đã có phản hồi ở mục 11 của 02-architecture.md:
// ACCEPTED (đã sửa thiết kế) | REJECTED (bác bỏ có lý do) | DEFERRED (để người dùng chấp nhận rủi ro).
// Fail-closed: thiếu dòng, trạng thái lạ, mức độ lạ → coi là chưa xử lý.
function checkCritique(slug) {
  const problems = [];
  const deferred = [];
  const critique = readRepoFile(taskFile(slug, "02-architecture-critique.md"));
  if (critique === null) {
    return { problems: ["Chưa có 02-architecture-critique.md (Codex chưa phản biện, bước 2b: $arch-critique)."], deferred };
  }
  const clean = critique.replace(/<!--[\s\S]*?-->/g, "");
  const verdict = machineField(clean, "Verdict");
  if (!CRITIQUE_VERDICTS.includes(verdict || "")) {
    problems.push(`02-architecture-critique.md: Verdict "${verdict || "(chưa điền)"}" không hợp lệ (cần ${CRITIQUE_VERDICTS.join(" | ")}).`);
  } else if (verdict === "REDO") {
    problems.push("Codex kết luận REDO (cần làm lại thiết kế). Làm lại thiết kế rồi chạy lại $arch-critique.");
  }
  const ft = findingsTable(clean, "A");
  for (const m of ft.malformed) problems.push(`02-architecture-critique.md: ${m}.`);
  const findings = ft.rows;
  const arch = (readArch(slug) || "").replace(/<!--[\s\S]*?-->/g, "");
  const sec11 = (arch.match(/^## 11\.[^\n]*\n([\s\S]*?)(?=^## |$(?![\s\S]))/m) || [, ""])[1];
  const rt = findingsTable(sec11, "A");
  for (const m of rt.malformed) problems.push(`02-architecture.md mục 11: ${m}.`);
  const responses = new Map(rt.rows.map((c) => [c[0], (c[2] || "").toUpperCase()]));
  for (const [code, sevRaw] of findings) {
    const sev = (sevRaw || "").toUpperCase();
    if (!["BLOCKER", "MAJOR", "MINOR", "QUESTION"].includes(sev)) {
      problems.push(`Nhận xét ${code}: mức độ "${sevRaw}" không hợp lệ.`);
      continue;
    }
    if (sev !== "BLOCKER" && sev !== "MAJOR") continue;
    const r = responses.get(code);
    if (!r) problems.push(`${code} (${sev}) chưa có phản hồi ở mục 11 của thiết kế (chạy /architect-revise).`);
    else if (!RESPONSE_OK.includes(r)) problems.push(`${code} (${sev}): phản hồi "${r}" không hợp lệ (cần ${RESPONSE_OK.join(" | ")}).`);
    else if (r === "DEFERRED") deferred.push(`${code} (${sev})`);
  }
  return { problems, deferred, verdict };
}

function critiqueHash(slug) {
  const t = readRepoFile(taskFile(slug, "02-architecture-critique.md"));
  if (t === null) return null;
  return crypto.createHash("sha256").update(t.replace(/\s+$/gm, "").trim(), "utf8").digest("hex").slice(0, 16);
}

// ---------------------------------------------------------------- review (05)

// Fail-closed: chỉ các trạng thái này mới được coi là ĐÃ XỬ LÝ. Mọi giá trị khác (OPEN, PENDING, viết sai, trống) = còn mở.
const CLOSED_STATUSES = ["FIXED", "ACCEPTED", "INVALID"];
const SEVERITIES = ["BLOCKER", "MAJOR", "MINOR", "QUESTION"];

// Đọc lần review MỚI NHẤT ("## Lần N"): verdict + các nhận xét BLOCKER/MAJOR còn mở.
function parseReview(slug) {
  const text = readRepoFile(taskFile(slug, "05-review.md"));
  if (text === null) return { exists: false };
  const clean = text.replace(/<!--[\s\S]*?-->/g, "");
  const parts = clean.split(/^## Lần /m);
  const latest = parts.length > 1 ? parts[parts.length - 1] : clean;
  const verdict = machineField(latest, "Verdict");
  const open = [];
  const accepted = []; // BLOCKER/MAJOR mang trạng thái ACCEPTED: cần commit accept-risk.js của người dùng
  const codes = [];
  const { rows, malformed } = findingsTable(latest, "R");
  for (const m of malformed) open.push(m);
  for (const [code, sevRaw, statusRaw] of rows) {
    codes.push(code);
    const sev = (sevRaw || "").toUpperCase();
    const status = (statusRaw || "").toUpperCase();
    if (!SEVERITIES.includes(sev)) {
      open.push(`${code} (mức độ không hợp lệ "${sevRaw}")`);
      continue;
    }
    if (sev !== "BLOCKER" && sev !== "MAJOR") continue;
    if (!CLOSED_STATUSES.includes(status)) open.push(`${code} (${sev}, trạng thái "${statusRaw || "trống"}")`);
    else if (status === "ACCEPTED") accepted.push(code);
  }
  return { exists: true, verdict, open, accepted, codes };
}

// Commit chấp nhận rủi ro do accept-risk.js tạo cho (slug, code), tìm trong lịch sử của ref.
function acceptanceCommits(slug, code, ref = "HEAD") {
  try {
    // Neo đầu/cuối dòng để R-1 không khớp nhầm R-10.
    return gitLines(["log", "--format=%H", "-E", `--grep=^Review-Risk-Accepted: ${slug} ${code}$`, ref]);
  } catch {
    return [];
  }
}

// ---------------------------------------------------------------- verification (06)

function parseVerification(slug) {
  const text = readRepoFile(taskFile(slug, "06-verification.md"));
  if (text === null) return { exists: false };
  return { exists: true, verdict: machineField(text, "Verdict"), codeTree: machineField(text, "Code-Tree") };
}

// ---------------------------------------------------------------- code tree

// Hash của toàn bộ code tại một commit, BỎ QUA docs/work/ (nơi Codex ghi review/verification).
// Verification ghi hash này. Code đổi sau khi verify → hash lệch → CI chặn.
function codeTreeHash(ref = "HEAD") {
  const lines = git(["ls-tree", "-r", "--full-tree", ref])
    .split("\n")
    .filter((l) => l && !l.split("\t")[1].startsWith(`${WORK_REL}/`))
    .sort();
  return crypto.createHash("sha256").update(lines.join("\n"), "utf8").digest("hex").slice(0, 16);
}

// ---------------------------------------------------------------- task hoàn tất

// Task đã có code (04) phải: duyệt hợp lệ + review mới nhất đạt, không còn BLOCKER/MAJOR mở
// + verification PASS (và nếu biết headRef: Code-Tree khớp code hiện tại).
// "full": có 02-architecture.md (quy trình 6 bước). "lite": có 00-lite.md, không có 02 (luồng rút gọn). null: chưa phải task.
function taskKind(slug) {
  if (repoFileExists(taskFile(slug, "02-architecture.md"))) return "full";
  if (repoFileExists(taskFile(slug, "00-lite.md"))) return "lite";
  return null;
}

function checkTaskComplete(slug, { requireVerification = true, headRef = null, requireApproval = true } = {}) {
  const reasons = requireApproval ? checkApproval(slug).reasons : [];

  const r = parseReview(slug);
  if (!r.exists) reasons.push("Thiếu 05-review.md (bước 5 — Codex review).");
  else {
    if (!["PASS", "PASS_WITH_MINOR"].includes(r.verdict || "")) {
      reasons.push(`05-review.md: lần review mới nhất có Verdict "${r.verdict || "(chưa điền)"}", cần PASS hoặc PASS_WITH_MINOR.`);
    }
    if (r.open.length) reasons.push(`05-review.md: còn nhận xét chưa xử lý: ${r.open.join(", ")}.`);
  }

  if (requireVerification) {
    const v = parseVerification(slug);
    if (!v.exists) reasons.push("Thiếu 06-verification.md (bước 6 — Codex verify).");
    else {
      if (v.verdict !== "PASS") reasons.push(`06-verification.md: Verdict "${v.verdict || "(chưa điền)"}", cần PASS.`);
      if (!v.codeTree) reasons.push("06-verification.md: thiếu dòng Code-Tree (chạy: node scripts/code-tree.js).");
      else if (headRef) {
        const now = codeTreeHash(headRef);
        if (now !== v.codeTree) {
          reasons.push(`Code đã thay đổi SAU KHI verify (Code-Tree ${v.codeTree} ≠ ${now}). Chạy lại $verify ${slug}.`);
        }
      }
    }
  }
  return reasons;
}

// ---------------------------------------------------------------- cấu hình cổng

function loadGateConfig() {
  // Cấu hình luôn đọc từ đĩa (trong CI: bản của nhánh main), KHÔNG từ PR.
  const file = path.join(ROOT, ".github", "workflow-gate.json");
  try {
    return JSON.parse(fs.readFileSync(file, "utf8"));
  } catch (e) {
    throw new Error(`Không đọc được .github/workflow-gate.json: ${e.message}`);
  }
}

// ---------------------------------------------------------------- glob

// ** (nhiều cấp), * (trong 1 cấp), ?. Pattern không có "/" khớp tên file ở mọi cấp.
function globToRegExp(glob) {
  let g = glob.replace(/^\.\//, "");
  if (!g.includes("/")) g = "**/" + g;
  let re = "";
  for (let i = 0; i < g.length; i++) {
    const c = g[i];
    if (c === "*") {
      if (g[i + 1] === "*") {
        if (g[i + 2] === "/") {
          re += "(?:.*/)?";
          i += 2;
        } else {
          re += ".*";
          i += 1;
        }
      } else re += "[^/]*";
    } else if (c === "?") re += "[^/]";
    else if ("\\^$+.()|{}[]".includes(c)) re += "\\" + c;
    else re += c;
  }
  if (glob.endsWith("/")) re += ".*";
  return new RegExp("^" + re + "$");
}

function matchesAny(file, patterns) {
  return (patterns || []).find((p) => globToRegExp(p).test(file)) || null;
}

function hasAgentMarker(message, cfg) {
  const low = message.toLowerCase();
  return (cfg.agentMarkers || []).find((m) => low.includes(m.toLowerCase())) || null;
}

module.exports = {
  ROOT, WORK_DIR, WORK_REL, git, gitLines, setSource, readRepoFile, repoFileExists, listSlugs, taskFile,
  archPath, readArch, parseHeader, contentHash, setHeader, checkApproval, parseScope,
  machineField, parseReview, parseVerification, codeTreeHash, checkTaskComplete, taskKind, checkCritique, critiqueHash, findingsTable, acceptanceCommits, parseLiteScope,
  loadGateConfig, globToRegExp, matchesAny, hasAgentMarker,
};
