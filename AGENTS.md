# AGENTS.md — Luật chung cho Claude Code và Codex

> Đây là NGUỒN RULE DUY NHẤT. `CLAUDE.md` chỉ import file này.
> Mục 4–7 đã viết sẵn cho Laravel + JavaScript. Hãy rà lại cho khớp project thật.

## 0. Ngôn ngữ
Trả lời người dùng bằng tiếng Việt. Code, tên biến, commit message bằng tiếng Anh.

## 0b. Chỉ viết code — KHÔNG gọi API, KHÔNG chạy chương trình (BẮT BUỘC, ưu tiên cao nhất)
Người dùng tự chạy chương trình thủ công. Agent (Claude, Codex) chỉ được **đọc code, viết code, và chạy công cụ kiểm tra offline**. Claude Code được thêm quyền **đọc DB bằng tài khoản chỉ-đọc** (bên dưới).

**Được làm, không cần hỏi:**
- Đọc và sửa file trong project. Dùng `git status`, `diff`, `log`, `show`, `commit` (theo quyền ở `.claude/settings.json`).
- Chạy công cụ kiểm tra không gọi mạng: `pint`, `phpstan`, `pest`/`php artisan test`, `npm run build`/`lint`/`test`, các script `node scripts/*.js` của bộ kit.
  Test chạy với `Http::preventStrayRequests()`: mọi request HTTP thật đều làm test fail. Test phải dùng `Http::fake()`.
- `php artisan make:*`, `route:list`, `about` (chỉ sinh file hoặc đọc thông tin).
- (Claude Code) Đọc DB bằng tài khoản MariaDB chỉ-đọc `claude_ro` (chỉ có quyền `SELECT`, DB tự từ chối mọi lệnh ghi): `D:/xampp/mysql/bin/mysql.exe -u claude_ro -h 127.0.0.1 --default-character-set=utf8mb4 <db> -e "SELECT …"`.
  Chỉ dùng đúng lệnh này: không thêm `-u`/`--user` khác, không dùng `root`, không đọc `.env` để lấy mật khẩu. Mỗi truy vấn có `LIMIT`. Không SELECT mật khẩu, token, khóa API hay dữ liệu cá nhân (vd `users.password`, `remember_token`, `personal_access_tokens`, email, số điện thoại). Không xuất dữ liệu ra file. Khi dùng kết quả làm bằng chứng, ghi lại câu truy vấn.

**KHÔNG được làm khi người dùng chưa đồng ý rõ ràng trong chat, cho đúng lần đó:**
- Gọi **bất kỳ API hay dịch vụ ngoài nào**: `curl`, `wget`, `Invoke-WebRequest`, `gh api`, SDK, gửi request tới Claude/OpenAI/dịch vụ của app, mở URL, tìm kiếm web, gọi MCP tool có kết nối mạng.
- **Chạy chương trình**: `php artisan serve`, `tinker`, `queue:*`, `schedule:*`, `migrate*` và `db:*` trên DB thật, mọi command artisan của app (`app:*` và các command tự viết), `php <file>.php`, `php -r`, `php -S`, `npm run dev`/`start`/`preview`, mở trình duyệt.
- Cài package (`composer require`, `npm install`), vì lệnh này gọi mạng và đổi dependency.
- Viết code mà khi **test** chạy sẽ gọi mạng thật (không `Http::fake()`, dùng Guzzle hoặc curl trực tiếp để né `preventStrayRequests`).

Cần chạy hoặc gọi để kiểm chứng: **dừng lại**, nói rõ lệnh nào, vì sao, sẽ gọi tới đâu, rồi chờ người dùng trả lời. Người dùng có thể tự chạy và gửi kết quả lại.
Code ứng dụng gọi API ngoài khi người dùng chạy thật là việc của người dùng; agent chỉ viết code đó (qua client trong `App\Services`, có cấu hình và timeout), **không chạy thử**.

## 1. Quy trình 6 bước (BẮT BUỘC)

