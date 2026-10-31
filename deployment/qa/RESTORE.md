# Phục hồi source Agrovia QA demo

Đây là checkpoint mã nguồn, không phải bản Full Offline hoặc database backup. Không chạy hướng dẫn lên QA/Main đang hoạt động. Bước này chỉ kiểm build/source, chưa deploy ứng dụng từ checkout mới.

## Ánh xạ và nguồn

`source-manifest.json` đối chiếu 299 file QA với backup bước 1. Git giữ nguyên từng byte 297 source/config/launcher; hai favicon binary nằm ngoài Git, còn trong backup/source. `admin/` chính là `web-quan-ly-ban-hang/` của QA, chứa chung Admin và Shop. `scripts/` là ba helper QA nguyên bản. `database/` và `outbox-worker/` được kế thừa export lịch sử và đã so hash với Main, không phải dataset QA và không được tự bật/seed.

## Dependency ngoài Git

QA dùng runtime PHP/Node và vendor/node_modules từ bản Main riêng. Phiên bản ghi nhận: PHP8.3.30, extmongodb2.5.3, Node24.16.0, MongoDB8.0.34, Next16.3.5, React19.2.8, Laravel13.33.0, LaravelMongo5.11.0. Giữ Main/runtime và các dependency hiện hành để phục hồi offline; cài mới theo lockfile cần bộ package tương ứng. Không mang state hoặc credential Main sang Git.

Nếu tái cài PHP dependency, dùng Composer install theo composer.lock với --no-scripts trước khi cấu hình môi trường. Không chạy migrate/seed/demo helpers hoặc composer create-project. Manifest hiện còn script test:c00 tham chiếu tools không thuộc bản phân phối; không coi đó là test đã chạy hoặc dependency khởi động.

## Khôi phục có kiểm soát trên môi trường phục hồi riêng

1. Checkout đúng SHA/tag từ biên bản Git và kiểm từng hash trong source-manifest.json. Chọn đích mới, không chép lên folder đang dùng.
2. Root QA bị khóa là C:/Hoctap/NoSQL/QA/agrovia-phase3-20261030. Khi phục hồi đúng layout trên máy riêng, chép admin/ thành web-quan-ly-ban-hang/, api-laravel/ và scripts/ giữ tên. Không sửa Isolation hoặc launcher để né guard. Tại máy hiện hành root này đang có dữ liệu: KHÔNG thực hiện thao tác chép đè.
3. Chuẩn bị vendor/node_modules theo lockfile và PHP/Node từ Main đã được giữ riêng. Nếu dùng vendor optimized classmap chung, scripts/api-router.php có App autoload QA ưu tiên; không gọi thẳng public/index.php bằng vendor trỏ Main. Tạo thư mục bootstrap/cache, storage/framework/cache/data, sessions, views, storage/logs writable.
4. Tạo .env private từ deployment/qa/api.env.example và đặt APP_KEY/credential riêng qua kênh an toàn. MONGODB_URI cần database QA trong path, host localhost27028, authSource cùng DB, replicaSet=nosql186_rs, readPreference=primary, retryWrites=true; password tối thiểu32ký tự và username đúng allowlist. Không dùng root/restore credential cho app. Session path phải đúng root QA, cookie riêng nosql186_qa_api_session. Web dùng deployment/qa/web.env.example hoặc các biến launcher đặt.
5. Dataset/media lấy từ backup nội bộ bước1 riêng: C:/Hoctap/NoSQL/.agent/agrovia-demo-backup-20261031/backup. Đọc RESTORE_GUIDE.md, MEDIA_MANIFEST.json và checksums của bước1. Chỉ restore DB khi đã chứng minh đích rỗng và được cho phép; không --drop/reset/seed. Không đổi connection sang DB restore thử vì guard không chấp nhận tên đó.
6. Khôi phục backup/media theo đường dẫn QA; assets Web về web-quan-ly-ban-hang/public/, ảnh Laravel về storage/app/. Hai favicon có thể lấy từ backup/source đúng hash. Ảnh LICENSE_PENDING chỉ nội bộ; không publish/Git. Git không chứa font/ảnh nên clone đơn lẻ không tái hiện đầy đủ Shop.
7. MongoDB replica set riêng localhost27028 phải sẵn sàng, không reconfigure dịch vụ hiện có. Sau khi cấu hình và xác minh isolation, dùng scripts/qa.ps1 Build rồi Start/Check/Stop đúng ownership. Launcher khóa root, API8020/Web3320 loopback, phụ thuộc C:/Hoctap/NoSQL/Doan/sourcecode/runtime và state/php.ini của Main. Không chạy runme Setup của Main để dựng QA.

Không có runme.cmd QA độc lập: launcher chính xác là scripts/qa.ps1. Không tuyên bố Windows sạch hoặc deployment độc lập đã PASS. Không dùng package Git thiếu DB/media/private/dependency để thay thế backup bước1. AI/SMTP/worker/scanner/scheduler giữ tắt. Các SQL migration/schema và tên legacy nằm trong source lịch sử, không phải chỉ dẫn dùng PostgreSQL cho demo MongoDB.

Config api-laravel/.npmrc ???c b? sung t? QA live sau khi ??c n?i dung (ignore-scripts=true, audit=true); n?m ngo?i manifest299 c?a b??c1. run-api.ps1 c? ph? thu?c Laragon/port8000 v? b?t pdo_pgsql kh?ng ph?i launcher QA, c? ? kh?ng ??a v?o; d?ng scripts/qa.ps1. next-env.d.ts ???c Next t?o l?i khi build, kh?ng ph?i source th? c?ng b? thi?u.
