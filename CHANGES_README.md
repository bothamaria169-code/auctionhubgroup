# Auction Hub Group: changes, open items and design brief

Handover for the next Claude Code session on **bothamaria169-code/auctionhubgroup**.
Covers everything changed on branch `fix/no-dekra-report-by-message` (Nov 2026), the business
rules behind it, what is still open, and a ready-to-use design brief for the site and the home
page hero.

---

## 1. Where things stand

| Item | Status |
|---|---|
| Branch | `fix/no-dekra-report-by-message`, pushed to GitHub |
| Live site | Deploys from **`main`** via cPanel (`.cpanel.yml` → `deploy.sh`). `main` is still at `4e6923b` (real 404 page). **None of the work below is live yet.** |
| To go live | Merge the branch into `main`, then deploy in cPanel (Git Version Control → Update from Remote → Deploy HEAD Commit). See `DEPLOY_CHECKLIST.md`. |
| Database | New columns are added automatically on first use by `db_schema.php` (`ahgSchemaReady()`). No manual migration. |

**First thing to do in the new session:** `git checkout fix/no-dekra-report-by-message && git pull`.
Ask the owner before merging to `main`; merging puts everything live.

---

## 2. Business rules (the source of truth for all wording)

Use these exact rules in any page, email, PDF or WhatsApp text. Do not invent new figures.

**DEKRA reports**
- The full DEKRA report is **never** sent by WhatsApp or email. It is shown at the premises, where the
  client checks it against the actual vehicle with a team member. (Reason: copies get edited and reused
  by scammers.) Constants: `DEKRA_REPORT_POLICY`, `DEKRA_REPORT_LAW` in `config.php`.
- VINs are not published; a link explains why (`vin-policy.php`).

**Bank question**
- Every client form asks which bank the client uses: bidder registration (both sign-up forms),
  enquiries, finance and pre-auction. One shared list: `CLIENT_BANKS` and `clientBankOptions()` in
  `config.php` (`FIN_BANKS` now points to it).

**Bidder registration**
- Refundable **R10,000** registration deposit. Deducted from the price if the client wins; otherwise
  refunded to the paying account less **R102.00** admin and bank charges.
- Bank details are only sent after the client requests them in the dashboard and admin approves.
- Admin-created ("floor") bidders are already verified and get a bidder number `AHG-YYYY-NNN`.
  Their bids show **only name and bidder number**; nothing shows that the office bid for them.

**Who qualifies for pre-auction and finance** (`finPrice()` in `finance_helper.php`)
- **Coming soon** (status `upcoming`): always qualifies.
- **Live** (status `active`): only while it has **no bids** and the auction has not ended.
- Sold vehicles, closed auctions and vehicles already Presold through an application never qualify.
- Admin decides which vehicles are live and which are coming soon (Auctions → Split Live Stock).

**Pre-auction**
- Client offers a commitment-fee percentage between **30% and 50%**. Usual figures:
  **50%** up to R100,000; **40%** R100,000–R150,000; **30%** above R150,000. The office approves or
  sets the final percentage on the invoice (settings `pa_min_percent`, `pa_max_percent`).
- Flow: enquiry → **commitment-fee letter** (email + PDF) → client taps **Confirm and request my
  invoice** → office issues proforma invoice (valid **24 hours**) → payment verified → **Buyer Code**
  → **1 h 30 min** test drive and inspection at the premises → client **signs off the final
  decision** (pay balance, switch to alternative vehicle, or cancel for a refund less R102.00).
- No invoice can be issued until the client has confirmed the letter (`paIssueInvoice()` checks
  `paTermsConfirmed()`).
- Vehicles are sold voetstoots and only while advertised.

**Finance**
- Terms **6 to 72 months**. The quote is a 3-page document (terms table, deposit points and
  documents, chosen term and company details). The client chooses the term on their application
  page, then taps **Request my invoice**.
- Paying the deposit does not commit the client to buy; approval is subject to credit assessment.

