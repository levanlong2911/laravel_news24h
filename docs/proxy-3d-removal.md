# Gỡ Proxy 3D khỏi luồng yacht

Thực hiện ngày 2026-10-26 theo yêu cầu gỡ chức năng. Luồng Model sheet còn lại:
thiết kế → QA prompt đạt → render master → duyệt ảnh. Không tự tạo công đoạn thay thế.

## Code và hợp đồng

- Gỡ route/controller, các service YachtProxy, compiler/validator/mapping/resolver,
  màn hình và JavaScript dựng hình, worker Node, command phát hành/audit chuyên dụng.
- Gỡ lời gọi dựng Proxy sau duyệt Model sheet và điều kiện hình học Proxy chặn render.
- Gỡ enum điều phối Proxy. Stage lịch sử lưu bằng chuỗi, không có Eloquent enum cast;
  dữ liệu cũ không bị xóa hoặc chuyển đổi.
- Gỡ hợp đồng ProxyGeometry và yêu cầu output tương ứng trong cấu hình thiết kế mới.
- Gỡ Phase 3P và yêu cầu đầu vào clay/depth/mask từ tài liệu đang dùng cho lượt mới.
  Mask suy từ ảnh ở các công đoạn khác không đồng nghĩa với artifact của bộ dựng 3D.
- CompiledShot 6.3.1 chỉ nhận generate/edit_from_reference/none; importer hỗ trợ phiên bản
  riêng cho schema để không ghi đè CompiledShot 6.3.0 đã phát hành.
- Giữ ImageProxyService và route image.proxy: đây là chức năng tải ảnh/CORS độc lập.
- Giữ dữ liệu thiết kế có ý nghĩa riêng: kích thước, đường bao, boong, landmark, build state.
- Giữ `video.superyacht.build_state_text` (bộ kiểm thiết kế dùng để đối chiếu bộ phận/bề mặt của
  build state) và giữ `contract_version` của `spec.design` là `3`: output của hợp đồng không đổi,
  nâng số làm mọi bản thiết kế đã duyệt thành không hiện hành (panel ẩn nội dung và ảnh model sheet).

## DB đã chuyển đổi (máy DESKTOP-DJJC42L)

Các mục dưới đây là kết quả chạy trên DB của máy DESKTOP-DJJC42L. DB của máy admin
(`DB_DATABASE=test`) chưa chuyển đổi tính đến 2026-10-26: Phase 3P vẫn hiện hành và các prompt
hiện hành còn nhắc proxy/clay/3P; cần chạy command ở mục "Triển khai trên máy khác".

- Phát hành revision mới cho prompt bị ảnh hưởng và liên kết schema; không sửa nội dung revision cũ.
  Phase 3 hiện là revision 11, không yêu cầu canonical.proxy_geometry.
- Phase 3P không còn hiện hành; bản published cũ giữ làm lịch sử.
- Sao lưu rồi gỡ chính xác 10 job BuildProxyJob/PrepareProxyJob đang chờ, ID 488–497.
  Không có job nào trong nhóm này được reserved tại thời điểm gỡ.
- Không sửa canonical, raw response, QA, master, crop, lượt duyệt hoặc chi phí đã lưu.
- Không xóa artifact hay stage Proxy lịch sử. Không xóa toàn bộ cache/queue.

Bằng chứng và bản sao lưu tại `storage/app/retire-proxy-*.json`, gồm bản ghi trước thay đổi,
prompt/link cũ, payload job và kết quả kiểm chứng. Không chạy lại script áp dụng ban đầu:
đó là bản triển khai cho danh sách revision đã kiểm tra tại thời điểm này.

## Triển khai trên máy khác

Git không mang theo prompt trong DB. Sau khi lấy code, dừng worker cũ đang xử lý job dựng hình
và chạy command được quản lý trong repository:

```shell
php artisan video:reference-only-release
php artisan video:reference-only-release --apply --expected-plan=sha256:<hash-tu-ban-xem-truoc>
php artisan video:reference-only-release
```

Command mặc định chỉ xem trước. Khi ghi, nó kiểm lại đúng plan hash, yêu cầu không có job đã
reserved, sao lưu prompt/link/payload vào disk local trước khi ghi và áp dụng trong một
transaction. Prompt tùy chỉnh còn phụ thuộc không nhận dạng được sẽ dừng để đối chiếu,
không tự xóa. Lần chạy tiếp theo không tạo revision trùng. Revision cũ, schema cũ và dữ liệu
project đã duyệt được giữ nguyên. Máy DESKTOP-DJJC42L đã được chuyển đổi: command trả về không
có phase cần thay đổi và không có job dựng hình chờ xử lý.

Sau triển khai: clear config/route cache và khởi động lại worker dài hạn bằng quy trình vận
hành hiện có. Không chạy lại worker bằng code cũ sau khi công đoạn đã ngừng hoạt động.

## Kiểm chứng

- 108 test, 824 assertions đạt: ModelSheetWithoutProxyTest, SpecImportTest,
  SpecContractTest, ModelSheetQaRegressionTest, ModelSheetQaTransportTest.
- Test render dùng HTTP giả lập: dữ liệu Proxy cũ không chặn master, gửi trùng dùng lại ảnh,
  QA chưa đạt/prompt sai hash vẫn bị chặn, duyệt qua controller không tạo job Proxy.
- Build Vite và biên dịch Blade thành công; kiểm tra route chỉ còn image.proxy độc lập.
- 70 stage lịch sử được đối chiếu input/output/raw hash và status: không thay đổi.
- Đối chiếu 9 project yacht trước/sau: trạng thái nguồn đã duyệt không thay đổi.
  Ba master đã duyệt của Talune, Majesty, Ventara qua kiểm tra file/hash sâu thành công.
- Prompt đang hiện hành không còn proxy_geometry/proxy3d/proxy_input/edit_from_clay/3P.
- Không gọi AI hoặc render ảnh trả phí trong triển khai.

Command triển khai được kiểm riêng thêm các ca: preview không ghi, từ chối plan cũ,
giữ lịch sử và job không liên quan, backup thất bại thì không ghi, job reserved thì dừng,
và chạy lại không phát sinh revision mới.

Bộ test toàn repository chưa đạt: có nhiều failure/error và dừng bởi giới hạn thực thi
60 giây; lần chạy với CLI max_execution_time=0 cũng bị giới hạn được đặt lại trong quá trình
chạy. Log tại `storage/app/retire-proxy-tests.txt`. Chưa kết luận các failure đó đều có từ trước;
kết quả 108 test ở trên là phạm vi đã kiểm chứng riêng, không phải tuyên bố toàn repo xanh.
