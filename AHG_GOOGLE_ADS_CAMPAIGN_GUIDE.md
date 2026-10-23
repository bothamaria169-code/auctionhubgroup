# Auction Hub Group — Google Ads campaign guide

**Account 363-642-9749 · written 9 October 2026 · replaces the September launch pack**

The September pack was written when the live site had **no stock at all**, so it
never launched. Production now shows **494 vehicles live**, which removes the one
blocker that mattered. Everything below is built on what the live site actually
says today — the fees, the deposit, the stock counts and the pages that work.

Checked on the live site while writing this:

| Fact | Where it comes from |
|---|---|
| 494 vehicles live | `auctions.php` |
| R10,000 refundable deposit | `register-to-bid.php` (14 mentions) |
| No buyer's commission, R2,600 admin + VAT | `auction-guide.php` |
| DEKRA report on every lot | `auction-guide.php`, `register-to-bid.php` |
| Hilux 54 · Polo 54 · Mercedes 27 · BMW 25 · Golf 22 · Ranger 20 · Isuzu 16 · bakkies 13 · trucks 12 · Corolla 9 · NP200 8 · Fortuner 6 | `auctions.php?search=…` |
| Centurion (80 Sterling Rd), Boksburg, Durban, Bloemfontein, Eastern Cape | site footer, branches table |
| GA4 `G-STZ00ZTE9Y` and Ads `AW-3636429749` are live in the page source | live homepage |

---

## 0. Fix these before you spend a cent

| # | Problem | Why it costs you money | Fix |
|---|---|---|---|
| 1 | Photos still come from Cloudinary, whose emergency credits **expire 13 October 2026** | Every ad lands on a page of broken images | Upload `config.php` and `ahg_secrets.php`, run `ahg_switch_images_to_imagekit.sql`. All 10,854 photos are already waiting on ImageKit |
| 2 | `GADS_LABEL_REGISTRATION`, `_WHATSAPP` and `_CALL` in `config.php` are still `XXXXXXXXXXXXXXXXXX` | No conversions recorded, so you cannot tell which keyword produced a bidder, and Smart Bidding can never switch on | Create the three conversion actions (§8), paste the labels, re-upload `config.php` |
| 3 | Google Ads and GA4 are not linked | You see the signup but not what the visitor did next | Ads → Tools → Linked accounts → Google Analytics |
| 4 | `sold_vehicles.php` reports **0 vehicles sold** | "See what cars sold for" would land on an empty page | Don't link it or claim it until it has data |
| 5 | `events.php` newest date is **30 April 2026** | Any "auction this week" copy would be false, and the page looks abandoned | Update the events or keep ads away from it |
| 6 | `financing.php` still says "no credit checks" and "blacklisted welcome" | Google's financial-services policy can suspend the **whole account**, not one ad | Never in ad text, never a sitelink, never an ad landing page |
| 7 | Sitemap generator is not on a daily cron | Fresh lots take longer to appear in organic results | cPanel → Cron → `generate_sitemaps.php?key=ahg-gen-2026`, daily |

Items 1 and 2 are hard blockers. Items 3–7 can follow in the first week.

---

## 1. What is different from the September pack

1. **Brand gets its own campaign.** In one shared campaign a R12 generic click
   competes with a R2 brand click for the same budget. Split, brand stays cheap
   and always on, and the generic budget can't swallow it.
2. **A make-and-model ad group that lands on filtered stock.**
   `auctions.php?search=hilux` returns 54 real lots, so someone searching
   "repossessed hilux" sees 54 Hiluxes instead of a registration form. Verified
   working for every model listed in §4.
3. **Negative keywords as four shared lists**, about 200 terms, applied to both
   campaigns at once and reusable by every future campaign — rather than a single
   campaign-level list that has to be rebuilt each time.
4. **Offline conversion import for verified bidders.** The site already stores
   `users.acq_gclid`, so you can send Google the bidders who actually paid the
   R10,000 deposit, not just the people who filled in a form. Bidding then
   optimises towards deposits (§8).
5. **Two sitelinks removed**: in-house finance (policy risk) and past selling
   prices (empty page).
6. **"Park Village Auctions" is treated as a brand search, not a competitor** —
   people still look for the old name. Keyword only, never in ad text (§4, group E).

---

## 2. Account structure

