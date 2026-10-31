# Agrovia — Vụ Xanh · QA demo NoSQL

Checkpoint source QA được đối chiếu backup bước1 ngày31/10/2026; không phải source Main hoặc export lịch sử181c918. Đọc [deployment/qa/RESTORE.md](deployment/qa/RESTORE.md) và [source manifest](deployment/qa/source-manifest.json) trước khi phục hồi.

| Thư mục | Nội dung |
|---|---|
| admin/ | Ứng dụng Next.js chung: Admin V2, Admin cũ và Shop khách hàng |
| api-laravel/ | Laravel API và nghiệp vụ MongoDB QA hiện hành |
| scripts/ | Launcher/router QA nguyên bản, khóa root và origin QA |
| deployment/qa/ | Config example không secret, ánh xạ hash và hướng dẫn phục hồi |
| outbox-worker/ | Source kế thừa lịch sử, bằng Main; không tự chạy worker |
| database/ | Helper demo tổng hợp kế thừa lịch sử; không phải DB QA hoặc lệnh seed cần chạy |

Môi trường QA: Shop/Admin http://localhost:3320, API http://localhost:8020, MongoDB localhost27028, replica set nosql186_rs. Main8010/3310 và database Main độc lập. Admin route /admin/v2, Shop /shop, đăng nhập nhân sự /login.

Git không chứa .env/private/session, BSON/dataset, media/ảnh/font, runtime/vendor/node_modules hoặc build/cache. Các assets từng có ở ancestor được bỏ khỏi tree checkpoint, lịch sử Git không bị viết lại. Không tải ảnh LICENSE_PENDING lên Git/public. Clone repo này đơn lẻ không phải một bản demo offline đầy đủ: cần backup DB/media và dependency riêng. Main và QA không được chép đè nhau.

Ảnh sản phẩm và dữ liệu đi từ MongoDB qua Laravel API đến Next.js; không có mapping80SKU ở frontend. AI/SMTP/worker/scheduler/scanner không bật. Build source không chứng minh full browser/business parity hoặc production readiness. Xem báo cáo checkpoint bên ngoài Git để biết test thực chạy và các giới hạn còn lại.
