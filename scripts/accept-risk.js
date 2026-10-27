#!/usr/bin/env node
// CHẤP NHẬN RỦI RO cho nhận xét review (bước 5). CHỈ NGƯỜI DÙNG CHẠY (Claude bị chặn trong .claude/settings.json).
//
//   node scripts/accept-risk.js <slug> R-01 [R-02 ...] --reason "<lý do>"
//
// Tạo một commit RỖNG có trailer cho từng mã:
//   Review-Risk-Accepted: <slug> R-01
//   Reason: <lý do>
// Sau đó Codex mới được ghi trạng thái ACCEPTED cho các mã này khi review lại.
// CI chỉ chấp nhận ACCEPTED (BLOCKER/MAJOR) khi tìm thấy commit này, do approver tạo, không có dấu vết agent
// (và có chữ ký hợp lệ nếu bật requireSignedApproval).
const readline = require("readline");
const { execFileSync } = require("child_process");
const { ROOT, loadGateConfig, parseReview } = require("./approval-lib");

const args = process.argv.slice(2);
const slug = args[0];
const ri = args.indexOf("--reason");
const reason = ri > -1 ? (args[ri + 1] || "").trim() : "";
const codes = args.slice(1, ri > -1 ? ri : undefined).filter((c) => /^R-\d+$/.test(c));

if (!slug || !codes.length || reason.length < 10) {
  console.error('Cách dùng: node scripts/accept-risk.js <slug> R-01 [R-02 ...] --reason "<lý do, ít nhất 10 ký tự>"');
  process.exit(1);
}
if (!process.stdin.isTTY || process.env.CLAUDECODE || process.env.CODEX_SANDBOX || process.env.CODEX_SANDBOX_NETWORK_DISABLED) {
  console.error("Lệnh này phải do NGƯỜI DÙNG chạy trong terminal của mình, không phải agent.");
  process.exit(1);
}

let cfg = {};
try {
  cfg = loadGateConfig();
} catch {}

const review = parseReview(slug);
if (!review.exists) {
  console.error(`Không thấy docs/work/${slug}/05-review.md`);
  process.exit(1);
}
const known = new Set(review.codes || []);
const unknown = codes.filter((c) => !known.has(c));
if (unknown.length) {
  console.error(`Các mã không có trong lần review mới nhất: ${unknown.join(", ")}`);
  process.exit(1);
}

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
console.log(`\nChấp nhận rủi ro KHÔNG SỬA cho ${slug}: ${codes.join(", ")}\nLý do: ${reason}`);
rl.question('Bạn đã đọc kỹ các nhận xét này và chấp nhận rủi ro? Gõ "yes": ', (ans) => {
  rl.close();
  if (ans.trim().toLowerCase() !== "yes") {
    console.log("Đã hủy.");
    process.exit(1);
  }
  const body = codes.map((c) => `Review-Risk-Accepted: ${slug} ${c}`).join("\n") + `\nReason: ${reason.replace(/\s+/g, " ")}`;
  const gitArgs = ["commit", "--allow-empty", "-m", `docs(${slug}): accept review risk ${codes.join(", ")}`, "-m", body];
  if (cfg.requireSignedApproval) gitArgs.splice(1, 0, "-S");
  try {
    execFileSync("git", gitArgs, { cwd: ROOT, stdio: "inherit" });
  } catch {
    console.error("Commit thất bại.");
    process.exit(1);
  }
  console.log(`\n✅ Đã ghi nhận. Giờ yêu cầu Codex review lại ($code-review ${slug}) để đánh dấu ACCEPTED cho: ${codes.join(", ")}`);
});
