# Sateri Connect REST API Integration Guide (v1)

Welcome to the **Sateri Connect REST API** reference. Use this API to integrate your CRM, E-commerce store (WooCommerce, Shopify), Lead generation landing pages, Billing software, or custom applications directly with WhatsApp Cloud API automation.

---

## 1. Authentication

Every API request requires an API Key. You can generate permanent Secret API Keys directly inside your Sateri Connect dashboard under **Settings &rarr; Developer API Keys**.

Pass your key in the request headers:

```http
X-API-Key: sc_live_your_secret_api_key_here
```

*Alternatively, Bearer Authorization is supported:*
```http
Authorization: Bearer sc_live_your_secret_api_key_here
```

### Base URL
```text
https://your-domain.com/api/v1
```
*(All endpoints return machine-readable JSON)*

---

## 2. API Response Standard

All responses adhere to the standard JSON structure:

```json
{
  "status": "success",
  "message": "Human readable summary",
  "data": { ... }
}
```

### HTTP Status Codes:
- `200 OK` — Request succeeded.
- `201 Created` — Resource (e.g. contact, message) created.
- `400 Bad Request` — Invalid input or business logic exception.
- `401 Unauthorized` — Missing or invalid API Key / JWT.
- `404 Not Found` — Contact or message not found.
- `422 Unprocessable Entity` — Validation error or 24-hour service window policy restriction.
- `500 Internal Error` — Server-side error.

---

## 3. Endpoints Reference

### 3.1 Upsert Customer Contact
Create a new customer or update an existing customer by phone number. Automatically attaches tags and custom attributes, and triggers contact-related automations.

* **Endpoint:** `POST /api/v1/contacts/upsert`
* **Headers:**
  ```http
  Content-Type: application/json
  X-API-Key: sc_live_YOUR_KEY
  ```
* **Request Body:**
  ```json
  {
    "phone": "+917744010738",
    "name": "Mangesh Darade",
    "email": "mangesh@example.com",
    "tags": ["VIP Customer", "Website Lead"],
    "custom_attributes": {
      "city": "Pune",
      "preferred_language": "Marathi",
      "order_count": "5"
    }
  }
  ```
* **cURL Example:**
  ```bash
  curl -X POST "https://your-domain.com/api/v1/contacts/upsert" \
    -H "X-API-Key: sc_live_YOUR_KEY" \
    -H "Content-Type: application/json" \
    -d '{
      "phone": "+917744010738",
      "name": "Mangesh Darade",
      "email": "mangesh@example.com",
      "tags": ["VIP Customer"]
    }'
  ```

---

### 3.2 Send Direct WhatsApp Text Message
Sends a direct text message to a customer.
> **Note:** Direct text messages can only be sent within the **24-hour customer service window** (after the customer sent a message). If outside 24 hours, use an approved template (Endpoint 3.3).

* **Endpoint:** `POST /api/v1/messages/send-text`
* **Request Body:**
  ```json
  {
    "to": "+917744010738",
    "text": "Hello Mangesh! Your order #ORD-9988 has been dispatched."
  }
  ```
* **Success Response (201 Created):**
  ```json
  {
    "status": "success",
    "message": "Message sent successfully.",
    "data": {
      "message_id": 849,
      "wamid": "wamid.HBgLMOTE5ODc2NTQzMjEwFQIAEhgWM0VCNTA...",
      "to": "917744010738",
      "status": "sent"
    }
  }
  ```

---

### 3.3 Send Approved WhatsApp Template Message
Send an officially approved Meta WhatsApp template to initiate a conversation or deliver transactional notifications (Order confirmation, OTP, Shipping, Abandoned Cart). Can be sent **anytime**, even outside the 24-hour window.

* **Endpoint:** `POST /api/v1/messages/send-template`
* **Request Body:**
  ```json
  {
    "to": "+917744010738",
    "template_name": "order_confirmation",
    "language": "en_US",
    "variables": ["Mangesh", "ORD-9988", "Rs. 1,499"]
  }
  ```
* **Optional Media Header:** If your template has an Image or PDF header, add:
  ```json
  "header_media_url": "https://example.com/receipt.pdf"
  ```
* **cURL Example:**
  ```bash
  curl -X POST "https://your-domain.com/api/v1/messages/send-template" \
    -H "X-API-Key: sc_live_YOUR_KEY" \
    -H "Content-Type: application/json" \
    -d '{
      "to": "+917744010738",
      "template_name": "order_confirmation",
      "language": "en_US",
      "variables": ["Mangesh", "ORD-9988", "1499"]
    }'
  ```

---

### 3.4 Check Message Delivery Status
Check whether a WhatsApp message was sent, delivered, or read by the customer.

