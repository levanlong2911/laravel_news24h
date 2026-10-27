#!/usr/bin/env node
// Cổng quy trình.
//
// Trong CI (.github/workflows/gate.yml, sự kiện pull_request_target):
//   - Script và .github/workflow-gate.json lấy từ NHÁNH MAIN (PR không sửa được validator).
//   - Nội dung PR được đọc như DỮ LIỆU qua git ref, không chạy code của PR.
//   GATE_HEAD_REF=<ref commit PR>  GATE_BASE_REF=<ref nhánh gốc>  node scripts/ci-gate.js
//
// Trên máy (Codex chạy ở bước 6, hoặc bạn tự chạy):
//   node scripts/ci-gate.js --local
//
// Kiểm tra:
//   G1  Mọi task đã có code: duyệt hợp lệ + review mới nhất PASS, không còn BLOCKER/MAJOR mở
//       + verification PASS và Code-Tree khớp code của PR
//   G2  PR có rủi ro (đụng migration/route/config/job/dependency, hoặc vượt ngưỡng "thay đổi nhỏ")
//       bắt buộc thuộc một task (nhánh feat|fix/<slug>) có thiết kế được duyệt — KHÔNG phụ thuộc tên nhánh
//   G3  Commit duyệt: trailer đúng hash, do approver tạo, không có dấu vết agent, chỉ chứa file thiết kế
//       (tùy chọn: chữ ký được GitHub xác minh)
//   G4  Mọi file code thay đổi nằm trong mục 5 của thiết kế
//   G5  Commit chứa file của Codex (critique/review/verification) không được chứa code
//   G6  File luật chỉ đổi trong PR có label do người gắn, commit do approver tạo, không dấu vết agent
//   G7  Không thêm mã lách luật: @phpstan-ignore, ->skip(), markTestSkipped, eslint-disable, env() trong app...
const fs = require("fs");
const path = require("path");
const lib = require("./approval-lib");
const { git, gitLines, matchesAny, hasAgentMarker } = lib;

const LOCAL = process.argv.includes("--local");
const cfg = lib.loadGateConfig();
const errors = [];
const notes = [];
const fail = (code, msg) => errors.push(`[${code}] ${msg}`);
const note = (msg) => notes.push(msg);

// ------------------------------------------------------------------ bối cảnh
let headRef, baseRef, branch, labels = [], prNumber = null;

if (LOCAL) {
  headRef = "HEAD";
  branch = git(["rev-parse", "--abbrev-ref", "HEAD"]).trim();
  baseRef = `origin/${cfg.baseBranch}`;
  try {
    git(["rev-parse", "--verify", "--quiet", baseRef]);
  } catch {
    baseRef = cfg.baseBranch;
  }
} else {
  headRef = process.env.GATE_HEAD_REF;
  baseRef = process.env.GATE_BASE_REF;
  if (!headRef || !baseRef) {
    console.error("Thiếu GATE_HEAD_REF / GATE_BASE_REF. Trong CI, gate chạy bằng .github/workflows/gate.yml.");
    process.exit(1);
  }
  const evPath = process.env.GITHUB_EVENT_PATH;
  if (evPath && fs.existsSync(evPath)) {
    const ev = JSON.parse(fs.readFileSync(evPath, "utf8"));
    const pr = ev.pull_request || {};
    branch = (pr.head && pr.head.ref) || process.env.GITHUB_HEAD_REF;
    labels = (pr.labels || []).map((l) => l.name);
    prNumber = pr.number || null;
  } else {
    branch = process.env.GITHUB_HEAD_REF;
    labels = (process.env.GATE_LABELS || "").split(",").filter(Boolean);
  }
}

lib.setSource(headRef); // đọc docs/work/... của PR như dữ liệu

const approvers = (cfg.approvers || []).map((e) => e.toLowerCase());
if (!approvers.length) fail("CFG", "Chưa khai báo 'approvers' trong .github/workflow-gate.json (chạy scripts/setup-github.sh).");

// Nhánh feat|fix/<slug> = quy trình ĐỦ 6 bước; chore|hotfix/<slug> = luồng RÚT GỌN (Codex review + verify + CI).
const fullMatch = branch ? branch.match(new RegExp(cfg.branchPattern)) : null;
const liteMatch = !fullMatch && branch && cfg.liteBranchPattern ? branch.match(new RegExp(cfg.liteBranchPattern)) : null;
const currentKind = fullMatch ? "full" : liteMatch ? "lite" : null;
const currentSlug = fullMatch ? fullMatch[fullMatch.length - 1] : liteMatch ? liteMatch[liteMatch.length - 1] : null;

