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

> Đang hoàn thiện ở Phase 0.

```bash
cp .env.example .env        # đặt mật khẩu database
docker compose up -d
```

| Dịch vụ | Địa chỉ |
|---|---|
| Website | http://localhost:8080 |
| wp-admin | http://localhost:8080/wp-admin |
| phpMyAdmin | http://localhost:8081 |

## Lộ trình

| Phase | Nội dung | Ghi chú | Trạng thái |
|---|---|---|---|
| 0 | Dựng môi trường, làm quen WordPress | [phase-0.md](docs/phase-0.md) | 🟡 Đang làm |
| 1 | WooCommerce và dữ liệu sản phẩm | | ⬜ |
| 2 | Giao diện: classic theme, child theme | | ⬜ |
| 3 | Plugin chức năng | | ⬜ |
| 4 | Tuỳ biến bằng code: hook, CPT, REST API | | ⬜ |
| 5 | Hiệu năng | | ⬜ |
| 6 | Bảo mật và vận hành | | ⬜ |
| 7 | Deploy và Cloudflare (tuỳ chọn) | | ⬜ |
| 8 | Tổng kết | | ⬜ |
