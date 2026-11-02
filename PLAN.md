# Demo Mobile Store — Kế hoạch tổng

> Mục tiêu: **hiểu WordPress là gì và làm việc với nó như một developer**, thông qua việc dựng một website bán điện thoại, máy tính bảng và đồ điện tử bằng WordPress + WooCommerce.
> Ngày lập: 02/11/2026

---

## 1. Concept website

**Tên thương hiệu demo:** `Kio Mobile` (tên tự đặt, không sao chép thương hiệu thật)

**Phong cách:** chuỗi bán lẻ điện thoại kiểu Việt Nam, nhiều thông tin, tập trung vào giá và khuyến mãi. Không làm kiểu landing page tối giản. Concept này tổng hợp các pattern chung của ngành, lấy từ việc khảo sát các website bán lẻ điện thoại và điện tử lớn ở VN, chứ không sao chép một website cụ thể nào.

**Màu chủ đạo (gợi ý):** một màu thương hiệu (ví dụ xanh dương đậm), đỏ/cam cho giá và khuyến mãi, nền xám rất nhạt để nổi bật các thẻ sản phẩm.

### Danh mục sản phẩm
| Danh mục | Ví dụ |
|---|---|
| Điện thoại | iPhone, Samsung Galaxy, Xiaomi, OPPO |
| Máy tính bảng | iPad, Galaxy Tab, Xiaomi Pad |
| Laptop | MacBook, ASUS, Lenovo |
| Đồng hồ thông minh | Apple Watch, Galaxy Watch |
| Âm thanh | Tai nghe, loa Bluetooth |
| Phụ kiện | Sạc, cáp, ốp lưng, sạc dự phòng |

### Các trang và pattern cần có (rút ra từ khảo sát)

**Header**
- Thanh tiện ích trên cùng: "Hàng chính hãng · Miễn phí giao hàng từ 300k · Thu cũ đổi mới · Hotline 1800 xxxx"
- Logo, ô tìm kiếm lớn, nút "Danh mục", tra cứu đơn hàng, giỏ hàng, tài khoản
- Menu danh mục dạng mega menu, có icon cho từng nhóm

**Trang chủ**
- Banner trượt (hero slider) và các banner nhỏ bên cạnh
- Lưới icon danh mục
- Khối **Flash Sale / Deal hôm nay** có đồng hồ đếm ngược
- Các dải sản phẩm theo danh mục, có chip lọc thương hiệu và link "Xem tất cả"
- Hàng logo thương hiệu
- Dải cam kết: chính hãng, bảo hành 12 tháng, đổi trả 30 ngày, giao nhanh 2h

**Thẻ sản phẩm**
- Ảnh, tên kèm dung lượng, giá khuyến mãi (đỏ), giá gốc gạch ngang, nhãn `-x%`
- Nhãn "Trả góp 0%", "Mới", "Hết hàng"
- 2–3 thông số nổi bật (màn hình, chip, RAM), số sao đánh giá
- Nút "So sánh"

**Trang danh mục**
- Bộ lọc: thương hiệu (dạng logo), khoảng giá, RAM, bộ nhớ, tính năng
- Chip lọc nhanh (5G, sạc nhanh, pin trâu) và sắp xếp (nổi bật, giá tăng, giá giảm)
- Hiển thị số kết quả, nút "Xem thêm"

**Trang chi tiết sản phẩm**
- Gallery ảnh có thumbnail, breadcrumb, sao đánh giá và số lượng đã bán
- Chọn **dung lượng** và **màu**, đổi lựa chọn thì giá đổi theo; phiên bản hết hàng thì làm mờ
- Khối **Khuyến mãi** đánh số, có nút "Xem thêm"
- Khối "Trả góp 0% chỉ từ X đ/tháng"
- Thông số tóm tắt, bảng thông số đầy đủ chia theo nhóm (màn hình, camera, pin…)
- Bài viết mô tả, FAQ, đánh giá có biểu đồ sao
- Sản phẩm "Thường mua cùng" (phụ kiện) và "Sản phẩm liên quan"
- Thanh mua hàng dính dưới màn hình (sticky) trên mobile

**Footer**
- Các cột: chính sách (bảo hành, đổi trả, giao hàng), hỗ trợ, hotline có giờ làm việc
- Logo phương thức thanh toán, mạng xã hội, thông tin công ty (demo)

---

## 2. Kiến trúc kỹ thuật

