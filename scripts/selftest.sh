#!/usr/bin/env bash
# =============================================================================
# SELFTEST — kiểm chứng các cổng quy trình bằng KỊCH BẢN VI PHẠM CÓ CHỦ ĐÍCH
# =============================================================================
# Chạy ở thư mục gốc project (Git Bash trên Windows, WSL, macOS, Linux):
#     bash scripts/selftest.sh            # chạy tất cả
#     bash scripts/selftest.sh -v         # in cả output của gate
#
# Lệnh này KHÔNG đụng vào repo của bạn: nó tạo một repo git TẠM, chép scripts/, .github/,
# docs/work/_template/ và tests/Arch/ vào đó, rồi giả lập các PR — đúng cách gate.yml chạy trên
# GitHub: validator lấy từ nhánh main, nội dung PR đọc như dữ liệu.
# Mỗi kịch bản có kết quả KỲ VỌNG (qua / chặn bởi cổng nào). Lệch kỳ vọng → exit 1.
#
# Không kiểm tra được ở đây (cần Laravel thật / GitHub): Pest, Larastan, Infection, queue-smoke,
# branch protection. Những phần đó kiểm chứng bằng CI và scripts/setup-github.sh.
# =============================================================================
set -uo pipefail

VERBOSE="${1:-}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for need in git node; do command -v "$need" >/dev/null || { echo "Cần $need"; exit 2; }; done

TMP="$(mktemp -d 2>/dev/null || mktemp -d -t selftest)"
trap 'rm -rf "$TMP"' EXIT
OWNER_EMAIL="owner@selftest.local"
AGENT="Co-Authored-By: Claude <noreply@anthropic.com>"
PASSED=0; FAILED=0; RESULTS=()

# ----------------------------------------------------------------------------- dựng repo tạm
git init -q --bare "$TMP/remote.git"
git init -q -b main "$TMP/app"
cd "$TMP/app"
git config user.name "Owner"; git config user.email "$OWNER_EMAIL"
git config commit.gpgsign false; git config core.autocrlf false
git remote add origin "$TMP/remote.git"

mkdir -p scripts .github docs/work tests/Arch tests/Feature app/Http/Controllers database/migrations
cp -R "$SRC/scripts/." scripts/
cp -R "$SRC/.github/." .github/
cp -R "$SRC/docs/work/_template" docs/work/
[[ -d "$SRC/tests/Arch" ]] && cp -R "$SRC/tests/Arch/." tests/Arch/
printf '<?php\n' > artisan
printf '{}\n' > composer.json
printf '<?php\nclass OrderController {}\n' > app/Http/Controllers/OrderController.php
printf '<?php\nit("existing", fn () => expect(1 + 1)->toBe(2));\n' > tests/Feature/ExistingTest.php
touch database/migrations/.gitkeep
node -e '
const f=".github/workflow-gate.json"; const c=JSON.parse(require("fs").readFileSync(f,"utf8"));
c.approvers=[process.argv[1]]; c.approverLogins=["owner-gh"]; c.requireSignedApproval=false;
require("fs").writeFileSync(f, JSON.stringify(c,null,2));' "$OWNER_EMAIL"
git add -A && git commit -qm "chore: init" && git push -q origin main 2>/dev/null
git worktree add -q --detach "$TMP/trusted" main
printf 'labeled\tlaw-change-approved\towner-gh\n' > "$TMP/ev-owner.tsv"
printf 'labeled\tlaw-change-approved\tsomeone-else\n' > "$TMP/ev-other.tsv"

# ----------------------------------------------------------------------------- tiện ích
gate() { # gate <branch> [labels] [events-file] → in output, trả exit code của gate
  git -C "$TMP/trusted" fetch -q "$TMP/app" "+refs/heads/$1:refs/remotes/pr/head" 2>/dev/null
  (cd "$TMP/trusted" && GITHUB_ACTIONS=1 GITHUB_HEAD_REF="$1" GATE_LABELS="${2:-}" \
    GATE_LABEL_EVENTS_FILE="${3:-$TMP/none}" GATE_HEAD_REF=refs/remotes/pr/head GATE_BASE_REF=main \
    node scripts/ci-gate.js 2>&1)
}