**After winning an auction** (on the winning email, before the invoice)
1. Receive confirmation that you have won the bid.
2. Receive your sales order / invoice by email.
3. Check the vehicle price and any additional charges.
4. Make full payment by EFT within **one business day** of receiving the invoice.
5. Send us proof of payment.
6. Wait for payment clearance and verification.
7. Receive your vehicle Release Note.
8. Arrange vehicle collection within **72 hours** of the Release Note.
9. Present your ID and sign the delivery note.
10. Complete the ownership-transfer process and receive your registration documents.

**Company details** (in every email footer and on every client document, so clients can verify us)
- Auction Hub Auctioneers (Pty) Ltd, trading as Auction Hub Group
- Reg. no. K2016224499 (registered 2016/06/30), SARS tax no. 9982096167
- All values are constants in `config.php` (`COMPANY_*`). Never hard-code them.

---

## 3. Changes, by area

### 3.1 DEKRA and VIN policy
- Removed every offer to send DEKRA reports from enquiry WhatsApp templates and call scripts
  (`leads_helper.php`).
- DEKRA policy block on vehicle pages (`auction.php`), and wording updated across `faq.php`,
  `auction-guide.php`, `register-to-bid.php`, `about.php`, `preauction.php`, `invoice_helper.php`,
  `admin_dashboard.php`.

### 3.2 Bank question on every client form
- `config.php`: `CLIENT_BANKS`, `clientBankOptions()`.
- Bidder registration: `login.php` (Register tab), `register-to-bid.php`, server check and save in
  `auth.php` → `users.bank_name`.
- Enquiries: `enquiry_form.php`, `leads_helper.php` (`saveEnquiry`) → `enquiries.bank_name`; shown
  in the office alert.
- Finance and pre-auction already asked (`finance_apply.php`, `preauction_request.php`).

### 3.3 Pre-auction
- Offer percentage field with the usual band shown (`preauction_request.php`, `paBandPercent()`,
  `paRange()` in `finance_helper.php`).
