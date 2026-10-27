#!/usr/bin/env node
// BƯỚC 3 — APPROVAL. CHỈ NGƯỜI DÙNG CHẠY LỆNH NÀY (Claude bị chặn trong .claude/settings.json).
//
//   node scripts/approve.js <slug>            duyệt thiết kế
//   node scripts/approve.js <slug> --revoke   hủy duyệt (về DRAFT)
//
// KHÔNG có cách duyệt khi thiếu phản biện Codex: Architecture luôn là Claude + Codex.
// Nhận xét đã được Codex đánh giá mà bạn chấp nhận rủi ro → Claude ghi DEFERRED ở mục 11.
//
// Điều kiện duyệt (giống CI, scripts/approval-lib.js → checkCritique):
//   - đã có 02-architecture-critique.md của Codex, Verdict READY | NEEDS_CHANGES (không REDO);
//   - mọi nhận xét BLOCKER/MAJOR (A-xx) đã có phản hồi ACCEPTED | REJECTED | DEFERRED ở mục 11.
//
// Lệnh ghi Status: APPROVED, người duyệt, ngày, hash nội dung vào 02-architecture.md
// và tạo một commit duyệt riêng (trailer "Architecture-Approved: <slug> <hash>", ký nếu bật requireSignedApproval).
// Sửa nội dung thiết kế sau đó làm lệch hash → check-approval.js và CI chặn các bước sau.
const fs = require("fs");
const readline = require("readline");
const path = require("path");
const { execSync, execFileSync } = require("child_process");
const { ROOT, archPath, readArch, parseHeader, contentHash, setHeader, loadGateConfig, checkCritique, critiqueHash } = require("./approval-lib");

let gateCfg = {};
try {
  gateCfg = loadGateConfig();
} catch {}

const [slug, flag] = process.argv.slice(2);
if (!slug || (flag && flag !== "--revoke")) {
  console.error("Cách dùng: node scripts/approve.js <slug> [--revoke]");
  process.exit(1);
}

const text = readArch(slug);
if (text === null) {
  console.error(`Không thấy ${archPath(slug)}`);
  process.exit(1);
}

if (!process.stdin.isTTY || process.env.CLAUDECODE || process.env.CODEX_SANDBOX || process.env.CODEX_SANDBOX_NETWORK_DISABLED) {
  console.error("Lệnh duyệt phải do NGƯỜI DÙNG chạy trong terminal của mình, không phải agent.");
  process.exit(1);
}

if (flag === "--revoke") {
  let out = setHeader(text, "Status", "DRAFT");
  out = setHeader(out, "Approved-hash", "");
  fs.writeFileSync(archPath(slug), out);
  console.log(`Đã hủy duyệt ${slug}. Status: DRAFT`);
  process.exit(0);
}

const header = parseHeader(text);

// Cảnh báo những chỗ thường bị bỏ quên trước khi duyệt.
const warnings = [];
const pendingDecisions = text.match(/## 10\. Quyết định chờ duyệt[\s\S]*?(?=\n## )/);
if (pendingDecisions) {
  const rows = pendingDecisions[0]
    .split("\n")
    .filter((l) => /^\|\s*[^-|#\s]/.test(l) && !/Câu hỏi/.test(l));
  const unanswered = rows.filter((l) => {
    const cells = l.split("|").map((c) => c.trim());
    return cells.length >= 5 && cells[4] === "";
  });
  if (unanswered.length) warnings.push(`${unanswered.length} quyết định ở mục 10 chưa có câu trả lời ở cột "Người dùng chọn".`);
}
const critique = checkCritique(slug);
if (critique.problems.length && flag !== "--revoke") {
  console.error(`\n⛔ Chưa thể duyệt ${slug}: phản biện kiến trúc (Claude ⇄ Codex) chưa hoàn tất.\n  - ${critique.problems.join("\n  - ")}`);
  console.error(`\nLàm tiếp: Codex $arch-critique ${slug} → Claude /architect-revise ${slug}, rồi duyệt lại.`);
  console.error("Muốn chấp nhận rủi ro một nhận xét: yêu cầu Claude ghi DEFERRED cho mã đó ở mục 11 (không bỏ qua được phản biện).");
  process.exit(1);
}

let defaultName = "";
try {
  defaultName = execSync("git config user.name", { stdio: ["ignore", "pipe", "ignore"] }).toString().trim();
} catch {}

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
const ask = (q) => new Promise((r) => rl.question(q, r));

(async () => {
  console.log(`\nDuyệt thiết kế: ${slug}  (Revision ${header["Revision"] || "?"})`);
  if (warnings.length) {
    console.log("\n⚠ Cảnh báo:");
    warnings.forEach((w) => console.log("  - " + w));
  }
  if (critique.deferred.length) {
    console.log(`\n⚠ Bạn sẽ CHẤP NHẬN RỦI RO cho các nhận xét DEFERRED: ${critique.deferred.join(", ")}`);
  }

  const ans = await ask(`\nBạn đã đọc kỹ và đồng ý duyệt? Gõ "yes" để duyệt: `);
  if (ans.trim().toLowerCase() !== "yes") {
    console.log("Đã hủy, không thay đổi gì.");
    rl.close();
    process.exit(1);
  }
  const name = (await ask(`Người duyệt [${defaultName}]: `)).trim() || defaultName;
  rl.close();
  if (!name) {
    console.error("Cần tên người duyệt.");
    process.exit(1);
  }
  const today = new Date().toISOString().slice(0, 10);
  let out = setHeader(text, "Status", "APPROVED");
  out = setHeader(out, "Approved-by", name);
  out = setHeader(out, "Approved-date", today);
  out = setHeader(out, "Approved-hash", contentHash(out));
  const hash = contentHash(out);
  fs.writeFileSync(archPath(slug), out);

  // Tạo commit duyệt riêng: chỉ chứa 02-architecture.md, có trailer để CI xác minh (ci-gate.js G3).
  const rel = path.relative(ROOT, archPath(slug)).split(path.sep).join("/");
  const args = ["commit", "--only", "-m", `docs(${slug}): approve architecture rev ${header["Revision"] || "?"}`,
    "-m", `Architecture-Approved: ${slug} ${hash}\nApproved-by: ${name}` +
      (critiqueHash(slug) ? `\nCritique-Hash: ${critiqueHash(slug)}` : "") +
      (critique.deferred.length ? `\nRisk-Accepted: ${critique.deferred.join(", ")}` : "")];
  if (gateCfg.requireSignedApproval) args.splice(1, 0, "-S");
  args.push("--", rel);
  try {
    execFileSync("git", ["add", "--", rel], { cwd: ROOT, stdio: "inherit" });
    execFileSync("git", args, { cwd: ROOT, stdio: "inherit" });
  } catch (e) {
    console.error(`\n⚠ Đã ghi APPROVED nhưng commit thất bại. Sửa lỗi git ở trên rồi tự commit đúng lệnh:\n  git ${args.map((a) => (/\s/.test(a) ? JSON.stringify(a) : a)).join(" ")}`);
    process.exit(1);
  }
  console.log(`\n✅ Đã duyệt ${slug} — hash ${hash} (đã tạo commit duyệt)`);
  console.log(`Bước tiếp theo: trong Claude Code gọi  /implement ${slug}`);
})();
