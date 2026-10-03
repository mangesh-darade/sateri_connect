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
    "phone": "+919876543210",
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
      "phone": "+919876543210",
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
    "to": "+919876543210",
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
      "to": "919876543210",
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
    "to": "+919876543210",
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
      "to": "+919876543210",
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
      "to": "+919876543210",
      "status": "read",
      "sent_at": "2026-10-03 07:15:00",
      "delivered_at": "2026-10-03 07:15:02",
      "read_at": "2026-10-03 07:15:10"
    }
  }
  ```

---

### 3.5 Trigger Automation Workflow / Webhook
Trigger automated multi-step flows built in the Sateri Connect Visual Flow Builder using custom events (e.g. `order_placed`, `lead_received`, `payment_success`, `appointment_booked`).

* **Endpoint:** `POST /api/v1/automations/trigger`
* **Request Body:**
  ```json
  {
    "event": "order_placed",
    "phone": "+919876543210",
    "name": "Mangesh",
    "data": {
      "order_number": "ORD-12345",
      "amount": 1999,
      "item": "Premium Subscription"
    }
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Automation event triggered successfully.",
    "data": {
      "event": "order_placed",
      "contact_id": 1042,
      "matched": 1,
      "executed": 1
    }
  }
  ```

---

### 3.6 Search Customer Contacts
* **Endpoint:** `GET /api/v1/contacts/search?q=Mangesh&page=1&per_page=25`
* **cURL Example:**
  ```bash
  curl -X GET "https://your-domain.com/api/v1/contacts/search?q=9876543210" \
    -H "X-API-Key: sc_live_YOUR_KEY"
  ```

---

## 4. Inbound Webhooks

Configure your receiving server URL under **Settings &rarr; Webhooks** to get real-time JSON pushes whenever a customer messages your WhatsApp business number:

```json
{
  "event": "message_received",
  "contact": {
    "id": 1042,
    "phone": "+919876543210",
    "name": "Mangesh Darade"
  },
  "message": {
    "wamid": "wamid.HBgLMOTE5ODc...",
    "type": "text",
    "text": "Can I get pricing for the enterprise plan?",
    "timestamp": 1727938500
  }
}
```

---

## 5. Interactive Web UI
Visit `/api/docs` in your browser for the full interactive developer reference with one-click copy buttons and live schemas.
