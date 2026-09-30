# CS2 Case Opening Simulator | Full-Stack Web Platform

Hệ thống giả lập mở rương Counter-Strike 2 (CS2) toàn diện đạt chuẩn production, bao gồm đầy đủ 11 thành phần kiến trúc: **Frontend**, **Backend**, **Database**, **API**, **Authentication**, **Authorization**, **Web Server**, **Security**, **Deployment**, **Monitoring**, và **Logging**.

---

## 1. Tổng Quan Kiến Trúc 11 Trụ Cột

```
┌────────────────────────────────────────────────────────────────────────┐
│                          CS2 WEB APPLICATION                           │
│  [Frontend UI] Tailwind + Rajdhani + Web Audio Synth + Canvas Confetti  │
└────────────────────────────────────┬───────────────────────────────────┘
                                     │ HTTP / REST / JWT Bearer
┌────────────────────────────────────▼───────────────────────────────────┐
│                    WEB SERVER & REVERSE PROXY                          │
│  Nginx (SSL, Gzip, Caching, Rate Limit) -> Uvicorn ASGI (Port 8000)   │
└────────────────────────────────────┬───────────────────────────────────┘
                                     │
┌────────────────────────────────────▼───────────────────────────────────┐
│                          FASTAPI BACKEND CORE                          │
│  ├─ Auth & RBAC (PBKDF2-SHA256, JWT Tokens, Role: user/admin)         │
│  ├─ Provably Fair RNG Engine (HMAC-SHA256 Server/Client Seed)         │
│  ├─ OWASP Security Headers & Sliding Window Rate Limiter              │
│  ├─ Prometheus Metrics Collector (/metrics) & Health Check (/api/health)
│  └─ Structured Rotating Logging (app.log, audit.log)                   │
└────────────────────────────────────┬───────────────────────────────────┘
                                     │ ACID Transactions (WAL Mode)
┌────────────────────────────────────▼───────────────────────────────────┐
│                     DATABASE (SQLite / PostgreSQL)                     │
│  users | cases | skins | inventory | open_history | tradeup | audit   │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Chi Tiết Các Thành Phần

### 🎨 1. Frontend
- **Giao diện CS2 Tactical Cyber Dark**: Theme bóng bẩy thể thao điện tử, hiệu ứng phát sáng theo cấp độ hiếm (Mil-Spec Xanh, Restricted Tím, Classified Hồng, Covert Đỏ, Dao/Găng Vàng).
- **Vòng quay Roulette Reel chân thực**:
  - Dải trượt 75 ô với hiệu ứng giảm tốc quán tính chuẩn vật lý CS2 (`cubic-bezier(0.12, 0.8, 0.2, 1)`).
  - Kim vàng chỉ tâm với vạch canh tọa độ chính xác.
  - Tùy chọn Mở Nhanh (Fast Skip) và Mở nhiều rương cùng lúc (x1, x2, x3, x5).
- **Bộ giả lập âm thanh Web Audio API (`static/js/sounds.js`)**:
  - Tự động tổng hợp âm thanh bằng sóng âm (Triangle/Sine/Sawtooth), **100% không phụ thuộc file MP3 ngoài**, độ trễ 0ms.
  - Tiếng lách cách (Tick) khi thẻ trôi qua kim, tiếng rít mở hòm, tiếng vỗ tay/kèn trumpet (Fanfare) khi mở trúng Dao Vàng!
- **Hệ thống Kho Đồ (Inventory)**:
  - Bộ lọc theo bậc hiếm, sắp xếp theo giá trị, độ mòn (Float Wear) và ngày unbox.
  - Thước đo Float chính xác 6 chữ số thập phân (Factory New -> Battle-Scarred).
  - Nút Bán Nhanh từng món hoặc Bán Tất Cả (Quick Sell All) để thu hồi tiền mặt demo.
- **Hợp Đồng Nâng Cấp CS2 Trade-Up Contract**:
  - Chọn 10 skin cùng bậc hiếm để ghép thành 1 skin bậc cao hơn.
  - Tính toán độ mòn đầu ra theo công thức chuẩn game Valve: $Float_{out} = MinFloat + AvgFloat \times (MaxFloat - MinFloat)$.

---

### ⚙️ 2. Backend & API
- Phát triển trên nền **FastAPI** (Python 3.12/3.14) hiệu năng cao, bất đồng bộ (`async`/`await`).
- Đầy đủ tài liệu tương tác tự động **Swagger UI** tại `/api/docs` và **ReDoc** tại `/api/redoc`.
- Danh mục API chính:
  - `POST /api/auth/register`: Đăng ký tài khoản
  - `POST /api/auth/login`: Đăng nhập lấy Bearer JWT
  - `POST /api/auth/demo-login`: Đăng nhập nhanh tài khoản thử nghiệm
  - `POST /api/auth/admin-login`: Đăng nhập nhanh quyền Quản trị viên
  - `GET /api/cases`: Danh sách rương kèm giá và số lượng skin
  - `GET /api/cases/{id}`: Chi tiết skin và tỉ lệ trong rương
  - `POST /api/cases/{id}/open`: Mở hòm với thuật toán Provably Fair
  - `POST /api/cases/verify-roll`: Công cụ công khai kiểm tra độ công bằng
  - `GET /api/inventory`: Quản lý kho đồ cá nhân
  - `POST /api/inventory/{id}/sell`: Bán skin thu tiền
  - `POST /api/tradeup`: Ký hợp đồng đổi 10 skin
  - `POST /api/wallet/deposit`: Nạp thêm tiền demo
  - `GET /api/health`: Kiểm tra sức khỏe hệ thống
  - `GET /metrics`: Xuất số liệu chuẩn Prometheus

---

### 💾 3. Database
- **SQLite Engine** với chế độ ghi song song **WAL (Write-Ahead Logging)** và Foreign Keys ràng buộc toàn vẹn dữ liệu.
- Các bảng dữ liệu chính:
  1. `users`: Tài khoản, email, mật khẩu băm, quyền (role), số dư khả dụng (balance).
  2. `cases`: Danh mục rương CS2 (Kilowatt, Revolution, Dreams & Nightmares, Fracture, CS:GO Weapon Case #1...).
  3. `skins`: Chi tiết vũ khí, tên skin, phẩm cấp, màu sắc, giá cơ sở, min/max float.
  4. `inventory`: Kho đồ người chơi, độ mòn float, nhãn StatTrak™, trạng thái sở hữu/đã bán.
  5. `open_history`: Nhật ký mở rương lưu trữ `server_seed`, `client_seed`, `nonce`, `hash`, giá tiền.
  6. `tradeup_history`: Lịch sử các hợp đồng nâng cấp đã thực hiện.
  7. `transactions`: Sổ cái tài chính ghi nhận mọi biến động số dư.
  8. `audit_logs`: Nhật ký kiểm toán bảo mật lưu trữ địa chỉ IP, hành động và thời gian.

---

### 🔐 4. Authentication & Authorization (Bảo Mật & Phân Quyền)
- **Mã hóa mật khẩu**: Sử dụng giải thuật **PBKDF2-HMAC-SHA256** với **100,000 vòng lặp** và chuỗi salt 32-byte ngẫu nhiên cho mỗi người dùng.
- **Xác thực JWT**: JSON Web Tokens ký mã HS256, tự động kiểm tra thời hạn và định danh `sub`.
- **Phân quyền RBAC (Role-Based Access Control)**:
  - Phân quyền `user` (người chơi thông thường).
  - Phân quyền `admin` (truy cập màn hình giám sát, điều chỉnh số dư, xem log hệ thống).
  - Phân tầng bảo vệ thông qua FastAPI Dependency Injection (`require_admin`).

---

### 🛡️ 5. Security (An Toàn & Minh Bạch Provably Fair)
- **Hệ thống Provably Fair**:
  - Người chơi nhận được mã băm `SHA256(ServerSeed)` **trước khi mở**.
  - Kết quả được tính bằng công thức toán học minh bạch:
    $$\text{Roll} = \frac{\text{HMAC-SHA256}(\text{ServerSeed}, \text{ClientSeed} : \text{Nonce})[:8]}{2^{32}}$$
  - Tỉ lệ rơi chuẩn xác:
    - Mil-Spec: **79.92%**
    - Restricted: **15.98%**
    - Classified: **3.20%**
    - Covert: **0.64%**
    - Special Rare (Dao/Găng): **0.26%**
    - Tỉ lệ StatTrak™: **10%**
- **Rate Limiting**: Bộ điều tiết trượt (sliding window) chống spam yêu cầu API và tấn công brute-force mật khẩu.
- **Bảo mật giao dịch ACID**: Toàn bộ thao tác trừ tiền và nhận đồ thực thi trong một transaction nguyên tử, chống gian lận nạp lậu / số dư âm.
- **OWASP Security Headers**: Tự động áp dụng `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection`, `Referrer-Policy`.

---

### 📊 6. Monitoring & Logging (Giám Sát & Nhật Ký)
- **Healthcheck Endpoint (`/api/health`)**: Phục vụ load balancer giám sát trạng thái DB, dung lượng ổ đĩa, Uptime và bộ nhớ RAM.
- **Prometheus Exporter (`/metrics`)**: Cung cấp metrics chuẩn Prometheus (CPU/Memory, HTTP status count, tổng lượt mở rương, tổng doanh thu, độ trễ API).
- **Màn hình Quản trị Live trên Web (`#view-admin`)**:
  - Đồng hồ đo RAM, Uptime, lượt request, lợi nhuận sàn (House Edge).
  - **Terminal xem Log thời gian thực**: Trực tiếp theo dõi `logs/app.log` và `logs/audit.log` ngay trên giao diện web.