expect() { # expect "<tên>" PASS|<mã cổng> <branch> [labels] [events]
  local name="$1" want="$2"; shift 2
  local out code ok=0
  out="$(gate "$@")"; code=$?
  if [[ "$want" == "PASS" ]]; then [[ $code -eq 0 ]] && ok=1
  else [[ $code -ne 0 && "$out" == *"[$want]"* ]] && ok=1; fi
  if ((ok)); then PASSED=$((PASSED+1)); RESULTS+=("✅ $name  (kỳ vọng: $want)")
  else FAILED=$((FAILED+1)); RESULTS+=("❌ $name  (kỳ vọng: $want, exit=$code)"); out="${out}"$'\n'; VERBOSE="-v"; fi
  [[ "$VERBOSE" == "-v" ]] && { echo "──── $name"; echo "$out" | sed 's/^/    /'; }
}

expect_cmd() { # expect_cmd "<tên>" FAIL|OK <lệnh...>  (cho các lệnh local như approve.js)
  local name="$1" want="$2"; shift 2
  local out code
  out="$("$@" 2>&1 </dev/null)"; code=$?
  if [[ ( "$want" == "FAIL" && $code -ne 0 ) || ( "$want" == "OK" && $code -eq 0 ) ]]; then
    PASSED=$((PASSED+1)); RESULTS+=("✅ $name  (kỳ vọng: $want)")
  else FAILED=$((FAILED+1)); RESULTS+=("❌ $name  (kỳ vọng: $want, exit=$code)"); echo "$out" | sed 's/^/    /'; fi
}

commit_as() { # commit_as owner|agent|<email> "<msg>" [trailer...]
  local who="$1" msg="$2"; shift 2
  local args=(-m "$msg"); for t in "$@"; do args+=(-m "$t"); done
  git add -A
  case "$who" in
    owner) git commit -q --allow-empty "${args[@]}" ;;
    agent) git commit -q --allow-empty "${args[@]}" -m "$AGENT" ;;
    *)     git -c user.email="$who" commit -q --allow-empty "${args[@]}" ;;
  esac
}

new_design() { # new_design <slug> <critique-verdict> <sec11-row-or-empty> [scope-extra-row]
  local slug="$1"
  mkdir -p "docs/work/$slug"
  echo "# discovery" > "docs/work/$slug/01-discovery.md"
  node - "$slug" "$3" "${4:-}" <<'JS'
const fs = require("fs"); const [slug, sec11, extra] = process.argv.slice(2);
let s = fs.readFileSync("docs/work/_template/02-architecture.md", "utf8").replace("<tên task>", slug);
s = s.replace("| File | Tạo/Sửa | Lý do |\n|---|---|---|",
  "| File | Tạo/Sửa | Lý do |\n|---|---|---|\n| `app/Actions/Coupons/ApplyCoupon.php` | Tạo | |\n| `app/Http/Controllers/OrderController.php` | Sửa | |" + (extra ? "\n" + extra : ""));
if (sec11) s = s.replace("| Mã | Mức độ | Xử lý | Lý do / thay đổi |\n|---|---|---|---|", "| Mã | Mức độ | Xử lý | Lý do / thay đổi |\n|---|---|---|---|\n" + sec11);
fs.writeFileSync(`docs/work/${slug}/02-architecture.md`, s);
JS
  printf '# critique\n\nVerdict: %s\n\n| Mã | Mức độ | Mục | Vấn đề |\n|---|---|---|---|\n| A-01 | BLOCKER | 3 | thiếu khóa |\n| A-02 | MINOR | 2 | tên |\n' "$2" \
    > "docs/work/$slug/02-architecture-critique.md"
}