// ------------------------------------------------------------------ thay đổi trong PR
let mergeBase, changed, numstat, infos;
try {
  mergeBase = git(["merge-base", baseRef, headRef]).trim();
  changed = gitLines(["diff", "--name-only", "--no-renames", mergeBase, headRef]);
  numstat = gitLines(["diff", "--numstat", "--no-renames", mergeBase, headRef]).map((l) => {
    const [a, d, f] = l.split("\t");
    return { file: f, lines: (parseInt(a, 10) || 0) + (parseInt(d, 10) || 0) };
  });
  infos = gitLines(["rev-list", "--no-merges", `${mergeBase}..${headRef}`]).map(commitInfo);
} catch (e) {
  fail("GIT", `Không xác định được thay đổi so với ${baseRef}. Cần fetch đủ lịch sử. (${e.message.split("\n")[0]})`);
  finish();
}

const isLaw = (f) => !!matchesAny(f, cfg.lawFiles);
const isDocsOrTests = (f) => !!matchesAny(f, cfg.alwaysInScope);
const codeFiles = changed.filter((f) => !isLaw(f) && !isDocsOrTests(f));
// Test ĐÃ CÓ bị sửa (M) hoặc xóa (D). Thêm test mới (A) luôn được phép; sửa/xóa test cũ có thể làm yếu kiểm chứng,
// nên phải nằm trong phạm vi của task (mục 5 thiết kế, hoặc "Thay đổi dự kiến" của 00-lite.md).
const testEdits = gitLines(["diff", "--name-status", "--no-renames", mergeBase, headRef])
  .map((l) => l.split("\t"))
  .filter(([st, f]) => (st === "M" || st === "D") && matchesAny(f, cfg.protectedTests || ["tests/**"]) && !isLaw(f))
  .map(([st, f]) => ({ st, f }));

// ------------------------------------------------------------------ G1
const codeChanged = codeFiles.length > 0;
const needsTask = codeChanged || testEdits.length > 0;
for (const slug of lib.listSlugs()) {
  const kind = lib.taskKind(slug);
  const isCurrent = slug === currentSlug;
  // Task "đã bắt đầu code": có 04 (đủ) hoặc 00-lite (rút gọn), HOẶC là task của PR này và PR có đổi code.
  const started = kind === "lite" || lib.repoFileExists(lib.taskFile(slug, "04-implementation.md")) || (isCurrent && needsTask);
  if (!started || !kind) continue;
  const reasons = lib.checkTaskComplete(slug, {
    requireApproval: kind === "full",
    requireVerification: !(LOCAL && isCurrent), // ở local, bước 6 đang chạy nên chưa có 06
    headRef: isCurrent ? headRef : null,
  });
  if (kind === "full" && isCurrent && codeChanged && !lib.repoFileExists(lib.taskFile(slug, "04-implementation.md"))) {
    reasons.push("PR có code nhưng thiếu 04-implementation.md (bước 4).");
  }
  for (const r of reasons) fail("G1", `${slug}: ${r}`);

  // ACCEPTED (BLOCKER/MAJOR) chỉ hợp lệ khi người dùng đã chạy accept-risk.js (commit của approver).
  const rv = lib.parseReview(slug);
  for (const code of (rv.exists && rv.accepted) || []) {
    const shas = lib.acceptanceCommits(slug, code, headRef);
    const valid = shas.map(commitInfo).find((c) => {
      if (approvers.length && !approvers.includes(c.email)) return false;
      if (hasAgentMarker(c.body, cfg)) return false;
      if (c.files.length) return false; // commit chấp nhận rủi ro phải là commit rỗng
      return true;
    });
    if (!valid) {
      fail("G1", `${slug}: ${code} ghi ACCEPTED nhưng không có commit chấp nhận rủi ro hợp lệ của người dùng. Người dùng chạy: node scripts/accept-risk.js ${slug} ${code} --reason "<lý do>"`);
    } else {
      if (cfg.requireSignedApproval) checkSigned(valid, "G1", `Commit chấp nhận rủi ro ${code}`);
      note(`${slug}: ${code} được người dùng chấp nhận rủi ro (commit ${valid.short}).`);
    }
  }
}

// ------------------------------------------------------------------ G2 — phân loại rủi ro
const rp = cfg.riskPolicy || {};
const riskyHits = codeFiles.filter((f) => matchesAny(f, rp.designRequiredPaths));
const codeLines = numstat.filter((n) => codeFiles.includes(n.file)).reduce((s, n) => s + n.lines, 0);
const small = rp.smallChange || { maxFiles: 3, maxLines: 80 };
const needsDesign =
  riskyHits.length > 0 || codeFiles.length > small.maxFiles || codeLines > small.maxLines;