| # | Bước | Ai làm | Gọi bằng | File đầu ra trong `docs/work/<slug>/` |
|---|------|--------|----------|----------------------------------------|
| 0 | (Luồng rút gọn, thay cho 1–3) | Claude | `/quick <mô tả>` | `00-lite.md` |
| 1 | Discovery | Claude | `/discover <mô tả task>` | `01-discovery.md` |
| 2a | Architecture (bản nháp) | Claude | `/architect <slug>` | `02-architecture.md` |
| 2b | Architecture (phản biện) | Codex | `$arch-critique <slug>` | `02-architecture-critique.md` |
| 2c | Architecture (chốt) | Claude | `/architect-revise <slug>` | cập nhật `02-architecture.md` |
| 3 | Approval | **Người dùng** | `node scripts/approve.js <slug>` (terminal) | header của `02-architecture.md`: `Status: APPROVED` + `Approved-hash` |
| 4 | Implementation | Claude | `/implement <slug>` | code + `04-implementation.md` |
| 5 | Review | Codex | `$code-review <slug>` | `05-review.md` |
| 5b | Sửa theo review | Claude | `/fix-review <slug>` | code + cập nhật `04-implementation.md` |
| 6 | Verification | Codex + CI | `$verify <slug>` + pipeline CI | `06-verification.md` + CI xanh |

`<slug>` là tên ngắn kebab-case của task, ví dụ `add-order-discount`. Bước 1 tự tạo thư mục `docs/work/<slug>/`.

### Luồng rút gọn cho thay đổi nhỏ
**Mọi thay đổi code đều phải có task, có Codex review và có verification.** Chỉ PR thuần docs hoặc test mới được miễn.

| | Quy trình đủ | Luồng rút gọn |
|---|---|---|
| Khi nào | Mặc định | Thay đổi nhỏ (điều kiện bên dưới) |
| Nhánh | `feat/<slug>`, `fix/<slug>` | `chore/<slug>`, `hotfix/<slug>` |
| Bắt đầu bằng | `/discover` | `/quick <mô tả>` (Claude) |
| Bước 1–3 (Discovery, Architecture, Approval) | Có | **Bỏ**. Thay bằng `00-lite.md` |
| Bước 4 (Claude code) | Có | Có |
| Bước 5 (Codex review), 6 (Codex verify + CI) | Bắt buộc | **Bắt buộc** |

Điều kiện luồng rút gọn (CI kiểm tra bằng máy, theo `riskPolicy` trong `.github/workflow-gate.json`):
- tối đa 3 file code và 80 dòng thay đổi (không tính test, docs);
- không đụng vùng rủi ro: migration, `routes/`, `config/`, Job, Console, Provider, Middleware, `Http/Kernel.php`, Policy, `bootstrap/`, `composer.*`, `package*.json`, `vite.config.js`, `.env.example`.

Vượt một trong các điều kiện trên thì CI chặn, và phải chuyển sang `feat/<slug>`. Nhánh có code mà không thuộc task nào cũng bị chặn.