approve_as() { # approve_as owner|agent|<email> <slug> — tạo commit duyệt GIỐNG HỆT approve.js
  local who="$1" slug="$2" info
  info="$(node - "$slug" <<'JS'
const l = require("./scripts/approval-lib"); const fs = require("fs"); const slug = process.argv[2];
let t = l.readArch(slug);
t = l.setHeader(t, "Status", "APPROVED"); t = l.setHeader(t, "Approved-by", "Owner");
t = l.setHeader(t, "Approved-date", "2026-01-01"); const h = l.contentHash(t); t = l.setHeader(t, "Approved-hash", h);
fs.writeFileSync(l.archPath(slug), t);
console.log(h + " " + (l.critiqueHash(slug) || ""));
JS
)"
  local hash="${info%% *}" ch="${info#* }"
  local tr="Architecture-Approved: $slug $hash"$'\n'"Approved-by: Owner"
  [[ -n "$ch" ]] && tr+=$'\n'"Critique-Hash: $ch"
  commit_as "$who" "docs($slug): approve" "$tr"
}

finish_task() { # finish_task <slug> — 04 + review PASS + verification đúng Code-Tree (đều do "Codex"/Claude commit)
  local slug="$1"
  [[ -f "docs/work/$slug/02-architecture.md" ]] && cp docs/work/_template/04-implementation.md "docs/work/$slug/" && commit_as agent "docs: 04"
  printf '## Lần 1\n\nVerdict: PASS\n\n| Mã | Mức độ | Trạng thái | File |\n|---|---|---|---|\n| R-01 | MINOR | FIXED | x |\n' > "docs/work/$slug/05-review.md"
  commit_as owner "docs($slug): 05-review"
  printf 'Verdict: PASS\nCode-Tree: %s\n' "$(node scripts/code-tree.js)" > "docs/work/$slug/06-verification.md"
  commit_as owner "docs($slug): 06-verification"
}

code_change() { # sửa 2 file code trong phạm vi
  mkdir -p app/Actions/Coupons
  printf '<?php\nclass ApplyCoupon { public function handle(): void {} }\n' > app/Actions/Coupons/ApplyCoupon.php
  printf '<?php\nclass OrderController { public function store() { return request()->only("code"); } }\n' > app/Http/Controllers/OrderController.php
  printf '<?php\nit("applies", fn () => expect(collect([1, 2])->skip(1)->count())->toBe(1));\n' > tests/Feature/ApplyCouponTest.php
}

review_latest() { # review_latest <slug> "<dòng bảng>"... — thêm "Lần N" mới với Verdict PASS
  local slug="$1"; shift
  { printf '\n## Lần 9\n\nVerdict: PASS\n\n| Mã | Mức độ | Trạng thái | File |\n|---|---|---|---|\n'; printf '%s\n' "$@"; } >> "docs/work/$slug/05-review.md"
  commit_as owner "docs($slug): review lần 9"
}

lite_task() { # lite_task <slug> <file1> [file...] — nhánh chore/<slug> + 00-lite.md liệt kê các file
  local slug="$1"; shift
  git switch -q -c "chore/$slug" main
  mkdir -p "docs/work/$slug"
  node - "$slug" "$@" <<'JS'
const fs = require("fs"); const [slug, ...files] = process.argv.slice(2);
let s = fs.readFileSync("docs/work/_template/00-lite.md", "utf8");
s = s.replace("| File | Thay đổi |\n|---|---|", "| File | Thay đổi |\n|---|---|\n" + files.map((f) => "| `" + f + "` | sửa |").join("\n"));
fs.writeFileSync(`docs/work/${slug}/00-lite.md`, s);
JS
}

reset_to() { git switch -q "$1" && git reset -q --hard "$2"; }

# ============================================================================= KỊCH BẢN
echo "Đang chạy selftest (repo tạm: $TMP)…"

# ---------- Task đủ, làm đúng quy trình
git switch -q -c feat/add-coupon main
new_design add-coupon NEEDS_CHANGES "| A-01 | BLOCKER | ACCEPTED | thêm Cache::lock |"
commit_as agent "docs: design + critique"
approve_as owner add-coupon
code_change; commit_as agent "feat: coupon"
finish_task add-coupon
GOOD="$(git rev-parse HEAD)"
expect "01 Quy trình đủ, làm đúng (có ->only()/->skip() hợp lệ)" PASS feat/add-coupon

