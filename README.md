# CS2 Case Opening Simulator (PHP + MySQL + Apache)

Hệ thống giả lập mở rương Counter-Strike 2 chuẩn mực, hiệu năng cao viết bằng **PHP 8.2+**, cơ sở dữ liệu **MySQL 8.0**, chạy trên máy chủ **Apache HTTP Server**.

---

## 🚀 Tính năng chính

- **Ngôn ngữ Backend**: PHP 8.0+ thuần (Native PHP với PDO, chuẩn PSR, không phụ thuộc framework cồng kềnh, tốc độ xử lý tính bằng mili-giây).
- **Cơ sở dữ liệu**: MySQL 8.0 (hỗ trợ InnoDB, transactions ACID, đầy đủ 47 rương và 1,293 skin CS2 thật kèm hình ảnh Steam CDN).
- **Web Server**: Apache HTTP Server 2.4 (với `mod_rewrite`, `mod_headers`, `mod_deflate`, `mod_expires`, cấu hình bảo mật `.htaccess`).
- **Xác thực & Bảo mật**: JWT Authentication (HS256), băm mật khẩu `password_hash()` bcrypt (cost 12), Rate Limiting chống spam request, Audit Logs ghi vết bảo mật.
- **Thuật toán Provably Fair**: Đảm bảo 100% minh bạch với HMAC-SHA512 (Server Seed, Client Seed, Nonce), API kiểm tra độc lập `/api/cases/verify-roll`.
- **Hệ thống Giftcode**: Tự động phát mã ngẫu nhiên 6 chữ số mỗi 1 tiếng, nhận ngay $100.00.
- **Chuỗi quà đăng nhập 7 ngày (Daily Streak)**: Tích lũy chuỗi đăng nhập liên tiếp nhận quà tăng dần từ $5 đến $50.
- **Hợp đồng Trade-Up Contract**: Đổi 10 skin cùng bậc hiếm lấy 1 skin bậc cao hơn theo công thức Wear Float CS2 chuẩn.
- **Soi 3D Vũ khí (Inspect 3D Engine)**: Xem 3D mô hình súng xoay 360 độ, đổi góc chiếu, StatTrak, wear float chi tiết với Three.js GPU accelerated.
- **Bảng Quản trị Admin Dashboard**: Giám sát thời gian thực, quản lý người dùng, chỉnh sửa số dư/quyền, điều chỉnh giá rương, phát mã thưởng, xem log server.

---

## 📂 Cấu trúc thư mục

```
cs2-case-simulator-php/
├── .htaccess             # Cấu hình Apache mod_rewrite, security headers & routing
├── index.php             # Điểm vào chính phục vụ Frontend SPA
├── apache-vhost.conf     # Mẫu cấu hình Apache VirtualHost
├── Dockerfile            # Docker build PHP 8.2 + Apache + PDO MySQL
├── docker-compose.yml    # Stack 1-click: Apache + PHP + MySQL 8.0 + phpMyAdmin
├── start.bat             # Script khởi động tự động trên Windows
├── config/
│   ├── config.php        # Cấu hình DB, Secret Key, CORS, Admin info
│   └── database.php      # PDO Connection singleton, password hashing, audit log
├── includes/
│   ├── helpers.php       # JSON response, JWT encode/decode, Auth guards, Rate limit
│   └── security.php      # Provably Fair RNG engine (HMAC-SHA512)
├── api/                  # Toàn bộ RESTful API Endpoints
│   ├── auth.php          # /api/auth (register, login, forgot-password, me)
│   ├── cases.php         # /api/cases (list, detail, open, verify-roll)
│   ├── inventory.php     # /api/inventory (list, sell, sell-all)
│   ├── wallet.php        # /api/wallet (active-code, redeem-code, deposit, tx)
│   ├── daily.php         # /api/daily (status, claim)
│   ├── tradeup.php       # /api/tradeup (hợp đồng trade-up 10 items)
│   └── admin.php         # /api/admin (overview, users, balance, giftcode, logs)
├── sql/
│   ├── schema.sql        # Cấu trúc bảng MySQL 8.0
│   └── seed_full_data.sql# Dữ liệu đầy đủ 47 rương, 1293 skin, tài khoản admin
├── static/               # Frontend Assets (HTML, CSS, JS, 3D, Sounds)
│   ├── index.html        # Giao diện chính CS2 Tactical UI
│   ├── css/style.css     # CSS GPU-accelerated styling
│   ├── js/app.js         # Frontend controller & roulette engine
│   ├── js/inspect3d.js   # Three.js 3D weapon inspect engine
│   ├── js/sounds.js      # Web Audio SFX engine
│   └── js/three.min.js   # Three.js r128 library
└── logs/                 # Thư mục lưu nhật ký app.log và audit.log
```