- **Ghi nhật ký đa tầng (Logging)**:
  - Tự động xoay vòng file log (Rotating File Handler, tối đa 10MB/file, lưu trữ 5-10 bản backup).
  - Phân tách riêng biệt giữa nhật ký ứng dụng thông thường và nhật ký kiểm toán tài chính/bảo mật.

---

## 3. Hướng Dẫn Cài Đặt & Khởi Chạy

### Cách 1: Khởi Chạy Nhanh Trên Windows (Khuyên Dùng)
Chỉ cần nhấp đúp vào file `start.bat` trong thư mục dự án:
```powershell
.\start.bat
```
Script sẽ tự động khởi động server và mở trình duyệt web tại `http://localhost:8000`.

---

### Cách 2: Khởi Chạy Bằng Lệnh Thủ Công
1. Cài đặt các thư viện phụ thuộc:
```bash
pip install -r requirements.txt
```

2. Khởi chạy Uvicorn Web Server:
```bash
python -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

3. Mở trình duyệt và truy cập:
- **Trang chủ Web**: [http://localhost:8000](http://localhost:8000)
- **Tài liệu API Swagger**: [http://localhost:8000/api/docs](http://localhost:8000/api/docs)
- **Prometheus Metrics**: [http://localhost:8000/metrics](http://localhost:8000/metrics)
- **Kiểm tra sức khỏe**: [http://localhost:8000/api/health](http://localhost:8000/api/health)

---

### Cách 3: Triển Khai Bằng Docker & Docker Compose
```bash
docker-compose up -d --build
```
Kiểm tra logs container:
```bash
docker-compose logs -f
```

---

## 4. Tài Khoản Mặc Định Sẵn Có

| Tài Khoản | Tên Đăng Nhập | Mật Khẩu | Vai Trò | Số Dư Ban Đầu | Mục Đích |
|---|---|---|---|---|---|
| **Admin** | `admin` | `Admin@123456` | `admin` | $10,000.00 | Quản trị, xem log, giám sát hệ thống |
| **Demo Player** | `demoplayer` | `Demo@123456` | `user` | $1,000.00 | Trải nghiệm mở rương tức thì |

*(Có nút đăng nhập nhanh 1-click ngay trên modal Đăng Nhập để kiểm thử tiện lợi)*.