# ---------- Lách validator / file luật
reset_to feat/add-coupon "$GOOD"; sed -i.bak '1a process.exit(0);' scripts/ci-gate.js && rm -f scripts/ci-gate.js.bak; commit_as agent "chore: tweak gate"
expect "02 Agent sửa ci-gate.js trong PR thành luôn pass" G6 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; echo "# x" >> .github/workflows/ci.yml; commit_as owner "ci: tweak"
expect "03 Sửa ci.yml không có label" G6 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; echo "// nới lỏng" >> tests/Arch/ArchitectureTest.php; commit_as agent "test: relax arch"
expect "04 Agent nới lỏng test kiến trúc" G6 feat/add-coupon
git switch -q -c chore/tune-rules main; echo "// rule mới" >> tests/Arch/ArchitectureTest.php; commit_as owner "test: tune arch"
expect "05 Bạn đổi file luật, chưa gắn label" G6 chore/tune-rules
expect "06 Bạn đổi file luật, label do chính bạn gắn" PASS chore/tune-rules law-change-approved "$TMP/ev-owner.tsv"
expect "07 Label do tài khoản khác gắn" G6 chore/tune-rules law-change-approved "$TMP/ev-other.tsv"
expect "08 Label có nhưng không đọc được ai gắn" G6 chore/tune-rules law-change-approved

# ---------- Duyệt thiết kế
reset_to feat/add-coupon "$GOOD"; echo "- thêm yêu cầu" >> docs/work/add-coupon/02-architecture.md; commit_as agent "docs: tweak"
expect "09 Sửa thiết kế sau khi duyệt" G2 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; echo "- thêm" >> docs/work/add-coupon/02-architecture.md; approve_as agent add-coupon
expect "10 Agent tự duyệt lại (dấu vết agent)" G3 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; echo "- thêm" >> docs/work/add-coupon/02-architecture.md; approve_as bot@x.local add-coupon
expect "11 Tự duyệt bằng email khác" G3 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; printf 'Verdict: READY\n' > docs/work/add-coupon/02-architecture-critique.md; commit_as owner "docs: critique"
expect "12 Sửa file phản biện sau khi duyệt (xóa BLOCKER)" G3 feat/add-coupon

git switch -q -c feat/no-critique main; new_design no-critique NEEDS_CHANGES ""; rm docs/work/no-critique/02-architecture-critique.md
commit_as agent "docs: design"; approve_as owner no-critique; code_change; commit_as agent "feat"; finish_task no-critique
expect "13 Duyệt khi chưa có phản biện Codex" G3 feat/no-critique
git switch -q -c feat/redo main; new_design redo REDO "| A-01 | BLOCKER | ACCEPTED | x |"; commit_as agent d; approve_as owner redo; code_change; commit_as agent f; finish_task redo
expect "14 Codex kết luận REDO" G3 feat/redo
git switch -q -c feat/badresp main; new_design badresp NEEDS_CHANGES "| A-01 | BLOCKER | OK | x |"; commit_as agent d; approve_as owner badresp; code_change; commit_as agent f; finish_task badresp
expect "15 Phản hồi phản biện giá trị lạ (OK)" G3 feat/badresp
git switch -q -c feat/noresp main; new_design noresp NEEDS_CHANGES ""; commit_as agent d; approve_as owner noresp; code_change; commit_as agent f; finish_task noresp
expect "16 BLOCKER chưa được Claude phản hồi ở mục 11" G3 feat/noresp
git switch -q -c feat/badrow main; new_design badrow NEEDS_CHANGES "| A-01 | BLOCKER | ACCEPTED | x |"
printf '| ??? | BLOCKER | 1 | dòng thiếu mã |\n' >> docs/work/badrow/02-architecture-critique.md
commit_as agent d; approve_as owner badrow; code_change; commit_as agent f; finish_task badrow
expect "17 Bảng phản biện có dòng thiếu mã" G3 feat/badrow
git switch -q -c feat/deferred main; new_design deferred NEEDS_CHANGES "| A-01 | BLOCKER | DEFERRED | làm sau |"; commit_as agent d; approve_as owner deferred; code_change; commit_as agent f; finish_task deferred
expect "18 DEFERRED (bạn chấp nhận rủi ro khi duyệt)" PASS feat/deferred

