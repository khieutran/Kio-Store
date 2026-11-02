# Kio Store

Website demo bán điện thoại, máy tính bảng và đồ điện tử, dựng bằng **WordPress + WooCommerce**.
Đây là project học tập: mục tiêu là hiểu WordPress hoạt động thế nào và làm việc với nó như một developer.

> `Kio Mobile` là thương hiệu tự đặt. Website không sao chép giao diện, logo hay nội dung của bất kỳ website có thật nào.

## Công nghệ

| Thành phần | Lựa chọn |
|---|---|
| CMS | WordPress (image Docker chính thức) |
| Thương mại điện tử | WooCommerce |
| Database | MySQL 8.4 + phpMyAdmin |
| Theme | Classic theme miễn phí + child theme `kio-child` |
| Code tuỳ biến | Plugin `kio-store` |
| Tự động hoá | WP-CLI |
| Môi trường | Docker Compose, chạy trên máy local |

## Cấu trúc thư mục

```
├── docker-compose.yml       # WordPress, MySQL, phpMyAdmin, Mailpit, WP-CLI
├── .env.example             # mẫu biến môi trường (copy thành .env)
├── wp-content/
│   ├── themes/kio-child/    # child theme: phần hiển thị
│   └── plugins/kio-store/   # plugin: phần chức năng
├── data/                    # dữ liệu mẫu (JSON) và font để vẽ ảnh sản phẩm
├── scripts/                 # script WP-CLI: cài đặt, nạp dữ liệu, reset, smoke test
├── .github/workflows/ci.yml # CI: lint + smoke test
├── composer.json            # chỉ chứa công cụ kiểm tra code (PHPCS)
├── phpcs.xml.dist           # quy tắc PHPCS
└── docs/                    # ghi chú học tập theo từng phase
```

Chỉ có `kio-child` và `kio-store` được đưa vào git. Core WordPress, plugin cài từ kho và thư mục `uploads` nằm trong volume Docker.

## Cách chạy

Yêu cầu: Docker Desktop, cổng `8080`, `8081` và `8025` còn trống.

```bash
cp .env.example .env        # rồi đổi các mật khẩu trong .env
./scripts/install.sh        # khởi động container, cài và cấu hình WordPress
```

`install.sh` dựng toàn bộ cửa hàng: WordPress, WooCommerce, cấu hình Việt Nam, danh mục, thương hiệu, thuộc tính, giao hàng, thanh toán, coupon và 33 sản phẩm demo có ảnh. Lần đầu khoảng 5 phút. Chạy lặp lại được: thứ gì có rồi thì chỉ cập nhật.

> ⚠️ `./scripts/reset.sh` **xoá sạch database và ảnh upload** rồi cài lại từ đầu. Sản phẩm, đơn hàng tạo tay trong wp-admin sẽ mất. Chỉ dùng trên máy dev.

| Dịch vụ | Địa chỉ | Đăng nhập |
|---|---|---|
| Website | http://localhost:8080 | |
| wp-admin | http://localhost:8080/wp-admin | `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` trong `.env` |
| phpMyAdmin | http://localhost:8081 | tự đăng nhập |
| Mailpit (hộp thư giả) | http://localhost:8025 | mọi email WordPress gửi đi đều nằm ở đây |

### Lệnh hay dùng

| Việc | Lệnh |
|---|---|
| Chạy lệnh WP-CLI | `docker compose run --rm wpcli <lệnh>`, ví dụ `... wpcli plugin list` |
| Xem log lỗi PHP | `docker compose exec wordpress tail -f wp-content/debug.log` |
| Tắt (giữ dữ liệu) | `docker compose down` |
| Xoá sạch, cài lại từ đầu | `./scripts/reset.sh` |
| Tạo nội dung mẫu Phase 0 | `./scripts/phase0-sample-content.sh` |
| Nạp lại sản phẩm demo | `docker compose run --rm wpcli eval-file /scripts/import-products.php` |

## Kiểm tra tự động (CI)

GitHub Actions chạy `.github/workflows/ci.yml` mỗi khi push lên `main` hoặc mở Pull Request:

| Job | Kiểm tra | Thời gian |
|---|---|---|
| **Lint** | Cú pháp PHP (`php -l`) · chuẩn WordPress cho plugin/theme (PHPCS + WPCS, gồm các quy tắc bảo mật) · JSON trong `data/` · script bash (shellcheck) | ~1 phút |
| **Smoke test** | Dựng toàn bộ cửa hàng bằng `install.sh` trên máy ảo GitHub, rồi `scripts/smoke-test.py` kiểm tra như khách: trang web, 33 sản phẩm, giá VND, hết hàng, giỏ hàng, phí ship, coupon, email, log lỗi PHP | ~6 phút |

Chạy giống CI trên máy (không cần cài PHP, dùng Docker):

```bash
# Lint
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install -q && vendor/bin/phpcs"
docker run --rm -v "$PWD":/mnt -w /mnt koalaman/shellcheck:v0.11.0 -x scripts/*.sh

# Smoke test (sau khi site đang chạy)
python3 scripts/smoke-test.py
```

Tự sửa lỗi định dạng PHPCS: `docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/phpcbf`

**Phiên bản cố định:** image Docker (`docker-compose.yml`), WooCommerce (`WOO_VERSION` trong `scripts/setup-woocommerce.sh`), shellcheck và các gói Composer (`composer.lock`) đều ghi rõ phiên bản, để máy dev và CI chạy cùng một bộ phần mềm. Nâng cấp = đổi số phiên bản, chạy CI, xanh thì merge.

Chưa có CD (tự deploy): sẽ làm ở Phase 7 khi có server. CD chỉ đưa **code** (`kio-child`, `kio-store`) lên server, không bao giờ đưa database hay chạy `reset.sh`.

## Lộ trình

| Phase | Nội dung | Ghi chú | Trạng thái |
|---|---|---|---|
| 0 | Dựng môi trường, làm quen WordPress | `docs/phase0/phase-0.md` (local) | ✅ Xong |
| 1 | WooCommerce và dữ liệu sản phẩm | `docs/phase1/phase-1.md` (local) | ✅ Xong |
| 1.5 | CI: lint + smoke test trên GitHub Actions | README, mục trên | 🟡 Chờ chạy lần đầu trên GitHub |
| 2 | Giao diện: classic theme, child theme | | ⬜ |
| 3 | Plugin chức năng | | ⬜ |
| 4 | Tuỳ biến bằng code: hook, CPT, REST API | | ⬜ |
| 5 | Hiệu năng | | ⬜ |
| 6 | Bảo mật và vận hành | | ⬜ |
| 7 | Deploy và Cloudflare (tuỳ chọn) | | ⬜ |
| 8 | Tổng kết | | ⬜ |
