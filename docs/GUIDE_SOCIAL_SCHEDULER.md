# Social Scheduler Guide

Plan weeks of posts once and let the platform publish them on time to Facebook, Instagram, YouTube, LinkedIn and WhatsApp.

Where things are in the menu:

- **Social → Social Scheduler**: Calendar, Media Library, Accounts.
- **Settings → Social Setup**: app credentials, quick connect with a token, and the publishing status.
- **Settings → Activity Logs**: who scheduled, changed or deleted what.

## 1. What you can post where

| Platform | Photo | Video / Reel | Text only |
|---|---|---|---|
| Facebook Page | Yes | Yes (video or Reel) | Yes |
| Instagram | Yes | Yes (Reel) | No |
| YouTube | No | Yes (a vertical video under 3 minutes becomes a Short) | No |
| LinkedIn (your profile) | Yes | Yes | Yes |
| WhatsApp | Broadcast | Broadcast | Broadcast |

WhatsApp does not allow posting a Status through an official API. The scheduler sends a **broadcast**: an approved template to a customer group at the time you choose.

## 2. One-time setup (administrator)

1. Run the database update: `php spark migrate`, then `php spark db:seed PermissionSeeder`.
2. Open **Settings → Social Setup** and fill in the section for each platform you want. You do not need to edit `.env` (values already in `.env` are picked up and shown as ready).

| To connect | Enter on the Setup page |
|---|---|
| Facebook and Instagram | Meta App ID and App Secret |
| YouTube | Google OAuth Client ID and Client Secret |
| LinkedIn | LinkedIn Client ID and Client Secret |
| Google Drive media | Google service account key (the whole JSON file) |

3. Copy the **redirect URL** shown in each section into that platform's developer console (it ends with `/facebook`, `/youtube` or `/linkedin`).
4. Press **Test** (Facebook/Instagram and Google Drive) and then **Save setup**. The checklist on the left turns green.
5. For Google Drive, also enable the **Google Drive API** in the same Google Cloud project.

A platform whose credentials are missing simply has no Connect button on the Accounts page.

### When are posts published?

- **Automatically while the app is open.** Any signed-in user with the app open triggers a check every minute, and due posts are published in the background.
- **Without anyone online (recommended on a live server).** Add a cron job that runs every minute: `php spark social:publish`. The Setup page shows the exact command with a Copy button.

### Public web address

- Facebook photos and videos are uploaded directly, so they also work on a local or test site.
- **Instagram** and **Facebook Reels** fetch the file from your site, so `app.baseURL` must be a public **https** address. On a local site, use a tunnel such as ngrok or cloudflared, or go live first.

## 3. Connect your accounts

Open **Social Scheduler → Accounts**.

- **Connect Facebook & Instagram**: log in with Facebook and choose your Pages. An Instagram Business or Creator account linked to a Page connects automatically. A personal Instagram account will not work.
- **Connect YouTube**: log in with Google and pick your channel.
- **Connect LinkedIn**: log in. Posts go to your personal profile.
- **WhatsApp**: nothing to connect. It appears as "WhatsApp broadcast" when you schedule.

You can disconnect an account once it has no scheduled posts.

### Connect with a token (testing)

On **Settings → Social Setup**, the **Connect with a token** card lets you connect a Facebook Page with a Page ID and Page access token, or YouTube with a refresh token. Use the Connect buttons for regular use, because they keep the connection renewed.

