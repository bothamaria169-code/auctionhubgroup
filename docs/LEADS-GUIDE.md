# Leads, enquiry forms & message templates — guide

Every person who gets in touch now lands in **one place**: Admin → **Leads**. One card per person, with Call, WhatsApp, Email and Log-call buttons, a call script, and ready-made messages you can reword.

Ideas taken from **Easy Auctions** (one row per person, email → call progress, call scripts, WhatsApp templates) and **BidCo** (a "waiting on you" list on the dashboard), rebuilt for AHG's plain-PHP code.

| Area | Where |
|---|---|
| Enquiry form (Contact page + every vehicle page) | `enquiry_form.php` → posts to `enquiry.php` |
| Leads page | `admin_leads_tab.php` (Admin → Leads) |
| Templates page | `admin_templates_tab.php` (Admin → Email & WA Templates) |
| All the logic (sources, grouping, templates, scripts) | `leads_helper.php` |
| Admin actions (save, send, export) | `leads_api.php` |
| New tables `enquiries`, `lead_status`, `lead_messages` | `db_schema.php` — they create themselves |

> **Where-badges:** 🟦 **ACCOUNT** (outside websites) · 🟩 **ADMIN** (our admin panel) · 🟧 **CPANEL** (hosting)

---

## 1. Deploy it (5 min)

1. 🟧 **CPANEL** → **Git Version Control** → **Manage** (auctionhubgroup) → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**.
2. No SQL to run. The three new tables are made the first time the admin or an enquiry form opens.

**Test:** open `https://auctionhubgroup.co.za/contact.php` — you see a **Send us an enquiry** form.

## 2. Set your name for the messages (1 min)

1. 🟩 **ADMIN** → **Email & WA Templates** → **Agent name** → type the name customers should see (e.g. *Thandi*) → **Save**.
2. Without it, messages use the first name of whoever is logged in.

**Test:** Admin → **Leads** → any card → **WhatsApp** — the message says "This is *your name* from Auction Hub Group".

## 3. Working your leads every day (10 min a day)

1. 🟩 **ADMIN** → **Overview** — the yellow **Waiting on you** bar shows leads to contact, proofs of payment, banking requests and refunds. Tap one to go straight there.
2. 🟩 **ADMIN** → **Leads**. The **To do** filter is on. Red edge = **New**, orange = **Contacted**, blue = **Called**, green = **Won**.
3. On each card:
   - **WhatsApp** → the right message is already chosen and filled in → edit if you like → **Open WhatsApp** → press send in WhatsApp. The card moves to **Contacted**.
   - **Email** → template chosen → **Preview** to see it in the AHG letterhead → **Send email**. The card moves to **Contacted**.
   - **Call** → your phone dials; the **Log call** box opens → pick **How did the call go?**, add notes, a **Call back on** date → **Save**.
   - **Call script** → tap to open what to say for this kind of lead.
4. **Call back today** shows up as its own filter when a call-back date arrives.
5. **Export CSV** downloads everyone in the chosen period (opens in Excel).

> One person who signed up, asked for banking details *and* sent an enquiry is **one card** — matched on email, or on the last 9 digits of the phone number.

**Test:** send yourself an enquiry from a vehicle page on your phone → it appears at the top of **Leads** with a **NEW** badge, and admin@ gets an email alert.

## 4. Rewording templates (5 min)

1. 🟩 **ADMIN** → **Email & WA Templates** → **Emails** or **WhatsApp** → open one.
2. Change the words → **Save**. **Reset to original** puts the first wording back.
3. Words in `{curly brackets}` are filled in per person — open **Placeholders you can use** for the list. `{bank_details}` becomes the banking table in emails and bold lines on WhatsApp (from Admin → **Banking Details**).

**Test:** **Preview** shows the email in the letterhead with a sample person.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Form says "Sorry, we could not save your enquiry" | The database user can't create tables. 🟧 **CPANEL** → **phpMyAdmin** → run the three `CREATE TABLE` blocks for `enquiries`, `lead_status`, `lead_messages` from `db_schema.php`. |
| "Email sending is switched off on this server" | That server has `AHG_EMAIL_OFF` set (normal on a local PC). Live sends normally. |
| A WhatsApp button is missing | The person has no usable phone number. Use **Email** → **Ask for a phone number**. |
| Same person shows twice | They used a different email *and* a different phone number. |