| Campaign | Type | Daily budget | What it does |
|---|---|---|---|
| `AHG \| Brand \| Search` | Search | R40 | Defends your own name and the old one against impersonators and competitors |
| `AHG \| Non-brand \| Search` | Search | R250 → R500 → R1,000 | The money campaign: repossessed, auctions, bakkies, models |
| `AHG \| DSA \| Search` | Search, dynamic | R100 | Phase 2, from week three. Google reads the 494 live lots and writes the headline |
| Performance Max | — | — | Only after 30 conversions in 30 days. Not before |

Five ad groups inside the non-brand campaign, each with its own keywords, ads and
landing page. One conversion goal drives bidding: **bidder registration**.

---

## 3. Campaign setup, screen by screen

You are on "What's your campaign objective?" with **Create a campaign without
guidance** selected. That is the right choice — the guided flows switch on Display
expansion and broad match by default.

1. **Continue** → campaign type **Search**.
2. **Name**: `AHG | Non-brand | Search | ZA`.
3. **Bidding**: *Maximise clicks*, and tick **Set a maximum cost per click bid
   limit** → **R12**. Do not start on a Smart Bidding strategy; it has nothing to
   learn from yet.
4. **Networks**: untick **Search partners** and untick **Display network**. Both
   are on by default and both waste money in a new account.
5. **Locations**: South Africa. Then open *Location options* and choose
   **Presence: people in or regularly in your targeted locations**. The default
   includes people merely *interested in* South Africa, which means Nigeria and
   the UK.
6. **Languages**: English and Afrikaans.
7. **Audience segments**: add *In-market → Autos & Vehicles → Used cars* in
   **Observation** mode, not Targeting.
8. **Broad match**: leave off. Exact and phrase only to start.
9. **Automatically created assets**: off. **Final URL expansion**: off.
10. **Ad schedule**: all week to begin with. Review after fourteen days.
11. **Devices**: no adjustments yet. Expect 70–80% mobile.
12. **Conversion goals**: campaign-level override → **Bidder registration only**.
    WhatsApp and call clicks stay as secondary, measured but not bid on.

Repeat for the brand campaign with budget R40 and the same settings.

---

## 4. Ad groups, keywords and landing pages

Keep each group to one idea. Mixed groups are why ads score badly.

### A — Bank repossessed cars → `/register-to-bid.php`

```
[repossessed cars for sale]
[bank repossessed cars]
[repossessed cars south africa]
[bank repo cars for sale]
[repossessed vehicles for sale]
[bank repossessed vehicles]
[repossessed cars auction]
[repossessed cars gauteng]
[repossessed cars pretoria]
[repossessed cars johannesburg]
[absa repossessed cars]
[fnb repossessed cars]
[standard bank repossessed cars]
[nedbank repossessed cars]
[wesbank repossessed cars]
[mfc repossessed cars]
"repossessed cars"
"bank repo cars"
"repo cars for sale"
"liquidation cars for sale"
"bank stock cars"
"insolvency vehicles"
"repossessed cars near me"
```

### B — Car auctions, generic and by city → `/register-to-bid.php`

```
[car auctions south africa]
[vehicle auctions south africa]
[car auction gauteng]
[car auctions johannesburg]
[car auctions pretoria]
[car auctions centurion]
[car auctions durban]
[car auctions bloemfontein]
[car auctions port elizabeth]
[car auctions boksburg]
[online car auctions south africa]
[live car auction]
[cheap car auctions]
[car auction website]
"car auction"
"vehicle auction"
"auction cars for sale"
"car auction near me"
"bid on cars online"
"online vehicle auction"
```

Cities match real branches: Centurion, Boksburg, Durban, Bloemfontein and the
Eastern Cape. Don't add Cape Town until you can service it.

### C — Bakkies, LDVs and trucks → `/auctions.php?search=bakkie`

13 bakkies and 12 trucks are live right now, so the filtered page has stock.

```
[repossessed bakkies for sale]
[bakkie auction south africa]
[repossessed trucks for sale]
[truck auctions south africa]
[bakkie auctions gauteng]
[ldv auction south africa]
[repossessed double cab]
[truck tractor auction]
"repossessed bakkies"
"bakkie auction"
"truck auction"
"repo bakkies for sale"
"used bakkies auction"
"super link auction"
```

### D — Make and model → filtered listing pages (new)

One small ad group per model, each landing on its own filtered page. Every URL
below was tested and returns stock.

