#!/usr/bin/env node
// Kiểm tra cổng duyệt (bước 3). Claude và Codex chạy ở đầu bước 4, 5, 6.
//
//   node scripts/check-approval.js <slug>   kiểm tra một task
//   node scripts/check-approval.js --all    mọi task đã có code: đủ Approval → Review → Verification
//
// Exit 0 = hợp lệ. Exit 1 = bị chặn, lý do in ra stderr.
// (Trên GitHub, cổng đầy đủ chạy bằng scripts/ci-gate.js trong .github/workflows/gate.yml.)
const lib = require("./approval-lib");

const arg = process.argv[2];
if (!arg) {
  console.error("Cách dùng: node scripts/check-approval.js <slug> | --all");
  process.exit(1);
}

if (arg !== "--all" && arg !== "--ci") {
  if (lib.taskKind(arg) === "lite") {
    console.log(`✅ ${arg}: luồng rút gọn (00-lite.md) — không cần duyệt thiết kế. Vẫn bắt buộc Codex review + verification.`);
    process.exit(0);
  }
  const { ok, reasons } = lib.checkApproval(arg);
  if (ok) {
    console.log(`✅ ${arg}: thiết kế đã được duyệt hợp lệ.`);
    process.exit(0);
  }
  console.error(`⛔ ${arg}: CHƯA ĐƯỢC PHÉP triển khai.\n - ` + reasons.join("\n - "));
  process.exit(1);
}

const slugs = lib.listSlugs().filter(
  (s) => lib.taskKind(s) === "lite" || (lib.taskKind(s) === "full" && lib.repoFileExists(lib.taskFile(s, "04-implementation.md")))
);
const failures = slugs
  .map((slug) => [slug, lib.checkTaskComplete(slug, { headRef: "HEAD", requireApproval: lib.taskKind(slug) === "full" })])
  .filter(([, r]) => r.length)
  .map(([slug, r]) => `${slug}:\n   - ${r.join("\n   - ")}`);

if (failures.length) {
  console.error("⛔ Quy trình chưa hoàn tất:\n" + failures.join("\n"));
  process.exit(1);
}
console.log(`✅ ${slugs.length} task đã qua đủ Approval → Review → Verification.`);
