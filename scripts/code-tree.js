#!/usr/bin/env node
// In "Code-Tree" của commit hiện tại (hash toàn bộ code, bỏ qua docs/work/).
// Codex chạy lệnh này ở bước 6 và ghi kết quả vào 06-verification.md:   Code-Tree: <hash>
// CI so hash này với code của PR. Code đổi sau khi verify → CI chặn.
//
//   node scripts/code-tree.js          hash của HEAD (yêu cầu cây làm việc sạch ngoài docs/work/)
//   node scripts/code-tree.js <ref>    hash của một commit bất kỳ
const { codeTreeHash, git } = require("./approval-lib");

const ref = process.argv[2] || "HEAD";
if (ref === "HEAD") {
  const dirty = git(["status", "--porcelain", "--untracked-files=all"])
    .split("\n")
    .filter(Boolean)
    .filter((l) => !l.slice(3).startsWith("docs/work/"));
  if (dirty.length) {
    console.error("⛔ Còn thay đổi chưa commit ngoài docs/work/. Code-Tree chỉ có nghĩa với code đã commit:\n  " + dirty.join("\n  "));
    process.exit(1);
  }
}
console.log(codeTreeHash(ref));
