# Agrovia — Vụ Xanh · NoSQL

Mã nguồn hiện hành của hệ thống quản lý chuỗi cửa hàng vật tư nông nghiệp và Shop khách hàng. Cơ sở dữ liệu nghiệp vụ chính: MongoDB. Đây là source repository để theo dõi/review; không phải bộ Full Offline một-click.

## Cấu trúc

| Thư mục | Nội dung |
| --- | --- |
| `admin/` | Ứng dụng Next.js chung, chứa cả Admin V2, Admin cũ và Shop khách hàng; tên thư mục không có nghĩa chỉ có Admin |
| `api-laravel/` | Laravel API dùng chung, nghiệp vụ/quyền/transaction MongoDB |
| `outbox-worker/` | Source worker MongoDB; không tự bật khi đọc hoặc clone repo |
| `database/` | Helper khởi tạo và dữ liệu mẫu tổng hợp; không có database dump hoặc dữ liệu hiện hành |

Admin và Shop hiện nằm trong một ứng dụng, không tách thành hai server hoặc nhân bản source sang shopweb.

## Bản đồ mã nguồn

| Phần | Đường dẫn |
| --- | --- |
| Admin V2 shell/menu/theme | `admin/src/components/admin/v2/` |
| Admin V2 route | `admin/src/app/admin/v2/page.tsx` |
| Các màn và biểu mẫu Admin | `admin/src/app/admin/`, `admin/src/components/admin/` |
| Đăng nhập nhân sự | `admin/src/app/login/` |
| Shop khách hàng | `admin/src/app/shop/`, `admin/src/components/shop/` |
| Gọi API / tiền / số lượng | `admin/src/lib/` |
| Assets tĩnh | `admin/public/` |
| API routes | `api-laravel/routes/` |
| Controller/service/repository | `api-laravel/app/` |
| Schema/index/validator | Tìm trong `api-laravel/app/Support/` theo manifest NoSQL |

## Địa chỉ của môi trường phát triển hiện hành

- Web Next.js: `http://nosql.localhost:3310`.
- Admin V2: `/admin/v2`; Shop: `/shop`; đăng nhập nhân sự: `/login`.
- Laravel API: `http://nosql.localhost:8010`.
- MongoDB riêng: loopback port `27028`, replica set `nosql186_rs`.

Cấu hình Web có kiểm fail-closed đúng các URL trên, xem `admin/next.config.ts` và `.env.example`. Không sử dụng PostgreSQL hoặc MongoDB27017 của dự án khác. Repo không chứa cấu hình truy cập thật.

## Dependency và chạy source

Đọc `admin/package.json` / `package-lock.json`, `api-laravel/composer.json` / `composer.lock`, `outbox-worker/package.json` để biết dependency chính xác. Bản này không mang PHP/Node/MongoDB, vendor, node_modules hay bản build. `npm ci` / Composer cần môi trường phát triển và dependency thích hợp; đây không phải cam kết offline.

Web dùng Next.js16.3.5, React19.2.8. Laravel yêu cầu PHP8.3 trở lên và MongoDB extension tương thích. Không chạy khởi tạo demo lên database có sẵn. API cần cấu hình riêng và schema hợp lệ trước khi dùng UI.

Helper trong `database/` được lưu làm source của quy trình demo thuộc bản phân phối gốc. Chúng cần launcher/runtime/state của bản Full Offline, không phải lệnh seed độc lập dành cho repo mới này. Không tự chạy migrate/reset hoặc bật worker/scanner/scheduler.

## Trạng thái source tại lần đăng đầu

Admin V2 có8 nhóm/40 mục/35screen key; một số mục dùng chung component. Bản hiện hành gồm giao diện đăng nhập Vụ Xanh, menu tài khoản/đăng xuất và đích đăng nhập trực tiếp V2.

- Production build và TypeScript đạt ở bản nguồn trước khi xuất repo.
- HTTP/SSR81/81; đối chiếu47/47 handler trong phạm vi đã kiểm không đổi.
- Browser đã kiểm login/logout ba vai trò và giao diện đại diện320/768/1366.
- Chưa bấm lại mọi giao dịch nghiệp vụ trong đợt UI. Không coi menu mở được hoặc build đạt là nghiệm thu toàn bộ35 hành trình.
- AI DISABLED; chưa bật provider AI/SMTP/worker/scanner/scheduler.
- Chưa chứng minh full PostgreSQL parity hoặc production-ready. Các giới hạn Unicode/collation, BSON16MiB, toàn bộ FK, unknown commit và failover vẫn được giữ trạng thái chưa hoàn tất.

## Bảo vệ thông tin

Không đưa .env thật, credential, cookie/session, state, database, lịch sử QA, runtime, báo cáo Word hoặc workspace .agent lên Git. Các .env.example không chứa mật khẩu thật. Tài khoản/địa chỉ .test trong helper là dữ liệu mẫu công khai, không phải tài khoản thật. Metadata tác giả thư viện trong lockfile được giữ nguyên.

Source gốc và ứng dụng đang chạy được giữ nguyên; repo là bản sao mã nguồn. Các link tới Shop ở Admin và ngược lại vẫn dùng đường dẫn của ứng dụng chung.

## Cập nhật UI Admin V2 — 30/10/2026

Vòng sửa giao diện bổ sung header cố định, tương phản nút Light/Dark, bố cục bảng và biểu mẫu Admin, biểu đồ báo cáo và ma trận vai trò. Thay đổi trong 28 file React/CSS Admin; Laravel, Shop, nghiệp vụ, schema và dependency giữ nguyên.

Build và TypeScript đã đạt tại source; 47/47 handler và hai truy vấn Dashboard được đối chiếu không đổi. Browser kiểm giao diện đại diện 320/768/1366px, theme/sidebar/search/account và login/logout ba vai trò; không phải xác nhận mọi giao dịch ghi hoặc mọi màn đã hoàn thiện.

Tổng quan Shop vẫn còn phần danh sách báo cáo chi tiết chưa đồng bộ giao diện V2. Một số mục điều hướng vẫn hiển thị với tài khoản không có quyền, API tiếp tục kiểm quyền theo chuỗi/chi nhánh. Đây là các giới hạn UI đang chờ xử lý; không nới quyền hoặc đổi nghiệp vụ trong lần cập nhật này.
