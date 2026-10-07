# Amazon SES — Advanced Setup (Bounce, Complaint, Tracking, Speed)

> Settings → System Settings → **Email** tab → *Amazon SES Credentials* मधले optional fields:
> **Configuration Set**, **Max Send Rate**, **SNS Topic ARN**, **Bounce & Complaint Webhook**.

हे fields भरले नाहीत तरी email जातो. पण **bulk / marketing emails** पाठवताना हे नसतील तर AWS account
**pause किंवा बंद** होऊ शकतं, आणि emails spam मध्ये जायला लागतात. म्हणून live server वर हे setup **करायलाच हवं**.

---

## 1. आधी मूळ गोष्ट समजून घ्या — "Sender Reputation"

Gmail, Yahoo, Outlook आणि AWS स्वतः तुमच्या sending ला मार्क देतात. दोन आकडे सगळ्यात महत्त्वाचे:

| Metric | म्हणजे काय | AWS ची मर्यादा |
|---|---|---|
| **Bounce rate** | असा address ज्यावर email पोहोचलाच नाही (address अस्तित्वात नाही / बंद) | **5%** वर warning, **10%** वर account pause |
| **Complaint rate** | वाचणाऱ्याने email ला **"Report spam"** केलं | **0.1%** वर warning, **0.5%** वर account pause |

उदाहरण: 10,000 emails पाठवले आणि त्यातले 500 address चुकीचे निघाले, तर bounce rate 5% होतो आणि warning येते.
पुढच्या campaign मध्ये **त्याच 500 जणांना पुन्हा** email गेला, तर rate आणखी वाढतो आणि account pause होतं.

**उपाय:** ज्या address वर एकदा *hard bounce* किंवा *complaint* आली, त्याला **पुन्हा कधीच email पाठवायचा नाही** (suppression).
हे आपोआप व्हावं यासाठी **SNS Topic + Webhook** आहे.

---

## 2. चारही fields — काय, का, कसं

### 2.1 Bounce & Complaint Webhook (सगळ्यात महत्त्वाचं)

**काय:** App मधला URL — `https://<तुमचा-domain>/webhooks/ses/<tenant_key>`
(उदा. `https://app.example.com/webhooks/ses/swasthe_testing`). Settings screen वर तो Copy बटणासह दिसतो.

**कसं काम करतं (flow):**

```
App → SES → Gmail/Yahoo  ──(bounce / spam report)──►  SES
SES ──► SNS Topic ──(HTTPS POST)──► /webhooks/ses/<tenant>
App:  signature तपासतो → event वाचतो → address suppress करतो → activity log
```

**App आत काय करतं:**

| SES event | App काय करतं |
|---|---|
| `SubscriptionConfirmation` | AWS चा confirm link आपोआप उघडतो (हाताने confirm करायची गरज नाही) + activity log `ses_sns_subscribed` |
| **Bounce – Permanent** (hard) | Address **`email_unsubscribes`** मध्ये टाकतो → पुढे कोणत्याही campaign ला email जात नाही + event नोंद |
| Bounce – Transient (soft: mailbox full, server busy) | फक्त event नोंदवतो, **suppress करत नाही** (पुढच्या वेळी जाऊ शकतो) |
| **Complaint** (spam report) | Address लगेच suppress + event नोंद |
| Delivery | "Delivered" event नोंदवतो (reporting साठी) |

**सुरक्षा:**
- प्रत्येक message चा **AWS SNS signature** तपासला जातो (AWS च्या certificate ने). खोटा message आला तर 403.
- फक्त **एकाच SNS topic** कडून आलेले messages स्वीकारले जातात (पुढचा field पहा).

> ⚠️ **Localhost वर हे चालत नाही.** AWS ला तुमच्या computer चा `http://localhost/...` दिसत नाही.
> हा setup **live server (public HTTPS URL)** वरच करा.

### 2.2 SNS Topic ARN

**काय:** SNS topic चा पूर्ण पत्ता, उदा. `arn:aws:sns:us-east-1:123456789012:ses-bounces`.

**का:** Webhook URL public असतो. कोणीही तिथे request पाठवू शकतो. App फक्त **या topic** चे messages स्वीकारतो,
म्हणजे दुसरा कोणी (अगदी दुसरं AWS account सुद्धा) खोटे bounce पाठवून तुमचे contacts unsubscribe करू शकत नाही.

