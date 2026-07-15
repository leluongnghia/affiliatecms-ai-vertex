# HƯỚNG DẪN CẤU HÌNH VÀ SỬ DỤNG CLIProxyAPI

Tài liệu này hướng dẫn cách kết nối và cấu hình API từ hệ thống proxy cá nhân của bạn (`https://api.leluongnghia.net`) vào các plugin WordPress khác, các công cụ lập trình hoặc ứng dụng khách (Clients).

---

## 1. Thông tin kết nối cơ bản

Để kết nối bất kỳ ứng dụng nào với hệ thống proxy của bạn, hãy sử dụng thông số cấu hình chuẩn sau:

*   **API Key:** `sk-mFC3NKeX0b9ZDgAeX`
*   **Base URL (OpenAI Compatible):** `https://api.leluongnghia.net/v1`
*   **Endpoint Chat Completions:** `https://api.leluongnghia.net/v1/chat/completions`
*   **Base URL (Anthropic/Claude Native):** `https://api.leluongnghia.net/v1`
*   **Endpoint Messages (Claude Native):** `https://api.leluongnghia.net/v1/messages`

---

## 2. Danh sách các Model nổi bật trên hệ thống

Hệ thống của bạn hỗ trợ hơn 50 model từ các tài khoản đã liên kết (Antigravity, xAI, Codex, Vertex AI). Dưới đây là các model tốt nhất được khuyên dùng:

| Hãng / Nhà cung cấp | Model ID | Ghi chú |
| :--- | :--- | :--- |
| **Anthropic (qua Antigravity)** | `claude-sonnet-4-6` | Model thông minh nhất hiện tại của Anthropic, viết bài và viết code cực tốt. |
| **Anthropic (qua Antigravity)** | `claude-opus-4-6-thinking` | Phiên bản Claude suy luận chuyên sâu. |
| **Google (qua Antigravity/Vertex)** | `gemini-3.1-pro-preview` | Model suy luận mạnh mẽ của Google. |
| **Google (qua Antigravity/Vertex)** | `gemini-3.5-flash` | Model xử lý cực nhanh, tối ưu chi phí. |
| **xAI (Grok)** | `grok-4.5` | Model thế hệ mới của xAI (Twitter). |
| **xAI (Grok)** | `grok-3-mini` | Model nhỏ gọn, phản hồi nhanh của xAI. |
| **OpenAI** | `gpt-5.5` | Model thế hệ mới của OpenAI. |

---

## 3. Hướng dẫn cấu hình trên các Plugin WordPress khác

Hầu hết các plugin WordPress hỗ trợ AI (như RankMath, Elementor AI, AI Engine, v.v.) đều có tuỳ chọn kết nối qua **Custom OpenAI** hoặc **OpenAI Compatible**.

### Các bước cấu hình chung:
1. Mở cài đặt AI của plugin đó.
2. Chọn nhà cung cấp (AI Provider / Engine) là **Custom** hoặc **OpenAI Compatible** (Tương thích OpenAI).
3. Điền các thông số:
   * **API Key:** `sk-mFC3NKeX0b9ZDgAeX`
   * **Custom API Endpoint / Base URL:** `https://api.leluongnghia.net/v1`
4. Điền tên model cần dùng vào ô chọn model (Ví dụ: `claude-sonnet-4-6` hoặc `gemini-3.1-pro-preview`).
5. Lưu lại và trải nghiệm.

---

## 4. Hướng dẫn cấu hình trên các Ứng dụng/Extension lập trình

Nếu bạn sử dụng các phần mềm chat hoặc hỗ trợ viết code (như **Cursor**, **Cline**, **Roo Code**, **Continue**, **Chatbox**, **NextChat**, **LobeChat**):

### Cấu hình trên Chatbox / NextChat / LobeChat:
*   **Model Provider:** Chọn `OpenAI` hoặc `OpenAI Compatible`.
*   **API Key:** `sk-mFC3NKeX0b9ZDgAeX`
*   **API Host (Base URL):** `https://api.leluongnghia.net/v1`
*   **Model List:** Chọn hoặc nhập thủ công `claude-sonnet-4-6` hoặc `gemini-3.1-pro-preview`.

### Cấu hình trên Cline / Roo Code (VS Code Extension):
*   **Provider:** Chọn `OpenAI Compatible`.
*   **Base URL:** `https://api.leluongnghia.net/v1`
*   **API Key:** `sk-mFC3NKeX0b9ZDgAeX`
*   **Model ID:** `claude-sonnet-4-6`

---

## 5. Hướng dẫn viết Code kết nối (Dành cho Lập trình viên)

### Gọi qua cURL:
```bash
curl https://api.leluongnghia.net/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-mFC3NKeX0b9ZDgAeX" \
  -d '{
    "model": "claude-sonnet-4-6",
    "messages": [
      {
        "role": "user",
        "content": "Viết tiêu đề chuẩn SEO cho bài viết về công nghệ AI."
      }
    ],
    "temperature": 0.7
  }'
```

### Gọi qua Python (thư viện `openai`):
```python
from openai import OpenAI

client = OpenAI(
    base_url="https://api.leluongnghia.net/v1",
    api_key="sk-mFC3NKeX0b9ZDgAeX"
)

response = client.chat.completions.create(
    model="claude-sonnet-4-6",
    messages=[
        {"role": "user", "content": "Tóm tắt xu hướng công nghệ năm nay."}
    ]
)

print(response.choices[0].message.content)
```