| Ad group | Landing page | Lots live |
|---|---|---|
| Hilux | `/auctions.php?search=hilux` | 54 |
| Polo | `/auctions.php?search=polo` | 54 |
| Mercedes-Benz | `/auctions.php?search=mercedes` | 27 |
| BMW | `/auctions.php?search=bmw` | 25 |
| Golf | `/auctions.php?search=golf` | 22 |
| Ranger | `/auctions.php?search=ranger` | 20 |
| Isuzu | `/auctions.php?search=isuzu` | 16 |
| Corolla | `/auctions.php?search=corolla` | 9 |
| NP200 | `/auctions.php?search=np200` | 8 |
| Fortuner | `/auctions.php?search=fortuner` | 6 |

Keywords, swapping the model in each group:

```
[repossessed toyota hilux]
[toyota hilux auction]
[hilux auction south africa]
[repossessed hilux for sale]
"hilux auction"
"repossessed hilux"
"hilux on auction"
```

Start with Hilux, Polo, Ranger and BMW. Add the rest once they prove themselves.
Check the lot count monthly — pause a group the week its page drops near zero.

### E — Brand → `/` (homepage), in the brand campaign

```
[auction hub group]
[auction hub auctioneers]
[auctionhubgroup]
[auction hub group centurion]
[auction hub group register]
[auction hub group auctions]
[park village auctions]
[park village auctions pretoria]
"auction hub"
"auctionhub"
"park village auction"
```

The old name belongs in the keyword list because people still search it. **Keep
it out of the ad text** — a trademarked name in ad copy can be disapproved, while
bidding on it as a keyword is allowed.

---

## 5. Negative keywords

Build these as four **shared lists** (Tools → Shared library → Negative keyword
lists) and apply all four to both campaigns. Phrase match is wide enough that
most of these will trigger without them.

**List 1 — Jobs and training**

```
jobs, job, vacancy, vacancies, salary, salaries, career, careers, learnership,
internship, apprentice, cv, resume, hiring, recruitment, employment, auctioneer course,
how to become an auctioneer, auctioneering school, training, qualification, certificate,
job description, work from home
```

**List 2 — Research, freebies and reputation**

```
free, gratis, pdf, download, meaning, definition, define, wikipedia, reddit, quora,
forum, blog, youtube, video, template, example, sample, list of, how does, what is,
how to start, versus, vs, review, reviews, complaints, scam, fake, fraud, hellopeter,
news, statistics, history, simulator, game, games, model car, toy, diecast
```

Note: "scam", "review" and "complaints" stay negative in the **non-brand**
campaign only. In the brand campaign you want to appear for them, so you control
what people read about your own name.

**List 3 — Parts, scrap and damaged**

```
parts, spares, spare parts, stripping, strip, scrap, scrapyard, scrap yard, salvage,
salvage yard, breakers, breaker yard, accident damaged, damaged, code 3, code 4,
write off, written off, non runner, non-runner, engine only, engine for sale, gearbox,
tyres, rims, mags, bumper, body shell, panel beater, towing, wreck, wrecked, junk,
dismantlers
```

**List 4 — Wrong asset, wrong place, wrong intent**

```
house, houses, property, properties, home, land, plot, stand, farm, smallholding,
furniture, appliances, livestock, cattle, sheep, goats, poultry, tractor, farm equipment,
forklift, machinery, art, antique, antiques, estate sale, storage unit, jewellery,
laptop, phone, bicycle, motorbike, motorcycle, bike, scooter, quad bike, boat, jet ski,
caravan, aircraft, plane,
rent, rental, hire, car hire, rent to own, lease, leasing, insurance, insurance quote,
valuation, book value, blue book, trade in, trade-in, sell my car, selling my car,
how much is my car worth, scrap my car,
roadworthy, natis, licence renewal, license renewal, change of ownership, registration papers,
loan, credit score, blacklisted, debt review, stop repossession, voluntary surrender,
repossession help, how to stop repossession,
sheriff, police auction, government auction, sars auction, impound, impounded, stolen,
court, liquidator,
zimbabwe, zambia, botswana, namibia, lesotho, eswatini, mozambique, nigeria, kenya,
ghana, uk, usa, america, dubai, uae, japan, germany, australia, canada, china, india,
copart, iaai, manheim, ebay, craigslist, alibaba
```

Deliberately **not** negatives: truck, trailer, super link, bus, minibus, panel van —
you sell those. And never negative "registration" on its own; your own funnel is
"register to bid".

