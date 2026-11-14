# Auction Hub Group — 10-day expansion Search campaign

**Written 13 November 2026 · R1,000 test (R100/day × 10 days) · Search only · KZN, Eastern Cape, Free State, Western Cape, Gauteng excluded**

Published version: see the link in `README.md`. The fuller October guide is [AHG_GOOGLE_ADS_CAMPAIGN_GUIDE.md](AHG_GOOGLE_ADS_CAMPAIGN_GUIDE.md); this test replaces its national budget plan for now.

## 1. Fix these before you spend a cent

Three of these stop the test from telling you anything. Do them first, in this order.

| # | Problem | Why it costs you money | Fix |
|---|---|---|---|
| 1 | `GADS_LABEL_REGISTRATION`, `_WHATSAPP` and `_CALL` in `config.php` (lines 144–148) are still `XXXXXXXXXXXXXXXXXX` | Google records clicks but no registrations, so after 10 days you can't tell which keyword produced a bidder | Create the three conversion actions (section 9), paste the labels, deploy `config.php` |
| 2 | Two tracking paths are installed: the `AW-3636429749` tag from `config.php` and GTM `GTM-N5LK7BDP` | Tags in both places count every registration twice | Keep the labels in `config.php` and build **no** Ads conversion tags in GTM |
| 3 | Stock has dropped from 494 lots (October) to **48** (13 Nov) | An ad that lands on an empty model page burns the click | On launch morning open the three landing pages. Hilux needs 10+ lots, Quantum 4+. Launch without group C if Quantum is under 4 |
| 4 | There is no Western Cape branch | A Cape Town bidder who wins and can't get the car home asks for a refund | Confirm you can quote and arrange delivery to Cape Town. If not, remove Western Cape before launch |
| 5 | New registrants need a call within a day | Most ad sign-ups never pay the R10,000 deposit without a human nudge | Agree who works the Leads page every morning during the test |

## 2. The campaign at a glance

```
AHG | Expansion | Search   (R100/day, Max clicks R8 cap, KZN/EC/FS/WC, Gauteng excluded)
├── A  Bank repossessed vehicles   → /register-to-bid.php
├── B  Toyota Hilux & bakkies      → /auctions.php?search=hilux
└── C  Toyota Quantum & minibuses  → /auctions.php?search=quantum
    6 shared negative lists on all three
```

| Setting | Value |
|---|---|
| Campaign type | Search only. No Display, no Search partners, no Performance Max |
| Budget | R100 a day for 10 days = R1,000 |
| Bidding | Maximise clicks, max cost per click R8 |
| Where | KwaZulu-Natal, Eastern Cape, Free State, Western Cape. Gauteng excluded |
| Ad groups | 3: Bank repossessed vehicles, Toyota Hilux & bakkies, Toyota Quantum & minibuses |
| Keywords | 52, exact and phrase match only |
| Negatives | 6 shared lists plus cross-group rules |
| Goal | Bidder registration (a new account), valued at R350 |

## 3. Where the ads show

The test buys reach where you have no paid presence yet: the provinces with your branch cities, plus Cape Town for online bidders. Distances are approximate road distances from head office in Centurion.

| Province | Your presence | Main cities | From Centurion | Target? |
|---|---|---|---|---|
| Free State | Bloemfontein branch, Cnr R64 & Valentia Rd, Waterbron | Bloemfontein, Welkom | ~420 km | Yes |
| KwaZulu-Natal | Durban branch, River Horse Estate | Durban, Pietermaritzburg, Richards Bay | ~600 km | Yes |
| Eastern Cape | Gqeberha branch, 142 Burman Rd, Deal Party | Gqeberha, East London, Mthatha | ~950–1,060 km | Yes |
| Western Cape | No branch. Online bidding by webcast, delivery on request | Cape Town, George | ~1,450 km | Yes, if delivery is confirmed |
| Gauteng | Head office and Boksburg |  |  | **Excluded** |

> Gauteng is shut out twice. The location exclusion stops people **in** Gauteng seeing the ads. Negative list 5 stops people elsewhere who search for Gauteng places, for example "repossessed cars pretoria" typed in Durban.

