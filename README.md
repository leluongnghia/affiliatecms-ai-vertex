# AffiliateCMS AI Vertex AI Integration

Plugin mở rộng cho **AffiliateCMS AI**, giúp tích hợp dễ dàng các mô hình Google Cloud Vertex AI (Gemini 1.5, 2.0, 3.0, 3.5, v.v.) vào website WordPress của bạn mà không cần phải can thiệp trực tiếp vào mã nguồn (core) của plugin chính.

## Tính năng nổi bật
* **Hỗ trợ Vertex AI API**: Cho phép kết nối và sử dụng API của Google Cloud.
* **Xác thực qua Service Account**: Bạn có thể upload file JSON của Service Account để tạo mã thông báo (token) tự động, bảo mật.
* **Quản lý Custom Model**: Thêm ID của bất kỳ model tùy chỉnh nào bạn muốn sử dụng cho Vertex AI, CLIProxy, hoặc CKey.
* **Giao diện trực quan**: Tích hợp mượt mà vào màn hình cài đặt của AffiliateCMS AI hiện tại.

## Cài đặt
1. Tải file `.zip` của plugin.
2. Đăng nhập vào trang quản trị (Admin) WordPress của bạn.
3. Đi tới mục **Plugins** -> **Cài mới (Add New)** -> **Tải lên (Upload Plugin)**.
4. Chọn file `.zip` và bấm **Cài đặt ngay**.
5. Sau đó nhấn **Kích hoạt (Activate)**.

## Hướng dẫn cấu hình Vertex AI
1. Đảm bảo bạn đã kích hoạt [Vertex AI API](https://console.cloud.google.com/vertex-ai) trên Google Cloud Platform (GCP).
2. Tạo một **Service Account** có quyền truy cập vào Vertex AI (ví dụ: `Vertex AI User`).
3. Tạo và tải về file khóa bảo mật dạng **JSON** cho Service Account đó.
4. Trong giao diện Cài đặt (Settings) của **AffiliateCMS AI**, chọn cấu hình API cho **Google Cloud Vertex AI**.
5. Tải lên (Upload) file JSON vừa tải về ở mục **GCP Service Account JSON** (hệ thống sẽ tự động lấy *Project ID*).
6. Nhập khu vực triển khai (Region) của bạn (ví dụ: `us-central1` hoặc `global`).
7. Bấm **Lưu cài đặt**. Tận hưởng sức mạnh của Google AI trên trang web của bạn!
