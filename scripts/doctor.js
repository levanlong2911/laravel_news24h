#!/usr/bin/env node
// Kiểm tra môi trường trước khi dùng bộ kit. BẠN chạy lệnh này (chỉ in tên DB, không in mật khẩu).
//   node scripts/doctor.js
//
// Kiểm tra: PHP/Laravel/package tương thích, DB test có TÁCH khỏi DB dev không (RefreshDatabase xóa dữ liệu!),
// queue driver, cấu hình cổng, Git/GitHub.
const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const ROOT = path.resolve(__dirname, "..");
let bad = 0, warn = 0;
const ok = (m) => console.log(`  ✅ ${m}`);
const w = (m) => (warn++, console.log(`  ⚠  ${m}`));
const x = (m) => (bad++, console.log(`  ⛔ ${m}`));
const run = (cmd, args) => {
  try {
    return execFileSync(cmd, args, { cwd: ROOT, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
  } catch {
    return null;
  }
};
const readJson = (f) => {
  try {
    return JSON.parse(fs.readFileSync(path.join(ROOT, f), "utf8"));
  } catch {
    return null;
  }
};
const readText = (f) => {
  try {
    return fs.readFileSync(path.join(ROOT, f), "utf8");
  } catch {
    return null;
  }
};
// Đọc DUY NHẤT các khóa được hỏi từ file .env (không đọc/in giá trị khác).
const envKeys = (file, keys) => {
  const t = readText(file);
  if (t === null) return null;
  const out = {};
  for (const line of t.split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && keys.includes(m[1])) out[m[1]] = m[2].replace(/^["']|["']$/g, "");
  }
  return out;
};
const lockVersion = (lock, name) => {
  if (!lock) return null;
  const p = [...(lock.packages || []), ...(lock["packages-dev"] || [])].find((p) => p.name === name);
  return p ? p.version.replace(/^v/, "") : null;
};
const major = (v) => (v ? parseInt(String(v).split(".")[0], 10) : null);

// ------------------------------------------------------------------ 1. Phiên bản
console.log("\n1) Phiên bản");
const phpV = run("php", ["-r", "echo PHP_VERSION;"]);
if (!phpV) x("Không chạy được `php`. Thêm thư mục php của XAMPP (vd C:\\xampp\\php) vào PATH.");
else {
  ok(`PHP ${phpV}`);
  const [M, m] = phpV.split(".").map(Number);
  if (M === 8 && m < 2) w("PHP 8.1 đã hết hỗ trợ bảo mật (từ 31/12/2025). Bộ kit chạy được, nhưng nên lên kế hoạch nâng 8.2+ (khi đó dùng được Pest 3, Larastan 3).");
}
const ciYml = readText(".github/workflows/ci.yml") || "";
const ciPhp = (ciYml.match(/PHP_VERSION:\s*"?([\d.]+)"?/) || [])[1];
if (phpV && ciPhp) {
  const local = phpV.split(".").slice(0, 2).join(".");
  local === ciPhp ? ok(`CI dùng PHP ${ciPhp}, khớp máy local`) : w(`CI dùng PHP ${ciPhp} nhưng máy local là ${local}. Sửa PHP_VERSION trong .github/workflows/ci.yml (file luật).`);
}
const composerJson = readJson("composer.json");
const phpConstraint = composerJson && composerJson.require && composerJson.require.php;
if (phpConstraint) ok(`composer.json yêu cầu PHP ${phpConstraint}`);
const node = run("node", ["--version"]);
node ? ok(`Node ${node}`) : x("Không chạy được `node`.");

const lock = readJson("composer.lock");
const laravel = lockVersion(lock, "laravel/framework");
if (!lock) w("Chưa có composer.lock (chạy composer install).");
else if (!laravel) w("Không thấy laravel/framework trong composer.lock.");
else {
  ok(`Laravel ${laravel}`);
  if (major(laravel) < 10) x(`Laravel ${laravel}: Pest 2 (có arch test) cần Laravel 10+. Với Laravel 9, tests/Arch sẽ không chạy — báo lại để dùng phương án Deptrac.`);
}
const pest = lockVersion(lock, "pestphp/pest");
const larastan = lockVersion(lock, "larastan/larastan") || lockVersion(lock, "nunomaduro/larastan");
const infection = lockVersion(lock, "infection/infection");
pest ? (major(pest) >= 2 ? ok(`Pest ${pest}`) : x(`Pest ${pest}: cần Pest 2+ để có arch().`)) : w("Chưa cài Pest. Xem HUONG-DAN A3.");
larastan ? ok(`Larastan ${larastan}`) : w("Chưa cài Larastan. Xem HUONG-DAN A3.");
infection ? ok(`Infection ${infection}`) : w("Chưa cài Infection (mutation test). Xem HUONG-DAN A3.");
if (lockVersion(lock, "nunomaduro/larastan") && readText("phpstan.neon.dist")?.includes("vendor/larastan/larastan")) {
  x("Bạn cài gói cũ nunomaduro/larastan nhưng phpstan.neon.dist trỏ vendor/larastan/larastan. Cài larastan/larastan hoặc sửa đường dẫn include.");
}
for (const [bin, pkg] of [["vendor/bin/pint", "laravel/pint"], ["vendor/bin/pest", "pestphp/pest"], ["vendor/bin/phpstan", "larastan/larastan"]]) {
  if (lock && !fs.existsSync(path.join(ROOT, bin))) w(`Chưa có ${bin} (${pkg}) — CI job php-quality sẽ fail. Chạy composer install / composer require --dev ${pkg}.`);
}
const pkgJson = readJson("package.json");
if (pkgJson && !(pkgJson.scripts && pkgJson.scripts.build)) w('package.json không có script "build" — CI job js-quality sẽ fail.');
const pcov = run("php", ["-r", "echo extension_loaded('pcov') ? 'pcov' : (extension_loaded('xdebug') ? 'xdebug' : '');"]);
pcov ? ok(`Coverage driver: ${pcov}`) : w("Không có PCOV/Xdebug: mutation test chỉ chạy được trên CI.");

// ------------------------------------------------------------------ 2. Database test
console.log("\n2) Database test (QUAN TRỌNG — RefreshDatabase xóa sạch dữ liệu DB nó dùng)");
const dev = envKeys(".env", ["DB_CONNECTION", "DB_DATABASE", "DB_TEST_DATABASE", "QUEUE_CONNECTION"]) || {};
const envTesting = envKeys(".env.testing", ["DB_CONNECTION", "DB_DATABASE", "DB_TEST_DATABASE"]);
const phpunit = readText("phpunit.xml") || readText("phpunit.xml.dist") || "";
const pu = (name) => {
  const m = phpunit.match(new RegExp(`<(?:env|server)\\s+name="${name}"\\s+value="([^"]*)"`));
  return m ? m[1] : null;
};
const protectedDbs = ((readJson(".github/workflow-gate.json") || {}).protectedDatabases) || [];
const testAllow = ((readJson(".github/workflow-gate.json") || {}).testDatabases) || [];
const testUser = pu("DB_USERNAME");
const testConn = pu("DB_CONNECTION") || (envTesting && envTesting.DB_CONNECTION) || dev.DB_CONNECTION;
const testDbKey = testConn === "testing" ? "DB_TEST_DATABASE" : "DB_DATABASE";
const testDb = pu(testDbKey) || (envTesting && envTesting[testDbKey]) || dev[testDbKey];
console.log(`     DB dev  : ${dev.DB_CONNECTION || "?"} / ${dev.DB_DATABASE || "?"}`);
console.log(`     DB test : ${testConn || "?"} / ${testDb || "?"}   (${testDbKey}, lấy từ ${pu(testDbKey) ? "phpunit.xml" : envTesting && envTesting[testDbKey] ? ".env.testing" : ".env — KHÔNG có cấu hình riêng cho test!"})`);
if (testConn === "sqlite" && (testDb === ":memory:" || /\.sqlite$/.test(testDb || ""))) {
  w("Test local dùng SQLite, còn CI dùng MariaDB 10.4. Nên tạo DB riêng trên XAMPP (vd app_testing) để local giống CI.");
} else if (testDb && testAllow.length && !testAllow.includes(testDb)) {
  x(`DB test "${testDb}" không nằm trong testDatabases (${testAllow.join(", ")}) của workflow-gate.json. Dùng đúng DB test, hoặc thêm tên vào allowlist (file luật, qua PR có label).`);
} else if (testDb && protectedDbs.includes(testDb)) {
  x(`DB test "${testDb}" nằm trong protectedDatabases (${protectedDbs.join(", ")}). Test với RefreshDatabase sẽ XÓA SẠCH DB này. Xem HUONG-DAN A3.`);
} else if (!testDb || testDb === dev.DB_DATABASE) {
  x(
    `Test đang dùng CHÍNH DB dev "${dev.DB_DATABASE}". Chạy php artisan test với RefreshDatabase sẽ XÓA SẠCH dữ liệu.\n` +
      `       Sửa: tạo DB "app_testing" trong phpMyAdmin, rồi thêm vào phpunit.xml (trong <php>):\n` +
      `         <env name="DB_CONNECTION" value="mysql"/>\n` +
      `         <env name="DB_DATABASE" value="app_testing"/>\n` +
      `       Đừng chạy test, đừng cho agent chạy test cho đến khi sửa xong.`
  );
} else ok(`DB test "${testDb}" tách khỏi DB dev "${dev.DB_DATABASE}".`);
if (testConn === "mysql" && testDb && testDb !== dev.DB_DATABASE) {
  !testUser || testUser === "root"
    ? w(`Test đang kết nối bằng ${testUser ? "root" : "user của .env"}. Nên dùng user MySQL riêng chỉ có quyền trên "${testDb}" (HUONG-DAN A3) — kể cả cấu hình sai tên DB cũng không đụng được DB dev.`)
    : ok(`Test dùng user MySQL riêng "${testUser}".`);
}
if (dev.DB_DATABASE === "test") w('DB dev đang tên "test" — dễ nhầm với DB test. Nên đổi tên (vd app_dev) khi có dịp.');
// ------------------------------------------------------------------ 2b. Chặn API thật trong test
console.log("\n2b) Test không được gọi API thật (AGENTS.md mục 0b)");
const guardFiles = ["tests/TestCase.php", "tests/Pest.php"].filter((f) => /preventStrayRequests\(/.test(readText(f) || ""));
guardFiles.length
  ? ok(`Http::preventStrayRequests() có trong ${guardFiles.join(", ")}`)
  : x("Chưa có Http::preventStrayRequests() trong tests/TestCase.php. Test có thể gọi API thật. Thêm theo HUONG-DAN mục A3b (CI cũng sẽ fail).");

// ------------------------------------------------------------------ 3. Bộ kit
console.log("\n3) Cấu hình bộ kit");
const gate = readJson(".github/workflow-gate.json");
if (!gate) x("Không đọc được .github/workflow-gate.json");
else {
  gate.approvers && gate.approvers.length ? ok(`approvers: ${gate.approvers.join(", ")}`) : x("approvers trống — chạy bash scripts/setup-github.sh");
  gate.requireSignedApproval ? ok("requireSignedApproval: bật") : w("requireSignedApproval: tắt — agent dùng danh tính git của bạn có thể tự duyệt. Xem HUONG-DAN A7.");
}
for (const f of ["tests/Arch/ArchitectureTest.php", "phpstan.neon.dist", "AGENTS.md", ".github/workflows/gate.yml"]) {
  fs.existsSync(path.join(ROOT, f)) ? ok(f) : x(`Thiếu ${f}`);
}
const neon = readText("phpstan.neon.dist") || "";
if (/^\s*#\s*-\s*phpstan-baseline\.neon/m.test(neon) && fs.existsSync(path.join(ROOT, "phpstan-baseline.neon"))) {
  w("Đã có phpstan-baseline.neon nhưng chưa bật trong phpstan.neon.dist (bỏ dấu # ở dòng include).");
}

// ------------------------------------------------------------------ 4. Git / GitHub
console.log("\n4) Git / GitHub");
const email = run("git", ["config", "user.email"]);
email ? ok(`git user.email: ${email}`) : x("Chưa đặt git config user.email");
const remote = run("git", ["remote", "get-url", "origin"]);
remote ? ok(`origin: ${remote}`) : w("Chưa có remote origin.");
const base = (gate && gate.baseBranch) || "main";
run("git", ["rev-parse", "--verify", "--quiet", `origin/${base}`])
  ? ok(`origin/${base} tồn tại`)
  : w(`Không thấy origin/${base}. Đổi "baseBranch" trong workflow-gate.json nếu nhánh chính tên khác (vd master).`);
if (run("git", ["cat-file", "-e", `origin/${base}:.github/workflows/gate.yml`]) === null) {
  w(`gate.yml chưa có trên origin/${base}: trusted-gate chỉ chạy sau khi bộ kit được merge vào ${base}.`);
} else ok(`gate.yml đã có trên origin/${base}`);
const gh = run("gh", ["auth", "status"]);
gh !== null ? ok("GitHub CLI đã đăng nhập") : w("GitHub CLI chưa cài/đăng nhập (cần cho setup-github.sh).");

console.log(`\n${bad ? "⛔" : warn ? "⚠ " : "✅"} ${bad} lỗi, ${warn} cảnh báo.${bad ? " Sửa lỗi ⛔ trước khi dùng bộ kit." : ""}\n`);
process.exit(bad ? 1 : 0);