const whyNotSmall = () => {
  const why = [];
  if (riskyHits.length) why.push(`đụng vùng rủi ro: ${riskyHits.slice(0, 5).join(", ")}${riskyHits.length > 5 ? "…" : ""}`);
  if (codeFiles.length > small.maxFiles) why.push(`${codeFiles.length} file code (> ${small.maxFiles})`);
  if (codeLines > small.maxLines) why.push(`${codeLines} dòng code thay đổi (> ${small.maxLines})`);
  return why.join("; ");
};

const testEditList = () => testEdits.map((t) => `${t.f}${t.st === "D" ? " (xóa)" : ""}`).join(", ");
if (!needsTask) {
  note("PR chỉ đổi docs/thêm test mới/file luật: không cần task (file luật vẫn qua G6).");
} else if (!currentKind) {
  fail(
    "G2",
    `Nhánh "${branch}" có ${codeChanged ? "thay đổi code" : `sửa/xóa test đã có (${testEditList()})`} nhưng không thuộc task nào. ` +
      `Phải đi qua Codex review + verification: dùng feat/<slug> (đủ 6 bước) hoặc chore/<slug> (rút gọn — /quick trong Claude).`
  );
} else if (currentKind === "lite") {
  if (lib.taskKind(currentSlug) !== "lite") {
    fail("G2", `Nhánh ${branch} (luồng rút gọn) nhưng thiếu docs/work/${currentSlug}/00-lite.md.`);
  }
  if (needsDesign) {
    fail("G2", `Luồng rút gọn chỉ dành cho thay đổi nhỏ, PR này: ${whyNotSmall()}. Chuyển sang feat/${currentSlug} và chạy đủ 6 bước.`);
  } else if (lib.taskKind(currentSlug) === "lite") {
    // Phạm vi rút gọn: mọi file code và test cũ bị sửa/xóa phải được liệt kê trong 00-lite.md.
    const ls = lib.parseLiteScope(currentSlug) || [];
    for (const f of codeFiles.filter((f) => !matchesAny(f, ls))) {
      fail("G4", `${f} chưa được liệt kê ở "Thay đổi dự kiến" của docs/work/${currentSlug}/00-lite.md.`);
    }
    for (const t of testEdits.filter((t) => !matchesAny(t.f, ls))) {
      fail("G4", `Test đã có bị ${t.st === "D" ? "xóa" : "sửa"}: ${t.f}, nhưng không được liệt kê trong 00-lite.md. Sửa/xóa test cũ phải được khai báo để Codex review có chủ đích.`);
    }
    note(`Luồng rút gọn (${codeFiles.length} file, ${codeLines} dòng code): bỏ Discovery/Architecture/Approval; vẫn bắt buộc Codex review + verification.`);
  }
} else if (!lib.repoFileExists(lib.taskFile(currentSlug, "02-architecture.md"))) {
  fail("G2", `Nhánh ${branch} nhưng không có docs/work/${currentSlug}/02-architecture.md.`);
} else {
  const appr = lib.checkApproval(currentSlug);
  for (const r of appr.reasons) fail("G2", `${currentSlug}: ${r}`);

  // ---------------------------------------------------------------- G3
  if (appr.ok) {
    const header = lib.parseHeader(lib.readArch(currentSlug));
    const trailer = `Architecture-Approved: ${currentSlug} ${header["Approved-hash"]}`;
    const candidates = gitLines(["log", "--format=%H", "-F", `--grep=${trailer}`, headRef]);
    if (!candidates.length) {
      fail("G3", `Không có commit duyệt mang trailer "${trailer}". Phải duyệt bằng: node scripts/approve.js ${currentSlug}`);
    } else {
      const c = commitInfo(candidates[0]);
      const archFile = lib.taskFile(currentSlug, "02-architecture.md");
      if (approvers.length && !approvers.includes(c.email)) fail("G3", `Commit duyệt ${c.short} do ${c.email} tạo, không có trong approvers.`);
      const marker = hasAgentMarker(c.body, cfg);
      if (marker) fail("G3", `Commit duyệt ${c.short} có dấu vết agent ("${marker}").`);
      const extra = c.files.filter((f) => f !== archFile);
      if (extra.length) fail("G3", `Commit duyệt ${c.short} chứa file khác ngoài ${archFile}: ${extra.join(", ")}`);
      if (cfg.requireSignedApproval) checkSigned(c, "G3", "Commit duyệt");
      // Phản biện Codex phải hoàn tất trước khi duyệt. Không có ngoại lệ.
      const crit = lib.checkCritique(currentSlug);
      for (const p of crit.problems) fail("G3", `${currentSlug}: ${p}`);
      if (crit.deferred.length) note(`Người duyệt chấp nhận rủi ro: ${crit.deferred.join(", ")}`);
      const approvedCritique = (c.body.match(/^Critique-Hash:\s*(\S+)$/m) || [])[1];
      const nowCritique = lib.critiqueHash(currentSlug);
      if (approvedCritique !== nowCritique) {
        fail("G3", `02-architecture-critique.md đã thay đổi SAU KHI duyệt (${approvedCritique || "không có"} ≠ ${nowCritique || "không có"}). Người dùng cần duyệt lại.`);
      }
    }
  }

  // ---------------------------------------------------------------- G4
  const scope = lib.parseScope(currentSlug) || [];
  if (!scope.length) {
    fail("G4", `Mục 5 "Phạm vi file" của thiết kế ${currentSlug} chưa có đường dẫn nào trong \`backtick\`.`);
  } else {
    for (const f of codeFiles.filter((f) => !matchesAny(f, scope))) {
      fail("G4", `${f} nằm ngoài phạm vi thiết kế đã duyệt. Cần thì cập nhật mục 5 và duyệt lại.`);
    }
    for (const t of testEdits.filter((t) => !matchesAny(t.f, scope))) {
      fail("G4", `Test đã có bị ${t.st === "D" ? "xóa" : "sửa"}: ${t.f}, nằm ngoài phạm vi thiết kế. Thêm test mới thì luôn được; sửa/xóa test cũ phải được liệt kê ở mục 5.`);
    }
  }
}