### Cross-group negatives, so the right ad shows

| Add to | Negative (phrase) | Reason |
|---|---|---|
| Group B | `"repossessed"`, `"repo"` | Sends repo searches to group A's ad |
| Groups A and B | `"bakkie"`, `"truck"`, `"ldv"` | Sends work-vehicle searches to group C |
| Groups A, B and C | `"hilux"`, `"polo"`, `"ranger"`, `"golf"`, `"corolla"`, `"fortuner"`, `"np200"` | Sends model searches to the filtered pages in group D |
| Whole non-brand campaign | `"auction hub"`, `"auctionhub"`, `"park village"` | Brand searches get served by the brand campaign at a far lower cost |

---

## 6. The ads

Responsive search ads. Give Google all 15 headlines and 4 descriptions and pin
nothing at first. Every line below is inside Google's limits (30 characters for
headlines, 90 for descriptions) and matches a claim the live site makes.

### Group A — Bank repossessed cars

Headlines:

```
Bank Repossessed Cars SA
Repossessed Car Auctions
Bid at the Bank's Price
Register Free to Bid
Every Car DEKRA Inspected
No Buyer's Commission
Hundreds of Lots Live Now
R10,000 Refundable Deposit
Priced Below Retail Value
Centurion Auction Floor
Bid Online or In Person
Verified in 1-2 Days
Full Condition Report
Browse Today's Live Lots
Auction Hub Group
```

Descriptions:

```
Cars come direct from banks at the outstanding balance, not retail. Register free to bid.
Every lot DEKRA inspected with a full condition report. Bring your own mechanic to view.
No buyer's commission. Hammer price plus R2,600 admin and VAT. Nothing hidden.
R10,000 deposit is fully refundable if you don't buy. Verified in 1-2 business days.
```

### Group B — Car auctions

Headlines:

```
Car Auctions South Africa
Vehicle Auctions Gauteng
Hundreds of Cars on Auction
Register Free to Bid
Bank & Liquidation Stock
Cars From Every Major Make
DEKRA Report on Every Lot
No Buyer's Commission
Bid Online From Anywhere
Licensed Auctioneers
Centurion, Durban & More
Refundable R10,000 Deposit
Filter by Make and Price
Cars, Bakkies and Trucks
Auction Hub Group
```

Descriptions:

```
Browse live vehicle auctions across South Africa. Filter by make, year, price and mileage.
Bank and liquidation stock, DEKRA inspected, sold to the highest bidder. Register free.
Bid in person at our Centurion floor or online by webcast. Verified in 1-2 business days.
No buyer's commission. Hammer price plus R2,600 admin and VAT, so your budget is yours.
```

### Group C — Bakkies, LDVs and trucks

Headlines:

```
Repossessed Bakkies for Sale
Bakkie Auctions South Africa
Hilux, Ranger, NP200 & More
Trucks & LDVs on Auction
Ex-Bank Bakkie Stock
Register Free to Bid
DEKRA Inspected Bakkies
Work Vehicles at Bank Price
No Buyer's Commission
Double Cabs and Singles
Bid Online or In Person
Full Condition Report
Priced Below Retail Value
Super Link and Truck Lots
Auction Hub Group
```

Descriptions:

```
Repossessed bakkies, LDVs and trucks from bank and liquidation stock. Register free now.
Hilux, Ranger, NP200 and more, each with a full DEKRA condition report supplied.
Buy work vehicles at the outstanding balance, not retail. No buyer's commission payable.
Inspect before you bid. Bring your own mechanic to the viewing. Verified in 1-2 days.
```

### Group D — Model groups (Hilux shown; swap the model for each group)

Headlines:

```
Repossessed Hilux for Sale
Toyota Hilux on Auction
54 Hilux Lots Live Today
Bid at the Bank's Price
DEKRA Inspected Hilux
Register Free to Bid
No Buyer's Commission
Single and Double Cabs
See Mileage and Condition
Priced Below Retail Value
Bid Online or In Person
R10,000 Refundable Deposit
Full Condition Report
Centurion Auction Floor
Auction Hub Group
```

Descriptions:

```
Browse every repossessed Hilux on auction now, with mileage, year and condition shown.
Bank and liquidation stock sold to the highest bidder. No buyer's commission payable.
Every lot DEKRA inspected with a full condition report. Inspect before you bid.
R10,000 deposit is fully refundable if you don't buy. Verified in 1-2 business days.
```