```
Demo-Mobile-Store/
├── docker-compose.yml      # WordPress + MySQL + phpMyAdmin + WP-CLI + Redis
├── .env                    # mật khẩu DB (không commit)
├── wp-content/             # chỉ mount 2 thư mục tự viết, phần còn lại nằm trong volume Docker
│   ├── themes/kio-child/   # child theme tự viết (phần hiển thị)
│   └── plugins/kio-store/  # plugin tự viết (phần chức năng)
├── data/                   # file CSV sản phẩm mẫu, ảnh
├── scripts/                # script WP-CLI: cài plugin, nạp dữ liệu, reset
└── docs/                   # ghi chú học tập theo từng phase
```

| Thành phần | Lựa chọn | Lý do |
|---|---|---|
| WordPress | Image Docker chính thức | Chạy nhanh trên máy, không ảnh hưởng hệ thống |
| Database | MySQL 8 + phpMyAdmin | Xem trực tiếp WordPress lưu dữ liệu ra sao |
| Bán hàng | WooCommerce | Plugin thương mại điện tử phổ biến nhất của WordPress |
| Theme gốc | **Classic theme** miễn phí hỗ trợ WooCommerce tốt (chọn theme cụ thể ở Phase 2) | Template PHP và hook của WooCommerce chạy đầy đủ, dễ thấy template hierarchy, tài liệu nhiều |
| Tự động hoá | WP-CLI | Cài đặt và nạp dữ liệu bằng lệnh, làm lại được nhiều lần |
| Object cache | Redis (thêm service vào compose ở Phase 5) | Học object cache |

**Nguyên tắc chia code:**
- **Chức năng** (nhãn giá, trả góp, khuyến mãi, flash sale…) đặt trong plugin `kio-store`. Đổi theme thì tính năng vẫn còn.
- **Hiển thị** (CSS, template, layout) đặt trong child theme `kio-child`.
- Không bao giờ sửa trực tiếp core WordPress, theme gốc hay plugin của người khác.

**Lưu ý Docker:**
- Chỉ bind mount `themes/kio-child` và `plugins/kio-store`. Như vậy core, plugin cài từ kho và `uploads` không lẫn vào repo, và tránh được việc bind mount chậm trên macOS.
- Container WP-CLI (`wordpress:cli`) chạy với `user: "33:33"` để trùng quyền file với container WordPress (www-data).

---

## 3. Các phase

Mỗi phase có: **Học được gì · Việc cần làm · Xong khi**.

### Phase 0 — Dựng môi trường & làm quen WordPress
- **Học được gì:** WordPress gồm những gì (core, theme, plugin, database); trang quản trị `wp-admin`; khác nhau giữa Post và Page; WordPress lưu dữ liệu vào các bảng nào (`wp_posts`, `wp_postmeta`, `wp_options`, `wp_terms`, `wp_users`).
  - **Vòng đời một request:** `index.php` → `wp-config.php` → nạp plugin (`plugins_loaded`) → nạp theme (`after_setup_theme`) → `init` → phân tích URL thành query → chọn template theo template hierarchy → The Loop in nội dung. Đây là khung để hiểu mọi phase sau.
- **Việc cần làm:**
  - Viết `docker-compose.yml`, chạy WordPress tại `http://localhost:8080`
  - Cài tiếng Việt, đặt múi giờ và permalink
  - Dạo một vòng wp-admin: tạo thử 1 bài viết, 1 trang, 1 menu
  - Mở phpMyAdmin xem bài vừa tạo nằm ở bảng nào
  - Vẽ sơ đồ vòng đời request vào `docs/phase-0.md`
- **Xong khi:** website chạy được, anh giải thích được "WordPress lưu một bài viết như thế nào" và "từ lúc gõ URL đến lúc ra trang, WordPress làm những bước gì".

### Phase 1 — WooCommerce & dữ liệu sản phẩm
- **Học được gì:** các loại sản phẩm (simple, variable); khác nhau giữa thuộc tính (attribute) và biến thể (variation); danh mục và thẻ (taxonomy); đơn hàng, coupon, phí giao hàng, thanh toán. WooCommerce lưu sản phẩm và biến thể ra sao (post type `product` và `product_variation`, meta `_price`, `_regular_price`, `_sale_price`), đơn hàng lưu ở bảng HPOS `wp_wc_orders`.
- **Việc cần làm:**
  - Cài WooCommerce, cấu hình tiền VND, địa chỉ Việt Nam
  - Tạo 6 danh mục ở mục 1 và các thuộc tính: Dung lượng, Màu sắc, RAM
  - **Thương hiệu:** dùng taxonomy Brands có sẵn của WooCommerce (từ bản 9.6). Ghi lại so sánh "làm thương hiệu bằng attribute hay bằng taxonomy" vào `docs/phase-1.md`
  - Tạo tay khoảng **5 sản phẩm** trước (có cả simple và variable) để hiểu cấu trúc, xem trong phpMyAdmin
  - Sau đó viết script WP-CLI trong `scripts/` để nạp đủ khoảng **30 sản phẩm**, trong đó điện thoại và tablet là **variable product** (dung lượng × màu, mỗi phiên bản một giá)
  - Cấu hình thanh toán COD và chuyển khoản, phí ship, 2–3 coupon
  - Đặt thử một đơn hàng từ đầu đến cuối
