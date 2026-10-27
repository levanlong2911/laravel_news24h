# Kiến trúc hệ thống (Laravel + JavaScript)

> Agent đọc file này ở bước 1 và 2. Viết ngắn, đúng sự thật. Cập nhật mỗi khi một ADR được chấp nhận.
> TODO: các mục có "TODO" cần điền theo project thật. Có thể nhờ Claude soạn nháp:
> "Đọc codebase (composer.json, package.json, routes/, app/, resources/js/, database/migrations/) và điền docs/architecture.md theo các mục sẵn có.
> Chỉ ghi điều kiểm chứng được, kèm đường dẫn. Chỗ không chắc thì để TODO và hỏi tôi."

## 1. Tổng quan
- Hệ thống làm gì, cho ai: TODO
- Phiên bản: PHP TODO, Laravel TODO, Node TODO
- Kiểu ứng dụng: TODO (Blade thuần / Blade + Alpine / Inertia + Vue / Inertia + React / API + SPA riêng)
- CSDL: TODO (MySQL / PostgreSQL), cache/queue: TODO (Redis / database)

## 2. Cấu trúc thư mục
```
app/
  Actions/<Domain>/        CreateOrder.php ... (1 class = 1 nghiệp vụ, public function handle())
  Http/Controllers/        controller mỏng
  Http/Requests/           FormRequest: rules() + authorize()
  Http/Resources/          API Resource
  Models/                  Eloquent model
  Policies/                phân quyền
  Services/<Vendor>/       client API ngoài (Http facade)
  Jobs/  Events/  Listeners/  Notifications/
  Exceptions/
database/migrations/  database/factories/  database/seeders/
resources/js/
  api/                     MỌI lời gọi backend nằm ở đây
  components/  pages/      UI
resources/views/           Blade
routes/web.php  routes/api.php
tests/Feature/  tests/Unit/  tests/Arch/
```
TODO: sửa cho khớp cấu trúc thật. Nếu project dùng `app/Services` thay cho `app/Actions` thì ghi rõ và sửa `tests/Arch/ArchitectureTest.php` cho khớp.

## 3. Quy tắc phụ thuộc
```
Controller → FormRequest, Action, Resource, Policy
Action     → Model, Service, Event, Job, Action khác
Job/Listener/Command → Action
Model      → (chỉ Model khác, Cast, Enum)
Service    → Http facade, config
```
Chiều ngược lại bị cấm. Xem các rule [ARCH] ở AGENTS.md mục 4.

## 4. Luồng request điển hình
1. Route → middleware (auth, throttle)
2. Controller nhận `StoreXRequest` (đã validate và authorize)
3. Controller gọi `app(CreateX::class)->handle($request->validated(), $request->user())`
4. Action mở transaction nếu cần, thao tác Model, phát Event, dispatch Job
5. Controller trả `XResource`, redirect hoặc view

## 5. Dữ liệu
- Bảng chính và quan hệ: TODO
- Quy ước migration: file mới cho mọi thay đổi, có `down()`
- Transaction: mở trong Action, không mở trong Controller hay Model
- Soft delete: TODO (bảng nào dùng)

## 6. Tích hợp ngoài
| Hệ thống | Dùng để | Class client | Config |
|---|---|---|---|
| TODO | | `App\Services\...` | `config/services.php` |

## 7. Xác thực & phân quyền
- Đăng nhập: TODO (Breeze / Jetstream / Fortify / Sanctum / Passport)
- Phân quyền: Policy cho từng Model. TODO: vai trò (role) nếu có (spatie/laravel-permission?)

## 8. Frontend
- Build: Vite (`vite.config.js`)
- Framework: TODO
- Gọi backend: `resources/js/api/*.js`, dùng TODO (axios/fetch), xử lý lỗi 422 (validation) và 401/403 tập trung

## 9. Pattern chuẩn (copy theo)
- Feature mẫu hoàn chỉnh: TODO (vd: `app/Actions/Orders/CreateOrder.php` + `OrderController@store` + `StoreOrderRequest` + `tests/Feature/Orders/CreateOrderTest.php`)

## 10. ADR đã chấp nhận
| # | Quyết định | File |
|---|---|---|
