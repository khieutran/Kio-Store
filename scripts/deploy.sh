#!/usr/bin/env bash
# Chạy trên server sau khi code đã cập nhật. CD gọi file này, xem .github/workflows/ci.yml.
# Chỉ áp dụng code và cấu hình. Không đụng database, không chạy reset.sh hay import-products.php.
set -Eeuo pipefail
cd "$(dirname "$0")/.."

docker compose up -d
# File cấu hình Apache mount sẵn vào container: phải khởi động lại mới có tác dụng.
docker compose restart wordpress
docker compose --progress quiet run --rm -T wpcli cache-enabler clear
git log --oneline -1
