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
├── docker-compose.yml       # WordPress, MySQL, phpMyAdmin, WP-CLI
├── .env.example             # mẫu biến môi trường (copy thành .env)
├── wp-content/
│   ├── themes/kio-child/    # child theme: phần hiển thị
│   └── plugins/kio-store/   # plugin: phần chức năng
├── data/                    # dữ liệu sản phẩm mẫu
├── scripts/                 # script WP-CLI: cài đặt, nạp dữ liệu, reset
└── docs/                    # ghi chú học tập theo từng phase
```

Chỉ có `kio-child` và `kio-store` được đưa vào git. Core WordPress, plugin cài từ kho và thư mục `uploads` nằm trong volume Docker.

## Cách chạy

Yêu cầu: Docker Desktop, cổng `8080` và `8081` còn trống.

```bash
cp .env.example .env        # rồi đổi các mật khẩu trong .env
./scripts/install.sh        # khởi động container, cài và cấu hình WordPress
```

`install.sh` chạy lặp lại được: nếu WordPress đã cài thì chỉ áp lại cấu hình.

| Dịch vụ | Địa chỉ | Đăng nhập |
|---|---|---|
| Website | http://localhost:8080 | |
| wp-admin | http://localhost:8080/wp-admin | `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` trong `.env` |
| phpMyAdmin | http://localhost:8081 | tự đăng nhập |

### Lệnh hay dùng

| Việc | Lệnh |
|---|---|
| Chạy lệnh WP-CLI | `docker compose run --rm wpcli <lệnh>`, ví dụ `... wpcli plugin list` |
| Xem log lỗi PHP | `docker compose exec wordpress tail -f wp-content/debug.log` |
| Tắt (giữ dữ liệu) | `docker compose down` |
| Xoá sạch, cài lại từ đầu | `./scripts/reset.sh` |
| Tạo nội dung mẫu Phase 0 | `./scripts/phase0-sample-content.sh` |

## Lộ trình

| Phase | Nội dung | Ghi chú | Trạng thái |
|---|---|---|---|
| 0 | Dựng môi trường, làm quen WordPress | `docs/phase0/phase-0.md` (local) | ✅ Xong |
| 1 | WooCommerce và dữ liệu sản phẩm | | ⬜ |
| 2 | Giao diện: classic theme, child theme | | ⬜ |
| 3 | Plugin chức năng | | ⬜ |
| 4 | Tuỳ biến bằng code: hook, CPT, REST API | | ⬜ |
| 5 | Hiệu năng | | ⬜ |
| 6 | Bảo mật và vận hành | | ⬜ |
| 7 | Deploy và Cloudflare (tuỳ chọn) | | ⬜ |
| 8 | Tổng kết | | ⬜ |
