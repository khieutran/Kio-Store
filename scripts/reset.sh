#!/usr/bin/env bash
# Xoá toàn bộ dữ liệu (database + file WordPress) rồi cài lại từ đầu.
# Code trong kio-child và kio-store KHÔNG bị xoá vì nằm trong repo.
#
# Cách dùng: ./scripts/reset.sh        (hỏi xác nhận)
#            ./scripts/reset.sh -y     (không hỏi)

set -euo pipefail
cd "$(dirname "$0")/.."

if [[ "${1:-}" != "-y" ]]; then
  read -r -p "Xoá toàn bộ database và file WordPress của kio-store? [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]] || { echo "Đã huỷ."; exit 1; }
fi

echo "==> Dừng container và xoá volume"
docker compose --profile tools down -v --remove-orphans

./scripts/install.sh