### Luật cổng (gate) — agent nào cũng phải kiểm tra trước khi làm
- Mỗi bước chỉ chạy khi file đầu ra của bước trước đã tồn tại. Thiếu file thì DỪNG và báo người dùng cần chạy bước nào.
- **Chỉ người dùng được duyệt**, bằng lệnh `node scripts/approve.js <slug>`. Agent KHÔNG BAO GIỜ chạy lệnh này, không sửa các dòng `Status`, `Approved-*`, và không đề nghị "tự duyệt hộ".
- Bước 4, 5, 6 bắt đầu bằng `node scripts/check-approval.js <slug>`. Lệnh fail thì DỪNG. Task rút gọn (có `00-lite.md`) tự qua bước này.
- Lệnh kiểm tra so hash nội dung. Thiết kế bị sửa sau khi duyệt thì tự động mất hiệu lực, người dùng phải duyệt lại.
- Bước 5 và 6: Codex **không sửa code**. Codex chỉ ghi nhận xét vào file. Sửa code là việc của Claude ở bước 5b.
- Mỗi bước chỉ làm đúng phần việc của mình. Ví dụ: Discovery không thiết kế, Review không sửa code.
- Nhánh làm việc đặt tên `feat/<slug>` (hoặc `fix/<slug>`). CI dựa vào tên nhánh để biết PR thuộc task nào. Đặt tên nhánh khác không giúp bỏ qua duyệt (xem "Luồng rút gọn").
- **Phạm vi file**: mọi file code thay đổi trong PR phải khớp bảng mục 5 của `02-architecture.md`, trừ các file test, factory, seeder, lang và tài liệu. CI tự kiểm tra. Cần thêm file thì sửa thiết kế và duyệt lại.
- **Commit**: không bắt buộc commit trước khi phản biện, review hoặc verification local. Codex đọc cả code đã commit, staged, unstaged và file mới trong phạm vi task. Đầu bước 2b/5/6 chạy `node scripts/check-clean.js --capture docs/work/<slug>/.review-baseline.json`; cuối bước chạy `node scripts/check-clean.js --compare docs/work/<slug>/.review-baseline.json`. Nếu code đổi trong lúc kiểm tra thì dừng và kiểm tra lại; không reset/stash hoặc xóa thay đổi có sẵn. Claude chỉ commit khi người dùng yêu cầu. CI vẫn kiểm tra nội dung commit/PR; kết quả local không thay thế CI.
- **File của Codex** (`02-architecture-critique.md`, `05-review.md`, `06-verification.md`) commit riêng. Commit đó chỉ được chứa file trong `docs/work/`, CI tự kiểm tra.
- **Commit duyệt** do `approve.js` tạo, có trailer `Architecture-Approved: <slug> <hash>`. CI kiểm tra người tạo commit có tên trong `.github/workflow-gate.json` và commit không có dấu vết agent.
- **Review và Verification được CI đọc bằng máy**: `Verdict:` trong `05-review.md` và `06-verification.md`, cột Trạng thái của từng nhận xét, `Code-Tree:` trong `06-verification.md`. Code đổi sau khi verify thì CI chặn.
- **Chấp nhận rủi ro** cho nhận xét review chỉ do người dùng làm, bằng `node scripts/accept-risk.js <slug> R-xx --reason "…"`. Agent không chạy lệnh này. Không có commit đó thì `ACCEPTED` bị CI chặn.
- **Test đã có**: sửa hoặc xóa phải được khai báo trong phạm vi task (mục 5 của thiết kế, hoặc `00-lite.md`). Không được sửa test cũ để che lỗi.
- **Không bao giờ** gắn hay gỡ label trên GitHub, approve PR, sửa branch protection hay chạy `gh pr edit`, `gh label`, `gh api`. Label `law-change-approved` là chữ ký của người dùng cho việc đổi file luật.
- **Trung thực về giới hạn**: các cổng này để chặn sai sót và đường tắt. Agent không được tìm cách vượt qua chúng (đổi tên nhánh, chia nhỏ commit hoặc chia một thay đổi lớn thành nhiều task rút gọn để lọt ngưỡng, dùng danh tính git của người dùng, sửa validator). Thấy cổng chặn sai thì báo người dùng.

### Vai trò của từng agent
- **Claude Code**: người khảo sát (Discovery), kiến trúc sư soạn thiết kế (Architecture), kỹ sư triển khai (Implementation).
- **Codex**: kiến trúc sư phản biện (Architecture critique), reviewer độc lập (Review), người kiểm chứng (Verification).
  Codex phải đánh giá độc lập: không tin lời tóm tắt của Claude, phải tự đọc code và chạy lệnh.

## 2. Mức độ nghiêm trọng (dùng ở bước 2b, 5, 6)
- **BLOCKER**: sai kiến trúc, sai nghiệp vụ, lỗ hổng bảo mật, mất dữ liệu, test fail. Bắt buộc sửa.
- **MAJOR**: vi phạm rule mục 4–6, thiếu test cho logic quan trọng, xử lý lỗi sai. Phải sửa, trừ khi người dùng chấp nhận.
- **MINOR**: đặt tên, trùng lặp nhỏ, comment. Nên sửa.
- **QUESTION**: chưa rõ, cần người dùng trả lời.

Mỗi nhận xét phải có: mã (vd `R-03`), mức độ, `file:dòng`, vấn đề, rule bị vi phạm (nếu có), đề xuất sửa.

## 3. Nguyên tắc chung khi làm việc
- Đọc `docs/architecture.md` và code liên quan trước khi kết luận. Mọi khẳng định về code phải kèm đường dẫn file.
- Đi theo pattern đã có trong repo. Muốn tạo pattern mới hoặc đi ngược rule thì phải đề xuất ADR trong `docs/adr/`.
- Không đoán. Chỗ nào chưa chắc thì ghi vào mục "Câu hỏi mở".
- Không refactor ngoài phạm vi đã duyệt. Phát hiện vấn đề khác thì ghi vào mục "Ngoài phạm vi".

## 4. Kiến trúc của project (Laravel + JavaScript)
> Mặc định theo mô hình Laravel "Controller mỏng + Action". Chỉnh lại cho đúng project, đồng thời sửa `tests/Arch/ArchitectureTest.php` để máy kiểm tra cùng rule.