- **Xong khi:** mua được hàng trên site, và thấy đơn hàng xuất hiện trong wp-admin.

### Phase 2 — Giao diện: Classic theme, child theme, Customizer
- **Theme:** dùng classic theme (đã chốt). Block theme không nằm trong phạm vi chính.
- **Học được gì:** theme là gì; **template hierarchy** (vì sao trang danh mục dùng `taxonomy-product_cat.php` hoặc template `archive-product`); Customizer, menu, widget; block editor dùng để soạn nội dung trang (classic theme không có Site Editor); trường hợp nào cấu hình hoặc kéo thả là đủ, trường hợp nào phải code; parent theme và child theme kế thừa nhau thế nào.
- **Việc cần làm:**
  - So sánh 2–3 **classic theme** miễn phí (ví dụ Storefront, Astra, Kadence) rồi chọn một; tạo **child theme** `kio-child` (`style.css` khai báo `Template:`, `functions.php` enqueue CSS của theme cha)
  - Chuyển trang Giỏ hàng và Thanh toán về shortcode `[woocommerce_cart]` và `[woocommerce_checkout]`. Từ WooCommerce 8.3, hai trang này mặc định dùng block, nên các hook classic ở giỏ hàng và thanh toán sẽ không chạy nếu không chuyển
  - Dựng header, mega menu, footer theo concept
  - Dựng trang chủ: hero slider, lưới danh mục, khối flash sale, các dải sản phẩm, hàng thương hiệu, dải cam kết
  - Chỉnh thẻ sản phẩm và trang danh mục cho giống pattern ở mục 1
  - Kiểm tra responsive trên mobile và tablet
- **Xong khi:** trang chủ, trang danh mục và trang sản phẩm nhìn như một chuỗi bán lẻ thật, không phải theme mặc định.

### Phase 3 — Plugin chức năng
- **Học được gì:** hệ sinh thái plugin; cách **đánh giá plugin** (số lượt cài, lần cập nhật gần nhất, đánh giá, độ nặng); rủi ro khi cài quá nhiều plugin.
- **Nguyên tắc:** với mỗi chức năng, kiểm tra WooCommerce hoặc WordPress đã có sẵn chưa rồi mới tìm plugin.
- **Việc cần làm:**
  - Bộ lọc sản phẩm theo thuộc tính và khoảng giá: thử block Product Filters có sẵn của WooCommerce trước, chỉ cài plugin nếu thiếu
  - So sánh sản phẩm
  - Đánh giá có ảnh, danh sách yêu thích
  - SEO (Yoast hoặc Rank Math): title, meta, sitemap, schema sản phẩm
  - Form liên hệ
  - Ghi vào `docs/plugins.md`: plugin nào, vì sao chọn, plugin nào đã loại
- **Xong khi:** đủ chức năng như concept, và có bảng đánh giá plugin.

### Phase 4 — Tuỳ biến bằng code (phần việc của developer)
- **Học được gì:**
  - **Hook** (`add_action`, `add_filter`): cách WordPress cho phép chèn code mà không sửa code gốc; thứ tự ưu tiên (priority)
  - **The Loop và `WP_Query`**: tự truy vấn danh sách sản phẩm/bài viết
  - **Custom Post Type, custom taxonomy, meta box**: tạo kiểu dữ liệu riêng
  - **Enqueue** CSS/JS đúng cách (`wp_enqueue_script`, `wp_enqueue_style`), chỉ nạp ở trang cần dùng
  - **Shortcode và block**: khác nhau thế nào, khi nào dùng cái nào
  - **Options API, transient** (cache kết quả tính toán), **WP-Cron** (việc chạy theo lịch)
  - **REST API**: WordPress trả dữ liệu dạng JSON
  - Override template của WooCommerce; viết plugin riêng (header plugin, activation/deactivation hook)
  - Bảo mật khi viết code: nonce, sanitize đầu vào, escape đầu ra, kiểm tra quyền (`current_user_can`)