* **Endpoint:** `GET /api/v1/messages/{message_id_or_wamid}/status`
* **cURL Example:**
  ```bash
  curl -X GET "https://your-domain.com/api/v1/messages/849/status" \
    -H "X-API-Key: sc_live_YOUR_KEY"
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Message status retrieved.",
    "data": {
      "message_id": 849,
      "wamid": "wamid.HBgLMOTE5ODc2NTQzMjEwFQIA...",
      "to": "+917744010738",
      "status": "read",
      "sent_at": "2026-10-03 07:15:00",
      "delivered_at": "2026-10-03 07:15:02",
      "read_at": "2026-10-03 07:15:10"
    }
  }
  ```

---
### 3.4 Send WhatsApp Media (PDF Invoice, Image, Video, Audio)
Send document invoices, receipts, brochures, or photos directly to customers.

* **Endpoint:** `POST /api/v1/messages/send-media`
* **Request Body:**
  ```json
  {
    "to": "+917744010738",
    "type": "document",
    "url": "https://your-domain.com/invoices/INV-9988.pdf",
    "caption": "Your monthly tax invoice statement.",
    "filename": "invoice_INV9988.pdf"
  }
  ```
* **Supported Media Types:** `document` (PDF, Excel, Doc), `image` (JPEG, PNG, WebP), `video` (MP4), `audio` (AAC, MP3, OGG).
* **cURL Example:**
  ```bash
  curl -X POST "https://your-domain.com/api/v1/messages/send-media" \
    -H "X-API-Key: sc_live_YOUR_KEY" \
    -H "Content-Type: application/json" \
    -d '{
      "to": "+917744010738",
      "type": "document",
      "url": "https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf",
      "caption": "Your tax invoice #INV-2026-001",
      "filename": "invoice_001.pdf"
    }'
  ```

---

### 3.5 Check Message Delivery Status
Check whether a WhatsApp message was sent, delivered, or read by the customer.

* **Endpoint:** `GET /api/v1/messages/{message_id_or_wamid}/status`
* **cURL Example:**
  ```bash
  curl -X GET "https://your-domain.com/api/v1/messages/849/status" \
    -H "X-API-Key: sc_live_YOUR_KEY"
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Message status retrieved.",
    "data": {
      "message_id": 849,
      "wamid": "wamid.HBgLMOTE5ODc2NTQzMjEwFQIA...",
      "to": "+917744010738",
      "status": "read",
      "sent_at": "2026-10-03 07:15:00",
      "delivered_at": "2026-10-03 07:15:02",
      "read_at": "2026-10-03 07:15:10"
    }
  }
  ```

---

### 3.6 Discover & List WhatsApp Templates
Discover all active Meta WhatsApp templates, their categories, language, and required parameters schema.

* **Endpoint:** `GET /api/v1/templates?status=APPROVED&page=1&per_page=25`
* **cURL Example:**
  ```bash
  curl -X GET "https://your-domain.com/api/v1/templates?status=APPROVED" \
    -H "X-API-Key: sc_live_YOUR_KEY"
  ```
* **Inspect Specific Template:**
  ```bash
  curl -X GET "https://your-domain.com/api/v1/templates/order_confirmation" \
    -H "X-API-Key: sc_live_YOUR_KEY"
  ```

---

### 3.7 Trigger Automation Workflow / Webhook
Trigger automated multi-step flows built in the Sateri Connect Visual Flow Builder using custom events (e.g. `order_placed`, `lead_received`, `payment_success`, `appointment_booked`).

* **Endpoint:** `POST /api/v1/automations/trigger`
* **Request Body:**
  ```json
  {
    "event": "order_placed",
    "phone": "+917744010738",
    "name": "Mangesh",
    "data": {
      "order_number": "ORD-12345",
      "amount": 1999,
      "item": "Premium Subscription"
    }
  }
  ```

---

### 3.8 Account & WABA Health Check
Verify API key permissions, connected WhatsApp phone number ID, and system operational status.

* **Account Overview:** `GET /api/v1/account`
* **Liveness Ping:** `GET /api/v1/health`

---

## 4. Rate Limiting & Throttling
To protect server resources and prevent accidental abuse, the API enforces a rate limit:
* **Default Limit:** **60 requests per minute** per API Key & IP.
* **Header:** Responses exceeding the limit return `HTTP 429 Too Many Requests` with a `Retry-After: 60` header.

---

## 5. Postman Collection
Download the official pre-configured Postman Collection with real sample data:
* **Download Endpoint:** `GET /api/v1/postman`
* Includes all endpoints, dynamic variables `{{base_url}}` & `{{api_key}}`.

---

## 6. Interactive Web UI
Visit `/api/docs` in your browser for the full interactive developer reference with live testing console, copyable cURL snippets, and real-time response latency viewer.