```
routes/*.php → Http/Controllers ──► Http/Requests (validate + authorize)
                    │
                    ▼
               Actions/ (1 việc nghiệp vụ = 1 class, method handle())
                    │            └─► Services/<Vendor>/ (API ngoài, qua Http facade)
                    ▼
               Models/ (Eloquent: quan hệ, scope, cast, accessor)
                    │
Jobs/, Listeners/, Console/Commands ──► gọi Actions (không chứa logic riêng)
Http/Resources/ → định dạng JSON trả về     Policies/ → phân quyền
resources/js/ → frontend (Vite); gọi backend qua một module API duy nhất
```

Rule (các dòng **[ARCH]** được kiểm tra tự động bằng Pest Arch trong CI):
- **[ARCH]** Controller không dùng `DB::` hay query builder. Controller chỉ làm 4 việc: nhận FormRequest, gọi Action, trả Resource, trả redirect/view.
- **[ARCH]** Controller có hậu tố `Controller`. FormRequest có hậu tố `Request` và kế thừa `FormRequest`.
- **[ARCH]** `App\Actions` và `App\Services` không phụ thuộc HTTP (`Request`, `request()`, `session()`, `auth()` helper). Dữ liệu được truyền vào qua tham số.
- **[ARCH]** `App\Models` kế thừa Eloquent `Model` và không phụ thuộc `App\Http`, `App\Actions`.
- **[ARCH]** Không dùng `env()` ngoài thư mục `config/`. Code đọc cấu hình bằng `config()`.
- **[ARCH]** Không để lại `dd`, `dump`, `ray`, `var_dump`, `die`, `exit` trong `app/`.
- Validation chỉ đặt trong FormRequest. Phân quyền dùng Policy/Gate (`$this->authorize`, `can` middleware), không tự viết if-check trong controller.
- Business logic nằm trong Action. Model chỉ chứa quan hệ, scope, cast, accessor/mutator. Không có logic nghiệp vụ trong Blade, route closure hay model event.
- Thao tác ghi nhiều bảng phải bọc `DB::transaction()` trong Action.
- Không truy vấn trong vòng lặp (N+1). Dùng `with()`, `load()`. Môi trường dev bật `Model::preventLazyLoading()`.
- Gọi API ngoài qua `Http` facade, đặt trong `App\Services\<Vendor>\...`, cấu hình ở `config/services.php`.
- **Project hiện CHƯA dùng queue/Job.** Không tự thêm Job, `ShouldQueue`, `dispatch()` hay đổi `QUEUE_CONNECTION`. Nếu một việc chậm thật sự cần chạy nền, nêu trong thiết kế (mục Phương án) để người dùng quyết định. Khi người dùng bật queue, xem `optional/queue/README.md` của bộ kit.
- Lời gọi Claude API hoặc AI khác: đi qua một client duy nhất trong `App\Services`, ghi usage (token, chi phí) mỗi lần gọi, có timeout và retry có giới hạn.
- Frontend: mọi lời gọi backend đi qua `resources/js/api/` (một chỗ duy nhất). Component không tự gọi `fetch`/`axios` rải rác. Không đưa secret vào JS. Biến môi trường frontend chỉ dùng `VITE_*`.

Chi tiết xem `docs/architecture.md`.

## 5. Quy ước code
- Stack: **PHP 8.2** (XAMPP), **MariaDB 10.4**, Laravel (phiên bản theo `composer.json`), Eloquent, **Pest 2** (Laravel 10 dùng PHPUnit 10; không dùng tính năng Pest 3 như `preset()`, `toHaveMethod()`), Larastan 2, Vite + JavaScript. Chưa dùng queue.
- Code phải chạy được trên PHP 8.2: không dùng tính năng 8.3+ như hằng class có khai báo kiểu, `#[\Override]`, `json_validate()`, truy cập hằng class động `Foo::{$name}`.
- SQL phải chạy trên MariaDB 10.4: không dùng tính năng chỉ có ở MySQL 8 (ví dụ `JSON_TABLE` hay một số window function). Cột JSON trên MariaDB là `LONGTEXT` kèm `CHECK`.
- Định dạng PHP: Laravel Pint (PSR-12 + preset Laravel). Không tự format kiểu khác.
- Kiểu dữ liệu: khai báo type cho tham số, kiểu trả về, property. Larastan phải pass ở level trong `phpstan.neon.dist`.
- Đặt tên:
  - Controller `OrderController` (số ít).
  - Action `CreateOrder`, `CancelOrder` (động từ + danh từ).
  - FormRequest `StoreOrderRequest`, `UpdateOrderRequest`.
  - Resource `OrderResource`. Job `SendOrderInvoice`. Event `OrderPlaced`. Listener `NotifyWarehouse`.
  - Bảng snake_case số nhiều. Cột snake_case. Route name `orders.store`. URL kebab-case.
  - JS: file component PascalCase, file tiện ích camelCase.