Keep the lot-count headline accurate: "54 Hilux Lots Live Today" is true this
week, so check it when you review the group monthly. If you would rather not
maintain it, use "Hilux Lots Live Right Now".

### Group E — Brand

Headlines:

```
Auction Hub Group
Official Auction Hub Site
Register to Bid Free
Browse Today's Live Lots
Centurion Auction Floor
Registered Since 2016
DEKRA Inspected Stock
No Buyer's Commission
Bid Online or In Person
WhatsApp Us Now
Contact Our Sales Team
Beware of Fake Sites
```

Descriptions:

```
The official Auction Hub Group site. Register free, get verified and bid on bank stock.
Licensed auctioneers registered since 2016. Centurion, Boksburg, Durban and East Cape.
Beware of scam sites using our name. We never ask for payment without verified invoicing.
Talk to a real consultant on WhatsApp before you bid. We answer during business hours.
```

---

## 7. Extensions and assets

**Sitelinks** (25-character text, two 35-character description lines):

| Text | Lines | URL |
|---|---|---|
| Browse Live Auctions | See every vehicle open for / bidding right now. | `/auctions.php` |
| How Bidding Works | Register, verify, bid. / The full process explained. | `/auction-guide.php` |
| What It Actually Costs | No commission. Admin fee / and VAT only. | `/auction-guide.php` |
| Bakkies and Trucks | Work vehicles from bank / and liquidation stock. | `/auctions.php?search=bakkie` |
| Toyota Hilux Lots | Every repossessed Hilux / currently on auction. | `/auctions.php?search=hilux` |
| Pre-Auction Deals | Buy before it hits the / auction floor. | `/preauction.php` |

Dropped from the old pack: **In-House Finance** (policy risk) and **Past Selling
Prices** (the page shows zero sold vehicles).

**Callouts** (25 characters each):

```
No Buyer's Commission
DEKRA Inspected
Refundable Deposit
Free Registration
Bid Online or In Person
Bank & Liquidation Stock
Registered Since 2016
Nationwide Delivery
```

**Structured snippets**
- Brands: Toyota · Volkswagen · Ford · Hyundai · Audi · Mercedes-Benz · BMW · Kia · Nissan · Isuzu
- Types: Sedans · Hatchbacks · Bakkies · SUVs · Trucks · LDVs · Panel Vans · Minibuses

**Call**: +27 10 516 0683, business hours only, with call reporting on.
**Location**: link the Google Business Profile for 80 Sterling Rd, Kosmosdal, Centurion.
**Images**: 1200×628 landscape and 1200×1200 square, from your own auction floor.
Stock photos of showroom cars make an auction ad look like a scam site, and your
own pages warn buyers about exactly that.

---

## 8. Conversion tracking

### The three online actions

| Action | Category | Value | Count | Fires when |
|---|---|---|---|---|
| Bidder registration | Sign-up, **Primary** | R350 | One | A new account is created (`auth.php`) |
| WhatsApp click | Contact, Secondary | none | One | Any `data-ahg-contact="whatsapp"` element |
| Call click | Contact, Secondary | none | One | The landline links in the float popup |

Create them under Tools → Conversions → New → Website. Take the part of each tag
**after the slash** and paste it into `config.php`:

```php
define('GADS_LABEL_REGISTRATION', 'paste_me');
define('GADS_LABEL_WHATSAPP',     'paste_me');
define('GADS_LABEL_CALL',         'paste_me');
```

Then re-upload `config.php`. Nothing fires until those are real.

**Pick one measurement path.** GTM (`GTM-N5LK7BDP`) is installed sitewide and the
site also pushes `ahg_registration` and `ahg_contact_click` to the dataLayer.
Either keep the IDs in `config.php` and build no GA4 or Ads tags in GTM, or blank
them and build those tags in GTM. Doing both double-counts every conversion.

Attribution: **data-driven**. Conversion window: 30 days click.

### The upgrade: import verified bidders

A signup is cheap to produce; a bidder who actually pays the R10,000 deposit is
the real outcome. The site stores the Google click ID on the user row, so you can
send those back to Google and let bidding optimise towards deposits.

Set it up once: Tools → Conversions → New → **Import** → Other data sources →
Track conversions from clicks. Name it **Verified bidder**, count One, and give
it a value you believe (gross profit per buyer × the share of verified bidders
who buy — start at R1,500 and refine).