// ------------------------------------------------------------------ G5
for (const c of infos) {
  const touchesCodex = c.files.some((f) => f.startsWith(`${lib.WORK_REL}/`) && cfg.codexArtifacts.includes(path.basename(f)));
  if (!touchesCodex) continue;
  const extra = c.files.filter((f) => !f.startsWith(`${lib.WORK_REL}/`));
  if (extra.length) fail("G5", `Commit ${c.short} chứa file của Codex nhưng có cả code: ${extra.join(", ")}. Phải commit riêng.`);
}

// ------------------------------------------------------------------ G6 — file luật
const lawChanged = changed.filter(isLaw);
if (lawChanged.length) {
  const label = cfg.lawChangeLabel || "law-change-approved";
  if (LOCAL) {
    note(`PR này đổi file luật (${lawChanged.join(", ")}). Trên GitHub sẽ cần label "${label}" do bạn tự gắn.`);
  } else if (!labels.includes(label)) {
    fail(
      "G6",
      `PR đổi file luật: ${lawChanged.join(", ")}. Hãy tự đọc kỹ diff các file này, rồi gắn label "${label}" ` +
        `trên GitHub (gắn label sẽ chạy lại gate). Agent không được gắn label này.`
    );
  } else {
    // Ai đã gắn label? Lấy sự kiện labeled MỚI NHẤT của label này.
    const logins = (cfg.approverLogins || []).map((l) => l.toLowerCase());
    const actor = labelActor(label);
    if (!logins.length) fail("G6", "Chưa khai báo approverLogins (tài khoản GitHub được gắn label) trong workflow-gate.json.");
    else if (actor === null) fail("G6", `Không đọc được lịch sử label của PR để xác định ai gắn "${label}".`);
    else if (!logins.includes(actor.toLowerCase())) {
      fail("G6", `Label "${label}" do @${actor} gắn, không nằm trong approverLogins (${logins.map((l) => "@" + l).join(", ")}).`);
    } else note(`Label "${label}" do @${actor} gắn.`);
  }
  for (const c of infos) {
    const law = c.files.filter(isLaw);
    if (!law.length) continue;
    const marker = hasAgentMarker(c.body, cfg);
    if (marker) fail("G6", `Commit ${c.short} của agent ("${marker}") sửa file luật: ${law.join(", ")}`);
    else if (approvers.length && !approvers.includes(c.email)) fail("G6", `Commit ${c.short} (${c.email}) sửa file luật nhưng không phải approver.`);
    if (cfg.requireSignedApproval) checkSigned(c, "G6", "Commit sửa file luật");
  }
}