- **Việc cần làm** (chức năng trong plugin `kio-store`, hiển thị trong child theme):
  - Nhãn tự động: `-x%` tính từ giá gốc và giá sale, "Mới" cho sản phẩm dưới 30 ngày (filter)
  - Dòng "Trả góp 0% chỉ từ X đ/tháng", tự tính từ giá; số tháng và lãi suất lưu bằng Options API, có trang cài đặt trong wp-admin
  - Khối "Thông số nổi bật" trên thẻ sản phẩm, lấy từ thuộc tính (action)
  - Khối khuyến mãi trên trang chi tiết: tạo **Custom Post Type** `kio_promotion`, có meta box chọn sản phẩm/danh mục áp dụng và thời gian hiệu lực
  - Đồng hồ đếm ngược flash sale: làm bản **shortcode** trước, sau đó làm lại thành **block**; dùng **WP-Cron** để tự kết thúc flash sale khi hết giờ
  - Endpoint **REST API** `/wp-json/kio/v1/flash-sale` trả danh sách sản phẩm đang flash sale
  - Dải sản phẩm tuỳ biến trên trang chủ dùng `WP_Query`, cache kết quả bằng **transient**
  - Thanh mua hàng sticky trên mobile (JS được enqueue chỉ ở trang sản phẩm)
- **Xong khi:** mỗi tính năng ở trên chạy được, và anh giải thích được nó gắn vào hook nào, dữ liệu lưu ở đâu.

### Phase 5 — Hiệu năng
- **Học được gì:** vì sao WordPress dễ chậm; các tầng cache (trang, object, trình duyệt); tối ưu ảnh; cách đo tốc độ.
- **Việc cần làm:**
  - **Đo trước khi tối ưu** bằng Lighthouse, ghi lại điểm số và các chỉ số LCP, CLS, TBT
  - Cài Query Monitor để tìm query và plugin chậm
  - Thêm service `redis` vào `docker-compose.yml`
  - Bật page cache, Redis object cache, chuyển ảnh sang WebP, lazy load, gộp/hoãn tải CSS/JS
  - Gỡ plugin thừa
  - **Đo lại** và ghi bảng trước/sau vào `docs/performance.md`
- **Xong khi:** có số liệu trước/sau rõ ràng.

### Phase 6 — Bảo mật & vận hành
- **Học được gì:** các kiểu tấn công thường gặp với WordPress (dò mật khẩu admin, plugin có lỗ hổng, XML-RPC); phân quyền user; backup và khôi phục.
- **Việc cần làm:**
  - Giới hạn số lần đăng nhập sai, bật 2FA, tắt XML-RPC, tắt chức năng sửa file trong admin, ẩn version
  - Cài plugin bảo mật và quét lỗ hổng
  - Phân quyền: Admin, Shop Manager, Editor
  - Backup tự động, sau đó **thử khôi phục** từ bản backup
  - Ghi checklist vào `docs/security.md`
- **Xong khi:** đi hết checklist, và đã khôi phục thành công từ backup.

### Phase 7 (tuỳ chọn) — Deploy & Cloudflare
- **Học được gì:** chạy WordPress trên server thật; CDN; quy tắc cache của Cloudflare (không cache giỏ hàng, thanh toán, tài khoản).
- **Việc cần làm:**
  - Deploy bằng Docker lên VPS, subdomain dạng `shop.kiotrandev.tech`
  - Gắn Cloudflare: SSL, cache rules, bảo vệ `wp-login.php`
  - Đo PageSpeed bản online, có và không có Cloudflare
  - Dùng ảnh tự chụp, ảnh miễn phí bản quyền hoặc ảnh có ghi chú "demo" khi public
- **Xong khi:** site chạy online qua Cloudflare, có số liệu tốc độ.

### Phase 8 — Tổng kết
- Viết README: ảnh chụp màn hình, cách chạy, những gì đã học ở mỗi phase
- Rút ra 3–4 câu để bổ sung vào cover letter và CV (WordPress, WooCommerce, tối ưu tốc độ, Cloudflare, bảo mật)

---

## 4. Ngoài phạm vi
- Không tích hợp cổng thanh toán thật, chỉ dùng COD và chuyển khoản
- Không sao chép giao diện, logo hay nội dung của một website có thật
- Không mua theme hoặc plugin trả phí, chỉ dùng bản miễn phí

## 5. Tiến độ
| Phase | Trạng thái |
|---|---|
| 0 — Môi trường | ⬜ |
| 1 — WooCommerce & dữ liệu | ⬜ |
| 2 — Giao diện | ⬜ |
| 3 — Plugin | ⬜ |
| 4 — Custom code | ⬜ |
| 5 — Hiệu năng | ⬜ |
| 6 — Bảo mật | ⬜ |
| 7 — Deploy & Cloudflare (tuỳ chọn) | ⬜ |
| 8 — Tổng kết | ⬜ |