Then export this every Monday and upload the CSV:

```sql
SELECT
    u.acq_gclid                                                        AS 'Google Click ID',
    'Verified bidder'                                                  AS 'Conversion Name',
    CONCAT(DATE_FORMAT(br.reviewed_at, '%Y-%m-%d %H:%i:%s'), '+02:00') AS 'Conversion Time',
    1500                                                               AS 'Conversion Value',
    'ZAR'                                                              AS 'Conversion Currency'
FROM bidder_registrations br
JOIN users u ON u.id = br.user_id
WHERE br.status = 'approved'
  AND br.reviewed_at >= NOW() - INTERVAL 30 DAY
  AND u.acq_gclid IS NOT NULL
  AND u.acq_gclid <> '';
```

Export as CSV in phpMyAdmin, keep the header row exactly as above, and upload
under Conversions → Uploads. Google accepts clicks up to 90 days old.

Once that has run for a month, switch the campaign's bidding to **Maximise
conversion value** with Verified bidder as the goal.

---

## 9. Budget, bidding and what good looks like

| Phase | When | Daily | Bidding |
|---|---|---|---|
| Test | Weeks 1–2 | R250 non-brand + R40 brand | Maximise clicks, R12 CPC cap |
| Build | Weeks 3–6, once registrations are arriving | R500 + R40 | Maximise conversions, no target yet |
| Scale | Only with 30+ conversions in 30 days | R1,000+ | Target CPA, starting at your actual cost per registration |

Rough expectations for South African auto-auction terms, to sanity-check against
Keyword Planner rather than to trust blindly: R4–R12 per click on generic and
repo terms, under R3 on brand, 2–5% of landing-page visitors registering. At R7 a
click and 3%, a registration costs about R230, which is inside the R350 you
already decided a registration is worth.

Watch three numbers weekly: cost per registration, registrations that become
verified bidders, and cost per verified bidder. The first is vanity until the
second holds up.

---

## 10. The first fourteen days

- **Day 1** — launch groups A and E only. One campaign producing data beats four producing noise.
- **Day 2, and every second day** — Search terms report. Add every irrelevant term to the right shared list. This is where most of the waste gets caught.
- **Day 3** — confirm conversions are recording. Register a test bidder with `?gclid=test123` on the URL, then check Conversions in up to three hours.
- **Day 5** — add group C if A is producing registrations under R350.
- **Day 7** — first real review. Pause keywords with 30+ clicks and no registration. Check the mobile/desktop split before adjusting devices.
- **Day 10** — add groups B and D.
- **Day 14** — ad schedule from the hour-of-day report; first offline upload of verified bidders.

---

## 11. Weekly and monthly routine

**Weekly**: search terms, negatives, upload verified bidders, check each model
group's landing page still has stock, read the disapprovals tab.

**Monthly**: refresh the worst-performing three headlines per group, check that
lot-count claims are still true, review the budget split brand vs non-brand,
check Search Console for which terms you now rank for organically and stop paying
for the ones where you hold the top organic spot.

---

## 12. Policy risks

| Risk | What happens | Rule |
|---|---|---|
| "No credit checks" / "blacklisted welcome" on `financing.php` | Financial-services policy; can suspend the entire account | Never in ads, never a sitelink, never a landing page. A separate finance campaign with its own landing page if you want it at all |
| Price claims that aren't on the page | Ad disapproved | No rand figures in headlines except the R10,000 deposit, which the landing page states 14 times |
| Trademarked names in ad text | Ad disapproved | Bank names and the old company name may be keywords, never ad copy |
| "Stolen", "impounded", "police auction" | Misleading, and the wrong buyer | Stay with repossessed, liquidation, insolvency, bank stock |
| Impersonators bidding on your name | You pay to defend your own brand, or lose the click | Keep the brand campaign running permanently, even at R40 a day |

---

## 13. Phase 2, from week three

**Dynamic Search Ads.** With 494 lots live, Google can read `auctions.php` and
generate a headline per vehicle. Run it as its own campaign, targeting only the
listing pages, with the same shared negative lists and a R100 budget. Treat its
search terms report as a keyword research tool: anything that converts becomes a
proper keyword in the non-brand campaign.

**Performance Max.** Only once you have 30 conversions a month and the verified
bidder import is running. Without those it spends on Display and reports
conversions you can't trace.