Don't promise viewing at a branch in the ads. The ads say you can bid online and that delivery is available, which the register page states. If the Durban, Gqeberha and Bloemfontein branches can show these vehicles, add that later as its own test.

## 4. Set it up, screen by screen

1. Google Ads → **Campaigns** → **+ New campaign** → **Create a campaign without guidance** → **Search**. The guided flows switch on Display and broad match.
2. **Conversion goals**: keep only **Bidder registration** as the campaign goal. WhatsApp and call clicks stay secondary.
3. **Campaign name**: `AHG | Expansion | Search | KZN-EC-FS-WC | Nov26`.
4. **Bidding**: *Maximise clicks*. Tick **Set a maximum cost per click bid limit** and enter **R8**.
5. **Networks**: untick **Include Google search partners** and untick **Include Google Display Network**.
6. **Locations** → *Enter another location*: add **KwaZulu-Natal**, **Eastern Cape**, **Free State** and **Western Cape**. Then add **Gauteng** and choose **Exclude**.
7. **Location options** → Include: **Presence: people in or regularly in your included locations**. Exclude: **Presence**.
8. **Languages**: English.
9. **Audience segments**: add *In-market → Autos & Vehicles → Used vehicles* as **Observation**, not Targeting.
10. **Broad match** off. **AI Max** off. **Automatically created assets** off.
11. **Start and end dates**: end date = launch day + 9, so the campaign stops by itself after 10 days.
12. **Budget**: if Google offers **Campaign total budget**, choose R1,000 over the dates. Otherwise set **R100 daily**. A daily budget can spend up to twice that on a busy day and less on a quiet one, so the 10-day total lands near R1,000, not on it exactly.
13. **Ad groups**: create A, B and C from section 5, each with its own keywords and final URL.
14. **Ads**: one responsive search ad per group from section 7. Display paths: A `Repossessed/Cars`, B `Toyota/Hilux`, C `Toyota/Quantum`.
15. **Assets**: add the sitelinks, callouts, structured snippets and call asset from section 8.
16. **Shared negative lists**: Tools → **Shared library** → **Negative keyword lists**. Build the six lists from section 6 and apply all six to this campaign.
17. **Publish**. Ads are reviewed in a few hours. Status should read *Eligible*.

## 5. Ad groups and keywords

Lead with the vehicles people search most **that you actually have on the floor**. South Africa's most-searched used vehicles are led by the Toyota Hilux, VW Polo and Polo Vivo, Ford Ranger, Toyota Corolla, Fortuner and Quantum. That ranking is approximate: confirm it in Keyword Planner with the four provinces selected before launch.

| Model | Live lots, 13 Nov | Decision |
|---|---|---|
| Toyota Hilux | 18 | **Own ad group (B)** |
| Toyota Quantum | 6 | **Own ad group (C)** |
| Ford Ranger | 3 | Keyword inside group B only |
| VW Polo | 2 | Wait. Searched heavily, but 2 lots would waste the click |
| Corolla, Fortuner, Golf, NP200, Isuzu | 1 each | Wait |
| BMW, Mercedes-Benz | 0 | Wait |

Square brackets are exact match, quotes are phrase match. Keywords marked **watch** have the most searches and the weakest auction intent (people shopping dealers). Keep them only while they bring registrations: pause them on day 5 if they've taken a quarter of the spend with none.

### A — Bank Repossessed Vehicles → `/register-to-bid.php`

The core offer. Every one of the 48 live vehicles is bank stock, so this group sells the whole floor and lands on the page built for ad traffic.

```
[bank repossessed vehicles]
[bank repossessed cars]
[repossessed cars for sale]
[repossessed vehicles for sale]
[bank repo cars]
[repossessed cars auction]
[repossessed cars durban]
[repossessed cars kzn]
[repossessed cars pietermaritzburg]
[repossessed cars bloemfontein]
[repossessed cars port elizabeth]
[repossessed cars gqeberha]
[repossessed cars east london]
[repossessed cars cape town]
[car auctions durban]
[car auctions bloemfontein]
[car auctions port elizabeth]
[car auctions cape town]
"bank repossessed cars"
"bank repossessed vehicles"
"repo cars for sale"
"bank auction cars"
```

### B — Toyota Hilux & bakkies → `/auctions.php?search=hilux`