# ---------- Review / Verification
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| R-02 | BLOCKER | OPEN | x |"
expect "19 Review mới nhất còn BLOCKER OPEN" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| R-02 | MAJOR | PENDING | x |"
expect "20 Review MAJOR trạng thái PENDING" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| R-02 | MAJRO | FIXED | x |"
expect "21 Review mức độ viết sai" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| | MAJOR | FIXED | dòng thiếu mã |"
expect "22 Review có dòng thiếu mã" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| R-02 | MAJOR | FIXED | a |" "| R-02 | MAJOR | OPEN | b |"
expect "23 Review trùng mã" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; review_latest add-coupon "| R-02 | MAJOR | ACCEPTED | x |"
expect "24 ACCEPTED nhưng bạn chưa chạy accept-risk.js" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; commit_as agent "docs: accept" "Review-Risk-Accepted: add-coupon R-02"; review_latest add-coupon "| R-02 | MAJOR | ACCEPTED | x |"
expect "25 ACCEPTED với commit chấp nhận do agent tạo" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; commit_as owner "docs: accept" "Review-Risk-Accepted: add-coupon R-2"; review_latest add-coupon "| R-02 | MAJOR | ACCEPTED | x |"
expect "26 Commit chấp nhận cho mã khác (R-2 ≠ R-02)" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; commit_as owner "docs: accept" "Review-Risk-Accepted: add-coupon R-02"$'\n'"Reason: chấp nhận vì tạm thời"; review_latest add-coupon "| R-02 | MAJOR | ACCEPTED | x |"
expect "27 ACCEPTED với commit accept-risk của bạn" PASS feat/add-coupon
reset_to feat/add-coupon "$GOOD"; echo "// sửa sau verify" >> app/Actions/Coupons/ApplyCoupon.php; commit_as agent late
expect "28 Sửa code sau khi verify" G1 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; cp docs/work/_template/06-verification.md docs/work/add-coupon/; commit_as owner v
expect "29 Verification để nguyên template" G1 feat/add-coupon
git switch -q -c feat/no04 main; new_design no04 NEEDS_CHANGES "| A-01 | BLOCKER | ACCEPTED | x |"; commit_as agent d; approve_as owner no04
code_change; commit_as agent f
expect "30 Có code nhưng chưa có 04/05/06" G1 feat/no04

# ---------- Phạm vi
reset_to feat/add-coupon "$GOOD"; mkdir -p app/Services; printf '<?php\n' > app/Services/Extra.php; commit_as agent extra
expect "31 Sửa file ngoài phạm vi thiết kế" G4 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; git rm -q tests/Feature/ExistingTest.php; commit_as agent "test: remove"
expect "32 Xóa test đã có (ngoài phạm vi)" G4 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; printf '<?php\nit("existing", fn () => expect(true)->toBeTrue());\n' > tests/Feature/ExistingTest.php; commit_as agent "test: weaken"
expect "33 Làm yếu test đã có (ngoài phạm vi)" G4 feat/add-coupon
git switch -q -c random/weaken main; printf '<?php\nit("existing", fn () => expect(true)->toBeTrue());\n' > tests/Feature/ExistingTest.php; commit_as agent t
expect "34 Nhánh tùy ý chỉ sửa test cũ, không task" G2 random/weaken
git switch -q -c random/newtest main; printf '<?php\nit("new", fn () => expect(1)->toBe(1));\n' > tests/Feature/NewTest.php; commit_as agent t
expect "35 Nhánh tùy ý chỉ THÊM test mới" PASS random/newtest
git switch -q -c random/code main; printf '<?php\nclass OrderController {} // typo\n' > app/Http/Controllers/OrderController.php; commit_as agent t
expect "36 Nhánh tùy ý có code, không task" G2 random/code
git switch -q -c docs-only main; echo "# note" > docs/note.md; commit_as agent d
expect "37 Chỉ đổi docs" PASS docs-only
reset_to feat/add-coupon "$GOOD"; echo "//" >> app/Actions/Coupons/ApplyCoupon.php; echo "x" >> docs/work/add-coupon/05-review.md; commit_as owner mixed
expect "38 Commit review lẫn code" G5 feat/add-coupon