---

## 🛠️ Hướng dẫn cài đặt & Khởi chạy

### Cách 1: Chạy bằng Docker Compose (Khuyên dùng — 1 Click là chạy)

1. Mở Docker Desktop.
2. Mở terminal tại thư mục dự án và chạy:
   ```bash
   docker compose up -d
   ```
3. Truy cập:
   - **Giao diện Mở rương**: [http://localhost](http://localhost)
   - **phpMyAdmin (Quản lý DB)**: [http://localhost:8080](http://localhost:8080) (Đăng nhập: User `root`, Mật khẩu `rootpassword`)
   - **Tài khoản Admin mặc định**: Tên đăng nhập `admin`, Mật khẩu `admin`

---

### Cách 2: Chạy bằng XAMPP / Laragon trên Windows

1. **Khởi động XAMPP**: Mở XAMPP Control Panel, nhấn **Start** cho **Apache** và **MySQL**.
2. **Tạo Cơ sở dữ liệu**:
   - Truy cập `http://localhost/phpmyadmin/`.
   - Tạo database mới tên: `cs2_simulator` (Collation: `utf8mb4_unicode_ci`).
   - Vào mục **Import**, chọn file `sql/schema.sql` rồi nhấn **Go**.
   - Tiếp tục chọn file `sql/seed_full_data.sql` rồi nhấn **Go** (nạp 47 rương và 1293 skin).
3. **Cài đặt thư mục Web**:
   - Copy toàn bộ thư mục `cs2-case-simulator-php` vào thư mục `C:\xampp\htdocs\cs2-case-simulator`.
   - Mở file `config/config.php`, kiểm tra thông số kết nối:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_PORT', '3306');
     define('DB_NAME', 'cs2_simulator');
     define('DB_USER', 'root');
     define('DB_PASS', ''); // Mặc định XAMPP không có mật khẩu
     ```
4. **Bật `mod_rewrite` trong Apache** (nếu chưa bật):
   - Mở `C:\xampp\apache\conf\httpd.conf`.
   - Đảm bảo dòng sau không có dấu `#` ở đầu:
     ```apache
     LoadModule rewrite_module modules/mod_rewrite.so
     ```
5. **Truy cập**:
   - Mở trình duyệt vào: `http://localhost/cs2-case-simulator/`

---

### Cách 3: Chạy trên Linux (Ubuntu/Debian) LAMP Stack

1. **Cài đặt Apache, PHP & MySQL**:
   ```bash
   sudo apt update
   sudo apt install -y apache2 php php-pdo php-mysql php-mbstring php-curl mysql-server
   sudo a2enmod rewrite headers
   ```
2. **Tạo Database**:
   ```bash
   sudo mysql -u root -e "CREATE DATABASE cs2_simulator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   sudo mysql -u root cs2_simulator < sql/schema.sql
   sudo mysql -u root cs2_simulator < sql/seed_full_data.sql
   ```
3. **Triển khai code**:
   ```bash
   sudo cp -r . /var/www/html/cs2/
   sudo chown -R www-data:www-data /var/www/html/cs2/
   sudo chmod -R 775 /var/www/html/cs2/logs/
   sudo systemctl restart apache2
   ```

---

## 🔐 Thông tin Đăng nhập Quản trị viên (Admin)

- **Tên đăng nhập**: `admin`
- **Mật khẩu**: `admin`
- Sau khi đăng nhập, trên thanh menu sẽ tự động xuất hiện nút **QUẢN TRỊ & GIÁM SÁT** với 5 tab quản lý:
  1. **Tổng Quan Hệ Thống**: Doanh thu, tỉ lệ RTP, lợi nhuận nhà cái, tài nguyên server.
  2. **Quản Lý Người Dùng**: Tìm kiếm, cấp tiền / trừ tiền, thay đổi vai trò (User/Admin).
  3. **Quản Lý Giftcode**: Xem mã 1 tiếng đang kích hoạt, phát mã mới ngay lập tức, đổi giá trị thưởng.
  4. **Quản Lý Giá Rương**: Thay đổi giá mở cho từng rương trong 47 rương, bật/tắt rương.
  5. **Nhật Ký & Audit Logs**: Xem log truy cập, đăng nhập và can thiệp số dư.