The Hilux is South Africa's best-selling vehicle and one of its most-searched used cars. It is also your deepest stock: 18 of the 48 live lots. Searchers land on a page that shows only Hiluxes.

```
[repossessed hilux]
[repossessed toyota hilux]
[repossessed hilux for sale]
[bank repossessed hilux]
[hilux auction]
[toyota hilux auction]
[hilux on auction]
[repossessed bakkies]
[repossessed bakkies for sale]
[bakkie auction]
[repossessed ford ranger]
[hilux for sale] (watch)
[used hilux for sale] (watch)
"repossessed hilux"
"hilux auction"
"repossessed bakkie"
"bakkie auction"
```

### C — Toyota Quantum & minibuses → `/auctions.php?search=quantum`

Six Quantums are live. Minibus buyers in KZN and the Eastern Cape search for repossessed Quantums by name, and few auction houses bid on these words, so clicks should be cheaper than for cars.

```
[repossessed quantum]
[repossessed toyota quantum]
[repossessed quantum for sale]
[quantum auction]
[toyota quantum auction]
[repossessed taxi for sale]
[repossessed minibus]
[quantum for sale] (watch)
[toyota quantum for sale] (watch)
"repossessed quantum"
"quantum auction"
"repossessed taxi"
"minibus auction"
```

### Switch these on when their page has 10+ lots

| Model | Landing page | Starter keywords |
|---|---|---|
| VW Polo / Polo Vivo | `/auctions.php?search=polo` | [repossessed polo], [polo vivo auction], "repossessed polo" |
| Ford Ranger | `/auctions.php?search=ranger` | [repossessed ford ranger], [ranger auction], "repossessed ranger" |
| Toyota Corolla | `/auctions.php?search=corolla` | [repossessed corolla], [corolla auction] |
| Toyota Fortuner | `/auctions.php?search=fortuner` | [repossessed fortuner], [fortuner auction] |
| VW Golf | `/auctions.php?search=golf` | [repossessed golf], [golf gti auction] |

Copy group B's ads for each new model and swap the model name. Remove `[repossessed ford ranger]` from group B when Ranger gets its own group.

## 6. Negative keywords

Build these as **shared lists** under Tools → Shared library → Negative keyword lists, and apply all six to the campaign. Lists 1–4 come from the October guide and are reusable by every future campaign. Add each word as **phrase** match.

### List 1 · Jobs and training

```
jobs, job, vacancy, vacancies, salary, salaries, career, careers, learnership, internship, apprentice, cv, resume, hiring, recruitment, employment, auctioneer course, how to become an auctioneer, auctioneering school, training, qualification, certificate, job description, work from home
```

### List 2 · Research, freebies and reputation

```
free, gratis, pdf, download, meaning, definition, define, wikipedia, reddit, quora, forum, blog, youtube, video, template, example, sample, list of, how does, what is, how to start, versus, vs, review, reviews, complaints, scam, fake, fraud, hellopeter, news, statistics, history, simulator, game, games, model car, toy, diecast
```

### List 3 · Parts, scrap and damaged

```
parts, spares, spare parts, stripping, strip, scrap, scrapyard, scrap yard, salvage, salvage yard, breakers, breaker yard, accident damaged, damaged, code 3, code 4, write off, written off, non runner, non-runner, engine only, engine for sale, gearbox, tyres, rims, mags, bumper, body shell, panel beater, towing, wreck, wrecked, junk, dismantlers
```

### List 4 · Wrong asset, wrong place, wrong intent

```
house, houses, property, properties, home, land, plot, stand, farm, smallholding, furniture, appliances, livestock, cattle, sheep, goats, poultry, tractor, farm equipment, forklift, machinery, art, antique, antiques, estate sale, storage unit, jewellery, laptop, phone, bicycle, motorbike, motorcycle, bike, scooter, quad bike, boat, jet ski, caravan, aircraft, plane, rent, rental, hire, car hire, rent to own, lease, leasing, insurance, insurance quote, valuation, book value, blue book, trade in, trade-in, sell my car, selling my car, how much is my car worth, scrap my car, roadworthy, natis, licence renewal, license renewal, change of ownership, registration papers, loan, credit score, blacklisted, debt review, stop repossession, voluntary surrender, repossession help, how to stop repossession, sheriff, police auction, government auction, sars auction, impound, impounded, stolen, court, liquidator, zimbabwe, zambia, botswana, namibia, lesotho, eswatini, mozambique, nigeria, kenya, ghana, uk, usa, america, dubai, uae, japan, germany, australia, canada, china, india, copart, iaai, manheim, ebay, craigslist, alibaba
```