- Commitment-fee letter: `paTermsLetterHtml()` (email and confirm page) and
  **`commitment_letter_pdf.php`** (PDF in the invoice layout, attached to the email and saved in the
  application's private folder so it survives the Outbox).
- `confirm_terms.php` (new): client reads and confirms; office is notified.
- Client application page (`my_application.php`) shows **Read & Confirm — Request My Invoice** until
  confirmed.
- Fixed: the letter email greeted the client twice.
- Vehicles are listed in one place only (`auctions.php`, live and coming soon). `preauction.php` is
  information plus a "Find your vehicle" section (**Coming soon** / **Live vehicles** buttons and a
  pre-auction enquiry form, preselected with `$enqType = 'preauction'`, landing on the Pre-Auction desk).
  Its old vehicle grid is gone; the count uses the qualification rule above.
- On each vehicle (`auction.php`) the client can **Bid**, and when it qualifies, **Secure it
  (pre-auction)** → `preauction_request.php?id=` or **Get a finance quote** → `finance_apply.php?id=`.

### 3.4 Finance
- Terms extended to 72 months (`finTiers()`), copy updated on `preauction.php`, `faq.php`,
  `index.php`, `events.php`, `chatbot_proxy.php`, admin settings note.
- Three-page quote (`finQuoteBodyHtml()` in `finance_helper.php`, rendered by `application_doc.php`)
  modelled on the office quotation PDF; deposit points (`finDepositPoints()`), company details table
  (`finCompanyDetailsTableHtml()`).
- Client chooses the term on the profile, then **Request my invoice** (`my_application.php`).

### 3.5 Registration emails and invoice
- Welcome email (`email_helper.php` → `sendWelcomeEmail`): tailored registration invoice, short
  bidding guide, company details, and an attached **registration invoice PDF**.
- `reg_invoice_pdf.php` (new): a small dependency-free A4 PDF writer (`AhgPdf`) that draws the
  Auction Hub invoice layout (navy bar, logo, bill-to, balance box, item bar, totals, step boxes,
  terms). Registration invoice shows **registration-stage content only**: the deposit, refund rule,
  "what happens after you pay" (pay and upload POP → we verify → bidder number), payment
  instructions, company details and registration terms.
- Company identity line added to the footer of every email built on `_emailTemplate()` and to the
  newsletter.

### 3.6 Winning emails
- Both winning emails (`sendAuctionWinnerEmail`, `sendAuctionWonEmail`) now list the 10 after-win
  steps (`afterWinStepsHtml()`), replacing an old "pay within 48 hours" line that contradicted the
  one-business-day rule. Steps also on `auction-guide.php` ("After You Win").

### 3.7 Vehicles and bidding (admin tools)
- `admin_split_stock.php` (new): randomly picks half of the live, unsold vehicles to move to
  "coming soon"; admin reviews and confirms the picks.
- `admin_relist.php` (new): relists unsold vehicles that closed with no bids.
- Office bid note no longer leaks into the public bidder name (`api.php`, `admin_place_bid`).
- **Bid Desk** (`admin_bid_desk.php`, menu Vehicles → Bid Desk, and a button on Manual Bids & Floor):
  every live, unsold lot as a photo card with its gallery thumbnails, current and next bid, time left,
  bid count and leader. Pick the floor/phone bidder once (kept for the session; recent bidders one tap
  away) and how the bid came in (phone, floor, WhatsApp), then **Bid** (next bid), **+2 steps** or an
  own amount. Bids go through `api.php` → `admin_place_bid` (minimum bid, outbid alert, bid email) and
  the channel is logged as the internal note. Only verified floor bidders appear in the picker. The
  card turns green when our bidder leads. Modelled on BidCo's `admin/bid-desk.php`, simplified
  (no auto-bid / max bids yet).

### 3.8 Admin simplification
- Menu cut from 27 items to 19, in groups: **Today**, **Clients**, **Payments**, **Vehicles**,
  **Marketing**, **Settings & Staff** (`$adminNav` in `admin_dashboard.php`). Menu items can carry a
  desk parameter (`applications?kind=finance`, `leads?topic=registration`); `$navIsOn` decides which
  item is highlighted.
- **Separate follow-up desks** under Clients: **Registrations** (sign-ups, banking-detail requests,
  "registering to bid" enquiries), **Finance** (applications + finance enquiries), **Pre-Auction**
  (requests + pre-auction enquiries) and **General Enquiries**. Each has its own waiting count.
  Finance and Pre-Auction pages have **Applications | Enquiries** tabs. Every lead carries a `topic`
  (`LEAD_TOPICS`, `leadEntryTopic()` in `leads_helper.php`; a person's desk is the most specific of
  their entries: pre-auction > finance > registration > general). Counts: `leadWaitingByTopic()`.
- Pages that share one menu item get a row of tabs (`$adminSubTabs`): Payments & Refunds, Import
  Vehicles, Send Email, Website Content.
- Add Vehicle, Split Live Stock and Relist Unsold are buttons on **Auctions**; Deal Builder is a
  **New walk-in deal** button on Finance & Pre-Auction.
- **Overview is a to-do list**: "Waiting on you" boxes per desk (registrations, finance applications,
  finance enquiries, pre-auction requests, pre-auction enquiries, general enquiries) plus outbox
  emails, proofs of payment, banking requests and refunds; up to five names each, oldest first.
- Finance & Pre-Auction list has an **All types / Finance / Pre-auction** filter with "to action"
  counts.

### 3.9 WhatsApp follow-up
- Each finance or pre-auction request shows a green **Next WhatsApp** box with the message for the
  client's current stage, editable before sending, plus **Open in WhatsApp** and **Copy**
  (`finAdminWhatsAppText()` in `finance_helper.php`).
- Every name on the to-do list has a WhatsApp button with the right message ready.
- Messages are signed with the consultant name, company phone and registration number, and warn that
  we never ask clients to pay into a different account. None offer the DEKRA report.
- The office-to-client link helper is **`waToClient()`**. Do not name anything `waLink()`: that name
  already exists in `tracking_helper.php` and a clash crashes admin pages.

### 3.10 Mobile
- New controls are at least 40–44 px tall; admin sub-tabs scroll sideways on phones; bank dropdown
  on Register to Bid styled like the other fields. Checked at 360 px and 390 px: no sideways
  scrolling on the forms, admin menu, to-do list, WhatsApp box, guide section or emails.

---

## 4. Testing notes

- No database or browser exists in the build sandbox, so pages were checked by rendering the new
  parts with stub data and the site's real CSS in headless Chrome, plus `php -l`, `pdfinfo`/`qpdf`
  on PDFs, and function-level tests.
- **`php -l` does not catch duplicate function names across files.** Before pushing, load all
  helpers together in one PHP process (or grep `function name(` across `*.php`).
- Needs a real check on staging or live after deploy:
  1. Register a test bidder → welcome email arrives with the registration invoice PDF.
  2. Pre-auction enquiry → letter email with PDF → confirm → admin issues invoice.
  3. Finance application → quote → choose term → request invoice.
  4. Admin on a phone: to-do list, sub-tabs, a WhatsApp button.
  5. Win an auction (test) → winning email shows the 10 steps.

---

## 5. Open items

1. **Merge to `main` and deploy** (owner to approve).
2. Pre-auction requests created **before** this change have no letter confirmation, so their invoice
   is blocked. Either email them the letter to confirm or add an admin "mark as confirmed" action.
3. The commitment-fee email has two buttons (**Confirm and request my invoice** and **Follow My
   Request**). Suggested: drop **Follow My Request** from that email so confirming is the only action.
4. Admin dashboard marketing table still shows a **48-month** finance column (about line 5315); terms
   are now 6–72 months.
5. Release Note, delivery note and ownership-transfer documents are described in the steps but are
   not generated by the system.
6. The registration invoice PDF has a blank signature line (no uploaded signature image).
7. A few older tap targets are under 32 px (checkboxes, password-show toggle on login).
8. Run the split tool on live stock only after admin confirms the picks.

---

## 6. Design brief: make the whole site excellent

Paste this into the new Claude Code session as the task.

```text
You are improving the public website of Auction Hub Group (AHG), a South African vehicle auction
house selling bank-repossessed and estate vehicles at live floor and online auctions. Repo:
bothamaria169-code/auctionhubgroup (PHP 8.3, procedural, no framework; shared layout in header.php
and footer.php; global styles in style.css with CSS variables at the top). Work on a new branch from
fix/no-dekra-report-by-message. Read CHANGES_README.md first and follow its business rules exactly.

GOAL
Make the site look and feel like the most trustworthy, modern vehicle auction site in South Africa:
premium, clear and fast on a mid-range Android phone over mobile data. Most visitors are on phones.

BRAND (keep it; refine it, do not replace it)
- Colours: navy #001F5C / #002B7F, blue #005DDB, light blue #1E90FF, white, off-white #F8FAFC,
  gold accent #FBBF24 used sparingly (live, sold, highlights). Use the existing CSS variables.
- Type: Barlow Condensed (headings, 600–800) and Barlow (body). Keep both.
- Logo: logo-round.png.

DESIGN PRINCIPLES
1. Trust first: company registration (K2016224499), SARS number, physical address, phone and
   WhatsApp visible in the footer and on key pages; "We never ask you to pay into a different
   account" near any payment information.
2. One clear primary action per screen. Consistent buttons (primary navy/blue, secondary outline).
3. A spacing and type scale (e.g. 4/8 px spacing steps; 3–4 heading sizes) applied everywhere.
4. Vehicle cards: large photo (16:10), make/model/year, mileage, location, status chip (Live,
   Coming soon, Sold), current bid or price, countdown for live lots, clear tap target.
5. Mobile: no sideways scrolling at 360 px; tap targets at least 44 px; sticky bottom action bar on
   vehicle pages (Bid / Pre-auction / Finance / WhatsApp); readable 16 px body text.
6. Speed: lazy-load images with width/height set, WebP where available, no layout shift, no heavy
   new libraries. Keep Font Awesome as it is.
7. Accessibility: WCAG AA contrast, visible focus states, alt text, form labels, aria for icons.
8. Consistent empty, loading and error states.

PAGES TO REVIEW AND POLISH (in this order)
index.php (home), auctions.php (listing + filters), auction.php (vehicle page), register-to-bid.php,
login.php, preauction.php, preauction_request.php, finance_apply.php, my_application.php,
auction-guide.php, faq.php, about.php, contact, header.php, footer.php, 404.php.

RULES
- Do not change business rules, prices, percentages, fees, timings or legal wording; take them from
  CHANGES_README.md section 2. Never offer to send DEKRA reports.
- Do not invent statistics, reviews or testimonials. Use live data the pages already query, or leave
  a clearly marked placeholder for the owner.
- Keep every existing form field, name attribute and endpoint; only change presentation.
- Before each commit: php -l on changed files, load all *_helper.php together in one PHP process to
  catch duplicate function names, and check pages at 360 px, 390 px, 768 px and 1280 px.
- Make small commits per page; push the branch; do not merge to main without the owner's approval.
- At the end, send screenshots of each page at phone and desktop width.
```

---

## 7. Design brief: new home page hero

Paste this as a separate task, or include it in the brief above.

```text
Redesign the hero section on index.php (the <section class="hero" id="hero"> block, about line 154,
and its .hero* styles in style.css). Keep the existing PHP logic: $stats['active_auctions'] and the
three logged-in states for the second button (Register Free / Complete Registration / My Dashboard).

PURPOSE
In five seconds a first-time visitor on a phone should know: what AHG sells, that it is real and
trustworthy, and what to do next.

CONTENT (use this wording or tighter; no new claims)
- Live badge: "<N> live auctions now" with a pulsing dot (hide when N is 0; show "Next auction:
  <date>" instead if that data is available).
- Headline (Barlow Condensed, 2 lines max on phone): "Bank-repo and estate vehicles at auction"
  with "at auction" or "Bank-repo" in the accent colour.
- Sub-line (1–2 lines): "Bid live on the floor or online from anywhere in South Africa.
  Every vehicle is DEKRA-inspected; view the full report at our office."
- Primary button: "Browse live auctions" → auctions.php.
- Secondary button: existing Register Free / Complete Registration / My Dashboard logic.
- Tertiary text link: "How buying works" → auction-guide.php.
- Quick search directly in the hero: make/model text field + "Search" (submits to auctions.php with
  the same query parameter the listing page already uses).
- Trust row (icons + short text): Live floor auctions · Online bidding · DEKRA-inspected ·
  Registered company K2016224499.
- Optional: a small strip of 3 featured live vehicles (photo, name, current bid, time left) taken
  from the query index.php already runs for featured auctions.

LAYOUT
- Phone (360–430 px): full-width, background photo of real stock (from uploads/ or ImageKit) with a
  navy gradient overlay (#001F5C → transparent) for text contrast; badge, headline, sub-line,
  buttons stacked full-width, search field, trust row as a 2×2 grid. Hero height about 85vh, never
  taller than the content. Primary button visible without scrolling.
- Desktop (≥1024 px): two columns: text and search on the left, featured vehicle card stack or
  photo on the right. Max content width matches the site container.
- Subtle entrance animation (fade/slide 150–250 ms); respect prefers-reduced-motion.

QUALITY BAR
- LCP image preloaded and under 200 KB (WebP), width/height set, no layout shift.
- WCAG AA contrast on all hero text over the photo.
- Works with JavaScript off (search is a plain GET form).
- Check at 360, 390, 768, 1280 and 1440 px; send screenshots.
- Do not change business rules or invent figures (see CHANGES_README.md section 2).
```