**कसं भरतं:** **हाताने भरायची गरज नाही.** Webhook वर पहिला valid message आला की त्याचा topic ARN आपोआप save होतो
("pin" होतो). त्यानंतर दुसऱ्या topic चे messages नाकारले जातात.

**कधी बदलायचा:** AWS मध्ये topic delete करून नवीन बनवला, तर हा field **रिकामा करा** आणि Save करा → नवीन topic आपोआप pin होईल.

### 2.3 Configuration Set (optional)

**काय:** AWS SES मधलं एक "sending profile". त्याला **event destinations** जोडता येतात (SNS, CloudWatch, Firehose…).

**App काय करतं:** हा field भरला की प्रत्येक email सोबत `ConfigurationSetName` आणि हे **tags** जातात:

| Tag | Value |
|---|---|
| `campaign` | campaign चं नाव |
| `campaign_id` | campaign id |
| `log_id` | app मधला email log id |
| `tenant` | client key (उदा. `swasthe_testing`) |

**फायदा:**
1. Bounce / complaint / delivery event मध्ये हे tags परत येतात, त्यामुळे app **कोणत्या campaign** मधून bounce आला ते जोडू शकतो.
2. AWS Console / CloudWatch मध्ये campaign-wise आणि client-wise आकडे दिसतात.
3. Configuration set ला dedicated IP pool, reputation metrics, तात्पुरतं sending बंद करणे अशा सोयी जोडता येतात.

> ⚠️ **AWS मध्ये set बनवण्याआधी हे नाव इथे लिहू नका.** अस्तित्वात नसलेलं नाव दिलं तर AWS **प्रत्येक email नाकारतो**.
> Settings screen वर दिसणारं `sateri-campaigns` हे फक्त placeholder (उदाहरण) आहे.

### 2.4 Max Send Rate (emails/sec)

**काय:** App एका सेकंदात जास्तीत जास्त किती emails पाठवेल.

**App कसं वागतं:**
- **रिकामा:** App AWS कडून account ची limit (`MaxSendRate`, सध्या **14/sec**) वाचतो आणि त्या वेगाने पाठवतो.
- **आकडा दिला:** त्या वेगाने पाठवतो.
- AWS ने **429 Throttling** किंवा 5xx error दिली, किंवा network error आली, तर app थोडं थांबून **3 वेळा** पुन्हा प्रयत्न करतो.

**काय भरायचं:**

| परिस्थिती | Value |
|---|---|
| ही AWS keys फक्त या app मध्ये (एक client) | **10** (शिफारस) |
| 2 clients / systems एकच AWS account वापरतात | **6** प्रत्येकी |
| 3 clients / systems | **4** प्रत्येकी |

10 ठेवल्यास 14 च्या limit मध्ये ~30% जागा मोकळी राहते (OTP, single emails, test emails साठी). वेग: 1,000 emails ≈ 2 मिनिटं.

---

## 3. Step-by-step Setup (Live server वर)

> पूर्व-अट: domain (उदा. `elintom.in`) **Settings → Email Settings** मध्ये *Verified* आहे, आणि app public HTTPS URL वर चालू आहे.

### सोपा मार्ग — One-click "Connect" (शिफारस)

Live https:// site वर **Settings → Email Settings → Bounce & Delivery Tracking → Connect** दाबा. App स्वतः:
SNS topic बनवतो (SES ला publish permission सह) → webhook subscribe करतो (confirmation आपोआप) → SES Configuration Set + SNS event destination (Delivery, Bounce, Complaint) बनवतो → ARN व Configuration Set settings मध्ये save करतो.
1 मिनिटाने **Check status** दाबा → **Connected** दिसायला हवं. मग खालचे Step 2–4 हाताने करायची गरज नाही.

