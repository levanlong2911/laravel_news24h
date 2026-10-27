#!/usr/bin/env bash
# Thiết lập phía GitHub cho cổng quy trình. Chạy MỘT LẦN, do bạn chạy (không nhờ agent).
#
#   bash scripts/setup-github.sh            # làm một mình: PR bắt buộc, CI bắt buộc, không cần người review
#   bash scripts/setup-github.sh --team     # có team: thêm bắt buộc Code Owner review
#
# Cần: GitHub CLI đã đăng nhập (gh auth login), quyền admin repo.
# Lưu ý: branch protection cho repo PRIVATE cần gói GitHub Pro/Team trở lên (repo public thì miễn phí).
# Chạy SAU KHI bộ kit (kể cả .github/workflows/gate.yml) đã được merge vào nhánh chính, và đang đứng ở nhánh đó.
set -euo pipefail

MODE="${1:-}"
cd "$(git rev-parse --show-toplevel)"

command -v gh >/dev/null || { echo "Cần cài GitHub CLI: https://cli.github.com"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "Chạy 'gh auth login' trước."; exit 1; }

REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner)"
LOGIN="$(gh api user -q .login)"
EMAIL="$(git config user.email || true)"
BASE="$(node -e 'console.log(require("./.github/workflow-gate.json").baseBranch)')"

echo "Repo: $REPO | Bạn: @$LOGIN <$EMAIL> | Nhánh chính: $BASE | Chế độ: ${MODE:-solo}"
read -r -p "Tiếp tục? (yes/no) " OK
[[ "$OK" == "yes" ]] || exit 1

# 1) CODEOWNERS: @OWNER → @login
sed -i.bak "s/@OWNER/@$LOGIN/g" .github/CODEOWNERS && rm -f .github/CODEOWNERS.bak

# 2) approvers: thêm email của bạn nếu chưa có
node - "$EMAIL" "$LOGIN" <<'JS'
const fs = require("fs"); const f = ".github/workflow-gate.json";
const email = (process.argv[2] || "").toLowerCase();
const raw = fs.readFileSync(f, "utf8"); const cfg = JSON.parse(raw);
if (!email) { console.error("Chưa có git config user.email"); process.exit(1); }
if (!cfg.approvers.map((e) => e.toLowerCase()).includes(email)) cfg.approvers.push(email);
const login = (process.argv[3] || "").toLowerCase();
cfg.approverLogins = cfg.approverLogins || [];
if (login && !cfg.approverLogins.map((l) => l.toLowerCase()).includes(login)) cfg.approverLogins.push(login);
fs.writeFileSync(f, JSON.stringify(cfg, null, 2) + "\n");
console.log("approvers =", cfg.approvers.join(", "), "| approverLogins =", cfg.approverLogins.join(", "));
JS

# 3) Commit cấu hình thẳng lên nhánh chính TRƯỚC khi bật bảo vệ
#    (gate luôn đọc approvers từ main, nên main phải có email của bạn trước đã)
git add .github/CODEOWNERS .github/workflow-gate.json
if ! git diff --cached --quiet; then
  CUR="$(git rev-parse --abbrev-ref HEAD)"
  [[ "$CUR" == "$BASE" ]] || { echo "⛔ Hãy chạy script này khi đang ở nhánh $BASE (đã merge bộ kit)."; exit 1; }
  git commit -m "chore: configure workflow gate (approvers, CODEOWNERS)"
  git push origin "$BASE"
fi

# 4) Label dành cho việc đổi file luật
gh label create "law-change-approved" --color B60205 \
  --description "Chủ repo đã đọc và cho phép đổi file luật (AGENTS.md, tests/Arch, .github, scripts...)" 2>/dev/null \
  || echo "(label law-change-approved đã có)"

# 5) Token mặc định của workflow chỉ có quyền đọc
gh api -X PUT "repos/$REPO/actions/permissions/workflow" \
  -f default_workflow_permissions=read -F can_approve_pull_request_reviews=false >/dev/null

# 6) Bảo vệ nhánh chính
if [[ "$MODE" == "--team" ]]; then
  REVIEWS='{"required_approving_review_count":1,"require_code_owner_reviews":true,"dismiss_stale_reviews":true}'
else
  REVIEWS='{"required_approving_review_count":0,"require_code_owner_reviews":false,"dismiss_stale_reviews":false}'
fi

gh api -X PUT "repos/$REPO/branches/$BASE/protection" \
  -H "Accept: application/vnd.github+json" --input - <<JSON
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["trusted-gate", "php-quality", "mutation-test", "js-quality"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": $REVIEWS,
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON

# 7) Đọc lại cấu hình thật trên GitHub để chắc nó đã được áp dụng
echo
echo "Kiểm tra lại cấu hình trên GitHub:"
PROT="$(gh api "repos/$REPO/branches/$BASE/protection" 2>/dev/null || true)"
if [[ -z "$PROT" ]]; then
  echo "⛔ Không đọc được branch protection (repo private cần GitHub Pro/Team, hoặc thiếu quyền admin)."; exit 1
fi
node - "$PROT" <<'JS'
const p = JSON.parse(process.argv[2]);
const need = ["trusted-gate", "php-quality", "mutation-test", "js-quality"];
const have = ((p.required_status_checks || {}).contexts) || [];
const miss = need.filter((c) => !have.includes(c));
const checks = [
  ["Bắt buộc 4 check", miss.length === 0, miss.length ? "thiếu: " + miss.join(", ") : have.join(", ")],
  ["Check phải cập nhật theo main (strict)", !!(p.required_status_checks || {}).strict, ""],
  ["Áp dụng cả admin", !!(p.enforce_admins || {}).enabled, ""],
  ["Bắt buộc qua PR", !!p.required_pull_request_reviews, ""],
  ["Cấm force-push", !(p.allow_force_pushes || {}).enabled, ""],
];
let bad = 0;
for (const [n, okv, d] of checks) { console.log(`  ${okv ? "✅" : "⛔"} ${n}${d ? " — " + d : ""}`); if (!okv) bad++; }
process.exit(bad ? 1 : 0);
JS
PERM="$(gh api "repos/$REPO/actions/permissions/workflow" --jq .default_workflow_permissions 2>/dev/null || echo "?")"
[[ "$PERM" == "read" ]] && echo "  ✅ Token workflow mặc định: read" || echo "  ⛔ Token workflow mặc định: $PERM (cần read)"

echo
echo "✅ Xong:"
echo "   - approvers + CODEOWNERS đã lên $BASE"
echo "   - label law-change-approved"
echo "   - token workflow mặc định chỉ đọc"
echo "   - $BASE: bắt buộc PR, bắt buộc 4 check xanh (trusted-gate, php-quality, mutation-test, js-quality), cấm force-push, áp dụng cả admin"
echo
echo "Khuyến nghị mạnh: bật ký commit (requireSignedApproval) — HUONG-DAN.md mục A7."