### List 5 · Gauteng place names (new)

```
gauteng, johannesburg, joburg, jhb, pretoria, centurion, midrand, sandton, soweto, boksburg, benoni, germiston, kempton park, randburg, roodepoort, krugersdorp, vereeniging, vanderbijlpark, alberton, tembisa, springs, east rand, west rand
```

### List 6 · Finance and taxi paperwork (new)

```
finance, financing, on finance, no deposit, payment plan, monthly instalment, instalments, lay by, layby, debt review, under debt review, rent to own, loans, route permit, operating licence, operating license, taxi permit, taxi route, taxi association
```

### Cross-group negatives, so the right ad shows

| Add to | Negative (phrase) | Why |
|---|---|---|
| Group A | "hilux", "ranger", "bakkie", "quantum", "taxi", "minibus" | Sends model searches to the page that shows that model |
| Group B | "quantum", "taxi", "minibus" | Sends minibus searches to group C |
| Group C | "hilux", "ranger", "bakkie" | Sends bakkie searches to group B |
| Whole campaign | "auction hub", "auctionhub", "park village" | People searching your name find you for free in the organic results |

### Never add these as negatives

```
register, registration, cheap, auction, auctions, bank, repo, repossessed, bakkie, taxi, minibus, price, prices, deposit, near me, durban, cape town, port elizabeth, gqeberha, bloemfontein, east london
```

"Register to bid" is your own funnel, "cheap" is how many bargain hunters search, and the city names are the regions you're paying to reach. List 6 blocks the phrase "no deposit", so searches that mention a deposit in any other way still see your ads.

## 7. The ads

One responsive search ad per group, with all 15 headlines and 4 descriptions. Every line was checked against Google's limits (30 characters per headline, 90 per description) and repeats a claim the register page or auction guide makes.

Pin the two **auction price** headlines in each group to **position 1**. Google then always opens with one of them and rotates everything else. Pinning two lines to one spot keeps Google's ad strength from dropping to *Poor*.

### Group A — Bank Repossessed Vehicles

Headlines (★ = pin to position 1):

```
Repo Cars at Auction Prices     27  ★
Bid at the Bank's Price         23  ★
Bank Repossessed Vehicles       25
Repossessed Cars for Sale       25
Not Retail. The Bank's Price    28
Register Free to Bid            20
R10,000 Refundable Deposit      26
Every Car DEKRA Inspected       25
No Buyer's Commission           21
Bid Online via Live Webcast     27
Bid Online From Anywhere in SA  30
Verified in 1-2 Business Days   29
Nationwide Delivery Available   29
Full Condition Report           21
Auction Hub Group               17
```

Descriptions:

```
Bank repossessed cars priced at the outstanding balance owed, not retail. Register free.  (88)
Every vehicle DEKRA inspected with a full condition report. Bid online via live webcast.  (88)
No buyer's commission. Hammer price plus R2,600 admin and VAT. Nationwide delivery.  (83)
R10,000 deposit is fully refundable if you don't buy. Verified in 1-2 business days.  (84)
```

### Group B — Toyota Hilux & bakkies

Headlines (★ = pin to position 1):

```
Hilux at Auction Prices         23  ★
Repossessed Toyota Hilux        24  ★
Toyota Hilux on Auction         23
Bank Repo Hilux for Sale        24
Hilux Bakkies Live Now          22
Single and Double Cab Hilux     27
Hilux GD-6 4x4 and 4x2          22
Bid at the Bank's Price         23
Every Hilux DEKRA Inspected     27
No Buyer's Commission           21
Register Free to Bid            20
Bid Online via Live Webcast     27
Nationwide Delivery Available   29
R10,000 Refundable Deposit      26
Auction Hub Group               17
```

Descriptions:

```
Browse every repossessed Toyota Hilux on auction now, with year, mileage and condition.  (87)
Bank stock priced at the outstanding balance, not retail. No buyer's commission payable.  (88)
Every bakkie DEKRA inspected with a full condition report. Bid online via live webcast.  (87)
R10,000 deposit is fully refundable if you don't buy. Nationwide delivery available.  (84)
```

### Group C — Toyota Quantum & minibuses

Headlines (★ = pin to position 1):

```
Quantum at Auction Prices       25  ★
Repossessed Toyota Quantum      26  ★
Toyota Quantum on Auction       25
Bank Repo Quantum for Sale      26
Quantum Minibuses Live Now      26
Quantum 2.5 D-4D and 2.8 GD-6   29
Bid at the Bank's Price         23
Every Quantum DEKRA Inspected   29
No Buyer's Commission           21
Register Free to Bid            20
Bid Online via Live Webcast     27
Nationwide Delivery Available   29
R10,000 Refundable Deposit      26
Verified in 1-2 Business Days   29
Auction Hub Group               17
```

Descriptions:

```
Repossessed Toyota Quantum minibuses on auction. Bank stock at the outstanding balance.  (87)
Every Quantum DEKRA inspected with a full condition report. Bid online via live webcast.  (88)
No buyer's commission. Hammer price plus R2,600 admin and VAT. Nationwide delivery.  (83)
R10,000 deposit is fully refundable if you don't buy. Verified in 1-2 business days.  (84)
```

## 8. Sitelinks, callouts and other assets

### Sitelinks

| Text (25) | Line 1 (35) | Line 2 (35) | URL |
|---|---|---|---|
| Browse Live Auctions | Every vehicle open for bidding | right now, with photos. | `/auctions.php` |
| How Bidding Works | Register, get verified, bid. | The full process explained. | `/auction-guide.php` |
| Toyota Hilux Lots | Every repossessed Hilux | currently on auction. | `/auctions.php?search=hilux` |
| Toyota Quantum Lots | Repossessed Quantum minibuses | on auction now. | `/auctions.php?search=quantum` |
| Our Branches | Durban, Gqeberha, Bloemfontein | and Centurion head office. | `/about.php#branches` |
| Pre-Auction Deals | Buy before it reaches | the auction floor. | `/preauction.php` |

### Callouts

```
No Buyer's Commission
DEKRA Inspected
Refundable Deposit
Free Registration
Bid Online via Webcast
Bank Repossessed Stock
Nationwide Delivery
Verified in 1-2 Days
```

### Structured snippets

- **Models**: Hilux, Quantum, Ranger, Fortuner, Avanza, Polo
- **Types**: Bakkies, Minibuses, SUVs, Hatchbacks, Panel Vans, Trucks

### Call asset

`+27 10 516 0683`, business hours only, with call reporting on. WhatsApp `+27 83 670 9292` can go in a **Message** asset if your account offers it.

Left out on purpose: in-house finance (policy risk) and past selling prices.

## 9. Conversion tracking

Create these under **Goals → Conversions → + New conversion action → Website**, set up manually.

| Action | Category | Primary? | Value | Count | Fires when |
|---|---|---|---|---|---|
| Bidder registration | Sign-up | Primary | R350 | One | A new account is created (`auth.php`) |
| WhatsApp click | Contact | Secondary | none | One | Any WhatsApp button |
| Call click | Contact | Secondary | none | One | A phone number is tapped |

Each action gives a tag with `send_to: 'AW-3636429749/AbC-D_efG'`. Paste the part **after the slash** into `config.php` and deploy:

```
define('GADS_LABEL_REGISTRATION', 'paste_me');
define('GADS_LABEL_WHATSAPP',     'paste_me');
define('GADS_LABEL_CALL',         'paste_me');
```

### After the test: import the bidders who paid

A sign-up is cheap. A bidder who pays the R10,000 deposit is the outcome you want. The site stores the Google click ID on each user, so you can send those bidders back to Google. Create an **Import → Track conversions from clicks** action called **Verified bidder**, then run this in phpMyAdmin, export as CSV and upload it under Conversions → Uploads:

```
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

## 10. Budget and what to expect

|  | Low | High |
|---|---|---|
| Spend | R1,000 | R1,000 |
| Average cost per click | R8 | R5 |
| Clicks | ~125 | ~200 |
| Visitors who register | 2.5% | 3.5% |
| Registrations | ~3 | ~7 |
| Cost per registration | ~R330 | ~R145 |

> These are planning estimates, not quotes. Clicks outside Gauteng are usually cheaper because fewer auction houses bid there, but 10 days and R1,000 is a small sample. Judge keywords by their search terms and click-through rate as much as by registrations.

Maximise clicks spreads one budget across all three groups. Group A will probably take the most. If it takes over 70% by day 5, lower its keywords' share by pausing its weakest city keywords, not by raising the budget.

## 11. The 10 days

Tick each step as you go. Ticks are saved in this browser only.

- [ ] **Day 0** — Conversion labels pasted and `config.php` deployed. Six negative lists built. Hilux and Quantum pages checked for stock. Western Cape delivery confirmed or Western Cape removed.
- [ ] **Day 1** — Launch all three groups. Check that every ad reads *Eligible* within a few hours and that Locations shows only the four provinces.
- [ ] **Day 2** — Search terms report (Insights and reports → Search terms). Add every irrelevant term to the right shared list.
- [ ] **Day 3** — Test conversion: open `/register-to-bid.php?gclid=test123` on your phone, register a test bidder, and check that Bidder registration shows *Recording conversions* within 3 hours. Then delete the test user.
- [ ] **Day 4** — Search terms and negatives. Check average CPC against the R8 cap and *Impression share lost (budget)*.
- [ ] **Day 5** — Mid-test review. Pause **watch** keywords that took a quarter of the spend with no registration. Re-check that the Hilux and Quantum pages still have stock.
- [ ] **Day 6** — Search terms and negatives. Open the Locations report (user location): which province brings the clicks?
- [ ] **Day 7** — In Admin, check the new ad registrants. Who has uploaded proof of payment, and who hasn't?
- [ ] **Day 8** — Search terms and negatives. Replace any headline Google rates *Low* in the asset report.
- [ ] **Day 9** — Call or WhatsApp every ad registrant who hasn't paid the deposit (Admin → Leads).
- [ ] **Day 10** — Final review. Fill in clicks, CTR, registrations, deposits paid and cost per registration, by group and by province, and use the table below.

## 12. Day 11: what to do with the results

| After 10 days | What it means | Do this next |
|---|---|---|
| 3+ registrations at under R350 each | The test works | Run 14 more days at R150/day. After 15 registrations switch to **Maximise conversions** |
| 1–2 registrations, CTR above 5% | The ads attract; the page doesn't convert | Another 10 days at R100/day. Test the register page on a phone and call every sign-up |
| Plenty of clicks, 0 registrations, tracking test passed | Wrong searches or wrong page | Read the search terms, pause every **watch** keyword, and point group B at `/register-to-bid.php` for 10 days to compare |
| CTR under 3% | The ads don't match the searches | Rewrite headlines using the words in the search terms report. Keep the pins |
| Spends under R70 a day, CPC at the R8 cap | You're being outbid | Raise the cap to R10, or add more phrase-match keywords |
| One province brings most registrations | That's where demand is | Next round: that province only, at the same R100/day |
| Hilux page below 10 lots, or Quantum below 4 | Not enough stock behind the ad | Pause that group until new stock lands |

Watch the deposit rate as well. Registrations that never pay the R10,000 deposit don't count as success. Run the verified-bidder import in section 9 once the first ad registrants are approved.

## 13. Policy rules

| Risk | What happens | Rule |
|---|---|---|
| "No credit checks" or "blacklisted welcome" (on `financing.php`) | Google's financial-services policy can suspend the **whole account** | Never in ad text, never a sitelink, never a landing page |
| Bank names (ABSA, FNB, WesBank…) or the old company name in ad text | Ad disapproved for trademarks | These may be keywords. Never ad copy |
| Price claims the landing page doesn't make | Ad disapproved | No rand figures in headlines except the R10,000 deposit |
| "Stolen", "impounded", "police auction" | Misleading, and the wrong buyer | Stay with repossessed, bank stock, liquidation |
| Taxi route permits | Buyers think a route comes with the minibus | Never imply one does. Permit words are in negative list 6 |