यासाठी SES वापरणाऱ्या AWS IAM user ला ही permission लागते (IAM → Users → user → Add permissions → Create inline policy → JSON):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "sns:CreateTopic",
        "sns:Subscribe",
        "sns:SetTopicAttributes",
        "sns:ListSubscriptionsByTopic",
        "ses:CreateConfigurationSet",
        "ses:CreateConfigurationSetEventDestination",
        "ses:UpdateConfigurationSetEventDestination"
      ],
      "Resource": "*"
    }
  ]
}
```

Permission नसेल तर Connect दाबल्यावर नेमकी कोणती permission हवी ते message मध्ये दिसेल.

### Step 1 — Webhook URL copy करा
1. App → **Settings → System Settings → Email** tab → Amazon SES Credentials.
2. **Bounce & Complaint Webhook** चा URL **Copy** करा.
3. तो `https://` ने सुरू होतो आणि **localhost नाही** याची खात्री करा.

### Step 2 — SNS Topic बनवा
1. AWS Console → **SNS** → *Topics* → **Create topic**.
2. **Region तोच निवडा जो SES चा आहे** (उदा. `us-east-1`).
3. Type: **Standard** (FIFO नाही).
4. Name: उदा. `ses-feedback-<client>` (उदा. `ses-feedback-swasthe`) → **Create topic**.
5. Topic चा **ARN** नोंदवून ठेवा (field आपोआप भरेल, पण तपासण्यासाठी लागेल).

### Step 3 — HTTPS Subscription बनवा
1. त्याच topic मध्ये → **Create subscription**.
2. Protocol: **HTTPS**.
3. Endpoint: Step 1 मध्ये copy केलेला webhook URL.
4. **"Enable raw message delivery" OFF ठेवा** (बंद). App ला SNS चं पूर्ण envelope (signature सह) लागतं.
5. **Create subscription**.
6. काही सेकंदात status **Confirmed** व्हायला हवा. App हे आपोआप confirm करतो.
   - App मध्ये **Activity Logs** मध्ये *"Amazon SES bounce/complaint notifications connected"* दिसेल.
   - Settings मध्ये **SNS Topic ARN** field आपोआप भरलेला दिसेल.
   - *Pending confirmation* राहिलं तर **Troubleshooting** section पहा.

### Step 4 — SES ला सांगा की bounce/complaint या topic वर पाठव

**खालीलपैकी एकच पर्याय निवडा** (दोन्ही केल्यास प्रत्येक event दोनदा येईल).

**पर्याय A — सोपा (Identity notifications)** — Configuration Set वापरत नसाल तर:
1. AWS Console → **SES** → *Identities* → `elintom.in`.
2. **Notifications** tab → *Feedback notifications* → **Edit**.
3. **Bounce feedback** → तुमचा SNS topic. **Complaint feedback** → तोच topic.
   (*Delivery* हवं असल्यास तोही — पण bulk मध्ये खूप messages येतील.)
4. **Include original email headers**: ऐच्छिक (OFF चालेल).
5. Save.
6. त्याच page वर **Email feedback forwarding** → **Disable** करा (नाहीतर bounce चे emails From address च्या inbox मध्ये भरतील).

**पर्याय B — Advanced (Configuration Set + Event destination)** — campaign-wise tracking हवं असल्यास:
1. AWS Console → **SES** → *Configuration sets* → **Create set**. Name: उदा. `sateri-campaigns` → Create.
2. त्या set मध्ये → **Event destinations** → **Add destination**.
3. Event types: **Hard bounces, Complaints, Deliveries** (हवं तर Rejects, Delivery delays).
   > Opens / Clicks इथे चालू करू नका. App स्वतःचं open/click tracking करतो, त्यामुळे दोन्ही चालू केल्यास links दोनदा बदलतात.
4. Destination type: **Amazon SNS** → Step 2 चा topic → Save.
5. App मध्ये → Settings → Email → **Configuration Set** = `sateri-campaigns` (अगदी तसंच नाव) → **Save**.
6. (ऐच्छिक) Identity वर Configuration Set default म्हणून जोडता येतो: SES → Identities → `elintom.in` → *Configuration set* → Assign.

### Step 5 — Max Send Rate भरा
Settings → Email → **Max Send Rate** = `10` → Save. (तक्ता section 2.4 मध्ये.)

### Step 6 — Test (AWS Mailbox Simulator)
AWS चे खास test addresses आहेत. यांना पाठवल्यास reputation वर परिणाम होत नाही:

| पाठवा या address वर | अपेक्षित परिणाम |
|---|---|
| `success@simulator.amazonses.com` | Delivered (Delivery event चालू असल्यास नोंद) |
| `bounce@simulator.amazonses.com` | **Hard bounce** → app मध्ये address suppress |
| `complaint@simulator.amazonses.com` | **Complaint** → app मध्ये address suppress |

कसं:
1. App → **Emails → Single Email** → To: `bounce@simulator.amazonses.com` → Send.
2. 1–2 मिनिटं थांबा.
3. तपासा:
   - **Activity Logs** → `email_suppressed` — *Suppressed bounce@simulator.amazonses.com (SES hard bounce …)*
   - DB मध्ये `email_unsubscribes` table मध्ये तो address (reason: *SES hard bounce …*).
4. `complaint@simulator.amazonses.com` साठी असंच करा.
5. पुन्हा त्याच address ला campaign पाठवून पहा → तो **skip** व्हायला हवा.

---

## 4. Troubleshooting

| लक्षण | कारण / उपाय |
|---|---|
| Subscription **Pending confirmation** राहतो | URL public नाही / HTTPS certificate invalid / firewall. Browser मधून URL उघडून पहा (GET वर 404/405 येणं ठीक आहे — फक्त server पोहोचायला हवा). SNS मध्ये **Request confirmation** पुन्हा दाबा. |
| Webhook वर **403 Invalid SNS signature** (app log) | *Raw message delivery* चालू आहे → OFF करा. किंवा server ला AWS certificate download करता येत नाही (outbound HTTPS / CA bundle तपासा). |
| **403 Unexpected SNS topic** | Settings मधला **SNS Topic ARN** दुसऱ्या topic चा आहे. Field रिकामा करा → Save → SNS मध्ये पुन्हा confirmation पाठवा. |
| सगळे emails fail: *Configuration set does not exist* | Configuration Set चं नाव चुकीचं आहे किंवा तो दुसऱ्या region मध्ये आहे. नाव दुरुस्त करा किंवा field रिकामा करा. |
| Bulk sending मध्ये *Throttling / Maximum sending rate exceeded* | Max Send Rate कमी करा (उदा. 10 → 6). दुसरी system त्याच AWS account मधून पाठवत आहे का ते तपासा. |
| प्रत्येक bounce दोनदा नोंदला जातो | Step 4 मध्ये पर्याय A आणि B दोन्ही चालू आहेत. एकच ठेवा. |
| Bounce emails From inbox मध्ये येतात | SES Identity → Notifications → **Email feedback forwarding: Disable**. |

---

## 5. Multi-client (tenant) टीप

- Webhook URL मध्ये शेवटी **tenant key** असतो (`/webhooks/ses/swasthe_testing`). App त्यावरूनच ठरवतो की कोणत्या client च्या DB मध्ये suppress करायचं.
- **प्रत्येक client साठी स्वतंत्र SNS topic + subscription** बनवा (त्या client चा URL वापरून). एकाच topic ला दोन clients चे URL जोडू नका.
- अनेक clients एकच AWS account वापरत असतील तर Max Send Rate ची 14/sec ची limit त्यांच्यात वाटून घ्या (section 2.4).

---

## 6. Live जाण्यापूर्वी Checklist

- [ ] Domain **Verified** (Settings → Email Settings) + DKIM SUCCESS
- [ ] MAIL FROM (`bounce.<domain>`) चे MX + SPF records टाकले, status SUCCESS
- [ ] DMARC record टाकला (`_dmarc.<domain>`)
- [ ] SES account **Production** मध्ये (sandbox नाही) — Settings → Email tab मध्ये **Test Email Provider** दाबल्यावर येणारा notice तपासा
- [ ] SNS topic + HTTPS subscription **Confirmed**
- [ ] SES Identity / Configuration set → Bounce + Complaint → तो topic
- [ ] Settings मध्ये **SNS Topic ARN** आपोआप भरलेला दिसतो
- [ ] Email feedback forwarding **Disabled**
- [ ] Max Send Rate = **10** (किंवा तक्त्यानुसार)
- [ ] Mailbox simulator ने bounce + complaint test केलं, दोन्ही suppress झाले
- [ ] Configuration Set वापरत असाल तर नाव AWS मधल्या नावाशी जुळतं
