#!/usr/bin/env node
// Codex chạy lệnh này ở đầu và cuối bước 2b, 5, 6.
// Pass khi KHÔNG có thay đổi chưa commit nào nằm ngoài docs/work/.
//   - Đầu bước: bảo đảm Claude đã commit xong code, nên Codex review đúng thứ đã commit.
//   - Cuối bước: bảo đảm Codex không sửa code (chỉ ghi file trong docs/work/).
const { git } = require("./approval-lib");

let out;
try {
  out = git(["status", "--porcelain", "-z", "--untracked-files=all"]);
} catch (e) {
  console.error("Không chạy được git status: " + e.message);
  process.exit(1);
}
// Định dạng -z: "XY path\0", đổi tên: "R  new\0old\0".
const parts = out.split("\0");
const files = [];
for (let i = 0; i < parts.length; i++) {
  const entry = parts[i];
  if (!entry) continue;
  files.push(entry.slice(3));
  if (entry[0] === "R" || entry[0] === "C") files.push(parts[++i]);
}
const dirty = [...new Set(files)].filter((p) => p && !p.startsWith("docs/work/"));

if (dirty.length) {
  console.error(
    "⛔ Có thay đổi chưa commit ngoài docs/work/:\n  - " +
      dirty.join("\n  - ") +
      "\n\nĐầu bước: Claude cần commit xong (hoặc người dùng tự commit/stash) trước khi Codex chạy." +
      "\nCuối bước: Codex không được sửa các file này. Hoàn tác bằng: git checkout -- <file> (file mới: xóa đi)."
  );
  process.exit(1);
}
console.log("✅ Cây làm việc sạch (ngoài docs/work/).");