A Facebook Page token needs the permissions `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `instagram_basic` and `instagram_content_publish`. A token made in Graph API Explorer expires quickly unless you extend it. If publishing says the account "does not have permission", the token is missing `pages_manage_posts`, or your Facebook user does not have full control of the Page.

## 4. Schedule 20 days of media posts

1. Create a Google Drive folder and put your videos and photos in it.
2. Share the folder (Viewer access) with the service account email shown on **Media Library** and on **Settings → Social Setup**.
3. Open **Social Scheduler → Media Library**, paste the folder link and press **Add folder**. If it says no files were found, the folder is usually not shared with that email yet.
4. Check the tips next to each file. "Reel ready" is green. Yellow tips tell you what to fix, for example the video is not vertical 9:16 or is longer than 90 seconds.
5. Tick the files (the top checkbox selects all) and press **Schedule selected**.
6. Fill in the dialog:
   - **Post to**: one or more accounts. YouTube needs videos only, so photos cannot be scheduled to it.
   - **Start date** and **Posting times**: `10:00` means one post a day, `10:00, 18:00` means two a day. 20 files at one time a day fill 20 days. Times are in your account's time zone.
   - **Caption**: write your own, or use **AI caption helper** (describe the post, choose platform and tone, press Suggest, click an option). `{title}` becomes the file name.
   - **Hashtags**.
7. Press **Schedule**. Your posts appear on the calendar.

New files added to the folder later show up after **Scan for new files**.

## 5. Post text without media

On **Social Scheduler → Calendar** press **New text post**. Choose accounts (Facebook, LinkedIn, WhatsApp), the date and time, and write the text. Instagram and YouTube are listed but greyed out, because they need a photo or video: use the Media Library for those.

## 6. WhatsApp broadcast

Tick **WhatsApp broadcast** in either dialog and choose:

- an **approved template**,
- the **customer groups** to send to, or all contacts,
- optionally, use the post text as template variable {{1}}.

At the scheduled time a normal campaign starts. The usual rules still apply: approved template, opted-in contacts, STOP requests respected, a WhatsApp rate set for each recipient's country, and wallet balance. A failed broadcast is not retried automatically, because that could send it twice.

## 7. Manage the calendar

Open **Social Scheduler → Calendar**. Colours: blue scheduled, orange publishing, green published, red failed, grey cancelled. The calendar updates by itself, with no page refresh.

- While a post is publishing, its chip shows a percentage. Open it to see the progress bar and the step: downloading from Google Drive, then uploading to the platform. YouTube shows real upload progress; Facebook and Instagram show the step only.
- A toast tells you when a post is published or failed.

Click any post to:

- change its time and press **Save new time**,
- **Cancel post**,
- **Retry now** if it failed (the reason is shown),
- open the live post once published,
- **Delete from calendar**: removes it from the calendar only. A published post stays live on the platform.
- **Delete from YouTube too** / **Delete from Facebook too**: deletes the live video or post and the calendar entry. This cannot be undone. If the platform refuses, nothing is removed and the reason is shown.

Deleting a YouTube video needs the permission to manage videos. A YouTube connection made before this was added only has upload permission: reconnect YouTube on the Accounts page once, or delete the video in YouTube Studio.

Instagram and LinkedIn posts cannot be deleted from here, because those platforms do not allow it. Delete them in the app itself.

## 8. Rules and limits

- The same file cannot be scheduled twice.
- Times in the past are rejected.
- At most 5 posts per account per day, and 100 files per batch.
- A temporary failure (for example a rate limit) is tried again up to 3 times. Other failures are shown on the post so you can fix the cause and press Retry now.
- YouTube allows only a limited number of uploads a day for the whole platform. If the limit is reached the post fails with a clear message; press Retry the next day.
- LinkedIn logins last about 60 days. When one expires, the account shows "expired"; reconnect it.
- Do not use the scheduler for fake engagement or spam. Platforms can restrict accounts that do.

## 9. Troubleshooting

| Problem | What to check |
|---|---|
| No Connect button | The platform credentials are not saved on **Settings → Social Setup** |
| "Folder not found", or a folder with no files | The Drive folder is not shared (Viewer) with the service account email |
| Instagram post failed: could not fetch the photo or video | `app.baseURL` must be a public https address (not localhost); the account must be Business/Creator |
| "The Facebook / Instagram access token is invalid or expired" | Reconnect the account, or paste a new long-lived Page token on Setup |
| "This account does not have permission to publish" | The token lacks `pages_manage_posts`, or your Facebook user does not have full control of the Page |
| "YouTube needs videos" | YouTube takes video only. Untick it, or choose videos |
| Post stays "scheduled" | Someone must have the app open, or the `social:publish` cron job must be running every minute |
| Post shows "publishing" for a long time | It is probably a large file. After 15 minutes it is marked failed with "interrupted"; press Retry now |
| "Account login expired" | Reconnect the account on the Accounts page |
| WhatsApp broadcast failed | Template not approved, no opted-in contacts, no WhatsApp rate for a recipient's country, or low wallet balance |

## 10. AI captions

In **Media Library → Schedule selected** the AI can write the captions for you.

1. Choose the AI on **Settings → Social Setup → AI captions**: **Gemini** (uses the key in Settings → AI), **Claude** or **OpenAI** (paste that provider's API key). Press **Save setup**, then **Test**.
2. In the schedule dialog tick the accounts, choose the **language** (English, Hindi, Marathi or Hinglish) and the **tone**. A line about what the posts are about is optional.
3. Press **Write a caption for every file**. The AI looks at each file's preview picture (for a video, one frame) and writes one caption with hashtags for every platform you ticked, following that platform's rules. This takes a few seconds per file.
4. Read and edit the captions in the list, then press **Schedule**. Each file is posted with its own caption on each platform. A WhatsApp broadcast uses the shared caption.

If you leave the caption empty and press **Schedule**, the AI writes the captions first and shows them to you. Nothing is posted until you press **Schedule** again.

Notes: each file is one small request to the AI you chose, so 20 files means 20 requests and a small cost on that provider. The AI is told not to invent facts, prices or fake urgency, but always read the text before scheduling.