# ---------- Mã lách luật
reset_to feat/add-coupon "$GOOD"; printf '<?php\nit("x", fn () => 1)->skip();\n' > tests/Feature/SkipTest.php; commit_as agent s
expect "39 Thêm ->skip() vào test" G7 feat/add-coupon
reset_to feat/add-coupon "$GOOD"; printf '<?php\nclass ApplyCoupon { /** @phpstan-ignore-next-line */ public function handle() { return env("K"); } }\n' > app/Actions/Coupons/ApplyCoupon.php; commit_as agent s
expect "40 @phpstan-ignore + env() trong app" G7 feat/add-coupon

# ---------- Luồng rút gọn
lite_task fix-typo app/Http/Controllers/OrderController.php
printf '<?php\nclass OrderController {} // typo fixed\n' > app/Http/Controllers/OrderController.php; commit_as agent "chore: typo"; finish_task fix-typo
expect "41 Luồng rút gọn làm đúng" PASS chore/fix-typo
lite_task no-review app/Http/Controllers/OrderController.php; printf '<?php\nclass OrderController {} // x\n' > app/Http/Controllers/OrderController.php; commit_as agent x
expect "42 Rút gọn nhưng thiếu Codex review/verify" G1 chore/no-review
lite_task too-big app/Services/B1.php app/Services/B2.php app/Services/B3.php app/Services/B4.php
mkdir -p app/Services; for i in 1 2 3 4; do printf '<?php\nclass B%s {}\n' "$i" > "app/Services/B$i.php"; done; commit_as agent big; finish_task too-big
expect "43 Rút gọn nhưng 4 file code" G2 chore/too-big
lite_task mig database/migrations/2026_01_01_add_col.php; printf '<?php\n' > database/migrations/2026_01_01_add_col.php; commit_as agent m; finish_task mig
expect "44 Rút gọn nhưng đụng migration" G2 chore/mig
lite_task unlisted app/Http/Controllers/OrderController.php; mkdir -p app/Support; printf '<?php\n' > app/Support/Helper.php; commit_as agent u; finish_task unlisted
expect "45 Rút gọn sửa file không liệt kê trong 00-lite" G4 chore/unlisted
lite_task lite-late app/Http/Controllers/OrderController.php; printf '<?php\nclass OrderController {} // a\n' > app/Http/Controllers/OrderController.php; commit_as agent a; finish_task lite-late
printf '<?php\nclass OrderController {} // b\n' > app/Http/Controllers/OrderController.php; commit_as agent b
expect "46 Rút gọn, sửa code sau verify" G1 chore/lite-late
git switch -q -c chore/nolite main; printf '<?php\nclass OrderController {} // y\n' > app/Http/Controllers/OrderController.php; commit_as agent y
expect "47 Nhánh chore/ không có 00-lite.md" G2 chore/nolite

# ---------- Lệnh chỉ dành cho người dùng
git switch -q feat/add-coupon
expect_cmd "48 Agent chạy approve.js (không có terminal thật)" FAIL node scripts/approve.js add-coupon
expect_cmd "49 Agent chạy accept-risk.js (không có terminal thật)" FAIL node scripts/accept-risk.js add-coupon R-01 --reason "agent tự chấp nhận rủi ro"
expect_cmd "50 approve.js với CLAUDECODE=1" FAIL env CLAUDECODE=1 node scripts/approve.js add-coupon

# ============================================================================= KẾT QUẢ
echo
printf '%s\n' "${RESULTS[@]}"
echo
TOTAL=$((PASSED + FAILED))
if ((FAILED)); then
  echo "⛔ $FAILED/$TOTAL kịch bản LỆCH kỳ vọng. Cổng quy trình chưa đáng tin — đừng dùng cho tới khi sửa."
  exit 1
fi
echo "✅ $TOTAL/$TOTAL kịch bản đúng kỳ vọng: các cổng chặn đúng tình huống sai và cho qua đúng tình huống hợp lệ."