// ------------------------------------------------------------------ G7
{
  const diff = git(["diff", "-U0", "--no-color", "--no-renames", mergeBase, headRef]);
  let file = null;
  let line = 0;
  for (const l of diff.split("\n")) {
    if (l.startsWith("+++ ")) {
      file = l.startsWith("+++ b/") ? l.slice(6) : null;
      continue;
    }
    const h = l.match(/^@@ -\d+(?:,\d+)? \+(\d+)/);
    if (h) {
      line = parseInt(h[1], 10);
      continue;
    }
    if (!file || l.startsWith("---") || !l.startsWith("+")) continue;
    if (!matchesAny(file, cfg.forbiddenAddedExclude)) {
      for (const rule of cfg.forbiddenAdded || []) {
        if (rule.onlyIn && !matchesAny(file, rule.onlyIn)) continue;
        if (new RegExp(rule.pattern).test(l.slice(1))) fail("G7", `${file}:${line} — ${rule.message}: ${l.slice(1).trim().slice(0, 120)}`);
      }
    }
    line++;
  }
}

finish();

// ------------------------------------------------------------------ helpers
function commitInfo(sha) {
  const raw = git(["show", "-s", "--format=%ae%x1f%an%x1f%B", sha]);
  const [email, name, body] = raw.split("\x1f");
  const files = gitLines(["diff-tree", "--no-commit-id", "--name-only", "-r", "--no-renames", "-m", "--first-parent", sha]);
  return { sha, short: sha.slice(0, 8), email: email.trim().toLowerCase(), name: name.trim(), body, files };
}

// Trả về login của người gắn label (sự kiện labeled mới nhất), hoặc null nếu không đọc được.
// Nguồn: GATE_LABEL_EVENTS_FILE (dòng TSV: event<TAB>label<TAB>login — dùng khi chạy thử) hoặc GitHub API.
function labelActor(label) {
  let tsv;
  if (process.env.GATE_LABEL_EVENTS_FILE) {
    try {
      tsv = fs.readFileSync(process.env.GATE_LABEL_EVENTS_FILE, "utf8");
    } catch {
      return null;
    }
  } else {
    if (!prNumber || !process.env.GITHUB_REPOSITORY) return null;
    try {
      const { execFileSync } = require("child_process");
      tsv = execFileSync(
        "gh",
        ["api", "--paginate", `repos/${process.env.GITHUB_REPOSITORY}/issues/${prNumber}/events`,
          "--jq", '.[] | select(.event == "labeled" or .event == "unlabeled") | [.event, .label.name, .actor.login] | @tsv'],
        { encoding: "utf8", env: process.env }
      );
    } catch {
      return null;
    }
  }
  let actor = null;
  for (const line of tsv.split("\n")) {
    const [ev, name, login] = line.trim().split("\t");
    if (name !== label) continue;
    actor = ev === "labeled" ? login : null;
  }
  return actor;
}

function checkSigned(c, code, what) {
  if (LOCAL) {
    try {
      git(["verify-commit", c.sha]);
    } catch {
      fail(code, `${what} ${c.short} chưa được ký hoặc chữ ký không hợp lệ (git verify-commit).`);
    }
    return;
  }
  try {
    const { execFileSync } = require("child_process");
    const out = execFileSync(
      "gh",
      ["api", `repos/${process.env.GITHUB_REPOSITORY}/commits/${c.sha}`, "--jq", ".commit.verification.verified"],
      { encoding: "utf8", env: process.env }
    ).trim();
    if (out !== "true") fail(code, `${what} ${c.short} không có chữ ký được GitHub xác minh (Verified).`);
  } catch (e) {
    fail(code, `Không kiểm tra được chữ ký ${c.short} qua GitHub API: ${e.message.split("\n")[0]}`);
  }
}

function finish() {
  const seen = new Set();
  for (let i = errors.length - 1; i >= 0; i--) {
    const body = errors[i].replace(/^\[[A-Z0-9]+\] /, "");
    if (seen.has(body)) errors.splice(i, 1);
    else seen.add(body);
  }
  const head = LOCAL ? "Cổng quy trình (chạy thử local)" : "Cổng quy trình";
  const summary = process.env.GITHUB_STEP_SUMMARY;
  if (notes.length) console.log(`ℹ ${head} — ghi chú:\n  - ${notes.join("\n  - ")}\n`);
  if (errors.length) {
    console.error(`⛔ ${head}: ${errors.length} lỗi\n  - ${errors.join("\n  - ")}`);
    if (summary) fs.appendFileSync(summary, `## ⛔ Workflow gate\n\n${errors.map((e) => `- ${e}`).join("\n")}\n`);
    process.exit(1);
  }
  console.log(`✅ ${head}: đạt${currentSlug ? ` (task ${currentSlug})` : ""}.`);
  if (summary) fs.appendFileSync(summary, `## ✅ Workflow gate đạt\n${notes.map((n) => `- ${n}`).join("\n")}\n`);
  process.exit(0);
}