- Migration: luôn tạo file mới, luôn có `down()`. Khóa ngoại dùng `foreignId()->constrained()`. Thêm index cho cột dùng để lọc.
- Tiền tệ lưu dạng số nguyên (đơn vị nhỏ nhất), không dùng float.
- Lỗi: ném exception có tên rõ nghĩa (`App\Exceptions\...`), không nuốt lỗi, không trả `null` thay cho lỗi. Thông báo cho người dùng dùng `lang/`.
- Log: dùng `Log::` kèm context mảng. Không log mật khẩu, token, dữ liệu cá nhân.
- Test (Pest):
  - Mỗi endpoint có Feature test (`tests/Feature`): thành công, lỗi validation, không có quyền.
  - Mỗi Action có Unit test (`tests/Unit`).
  - Dùng factory và `RefreshDatabase`. **DB test phải là DB riêng** (ví dụ `app_testing`), khai báo trong `phpunit.xml`. Không bao giờ chạy test trên DB dev, vì `RefreshDatabase` xóa sạch dữ liệu.
  - API ngoài luôn `Http::fake()`. Feature test dùng `Queue::fake()`/`Mail::fake()`.
  - Test phải kiểm tra kết quả (DB, response, side-effect), không chỉ "không lỗi".

## 6. Lệnh
Dạng `php vendor/bin/...` chạy được cả trên Windows (PowerShell, CMD, Git Bash) lẫn Linux. CI chạy đúng các lệnh này.
- Kiểm tra môi trường (người dùng chạy): `node scripts/doctor.js`
- Cài đặt: `composer install` và `npm ci`
- Format (kiểm tra): `php vendor/bin/pint --test` (tự sửa: `php vendor/bin/pint`)
- Phân tích tĩnh: `php vendor/bin/phpstan analyse --memory-limit=1G`
- Test kiến trúc: `php vendor/bin/pest tests/Arch`
- Test: `php artisan test --exclude-group=integration`
- Mutation test (dòng thay đổi): `php vendor/bin/infection --git-diff-lines --git-diff-base=origin/main --threads=max --min-msi=60 --min-covered-msi=75`
- Frontend: `npm run build` (và `npm run lint`, `npm test` nếu project có)
- Cổng quy trình (chạy thử): `node scripts/ci-gate.js --local`
- Selftest các cổng (người dùng chạy khi đổi file luật): `bash scripts/selftest.sh`
- Code-Tree cho bước 6: `node scripts/code-tree.js`

## 7. Cấm tuyệt đối
- Đọc, sửa hoặc commit `.env*`, secret, key. Cần biến môi trường mới thì thêm vào `.env.example` kèm giá trị giả.
- Sửa migration đã có. Luôn tạo migration mới.
- Chạy `migrate:fresh`, `migrate:reset`, `db:wipe`, `migrate --force` hoặc bất kỳ lệnh nào trên DB không phải DB test. Chạy `php artisan test` khi chưa chắc DB test tách khỏi DB dev.
- Thêm package Composer/npm khi thiết kế đã duyệt chưa ghi rõ package đó.
- Sửa các file "luật" (danh sách `lawFiles` trong `.github/workflow-gate.json`): `tests/Arch/**`, `tests/Pest.php`, `phpstan*.neon*`, `infection.json5`, `pint.json`, `phpunit.xml`, `.github/**`, `scripts/**`, `.claude/**`, `.agents/**`, `.codex/**`, `AGENTS.md`, `CLAUDE.md`. Cần đổi thì đề xuất cho người dùng.
- Thêm `@phpstan-ignore`, `->skip()`, `markTestSkipped`, `// eslint-disable` hoặc hạ level để cho qua.
- `git push`, `git reset --hard`, `git rebase`, xóa nhánh khi chưa được yêu cầu rõ ràng.

## 8. Bài học (cập nhật mỗi khi agent làm sai)
<!-- Mỗi dòng: ngày — lỗi đã gặp — rule đúng. -->
- (trống)
