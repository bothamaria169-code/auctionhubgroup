# AHG — Automated Trending-Car Articles + SEO Runbook

Goal: continuously publish high-quality, SEO-optimised car articles (buying guides,
model reviews, "trending now" pieces) with **tags** and **internal backlinks**, fed by
trending topics + your own auction inventory, written by an LLM, on a schedule.

> ⚠️ **Read this first — quality over volume.** Google's *scaled content abuse* policy
> (March 2024) penalises mass auto-generated content. Publish **2–4 strong articles/week**,
> each tied to your real stock or a genuine buyer question — NOT 50 thin articles/day.
> Generate as **drafts** (`is_active = 0`) and approve in admin before they go live.

---

## ✅ BUILT — what's now in the repo (PHP cron + Groq 70B)

The recommended engine is implemented. To turn it on:

1. **Add your Groq key.** In `config.php` set `GROQ_API_KEY` (free key from
   <https://console.groq.com> → API Keys). Model is already `llama-3.3-70b-versatile`.
2. **Create the tables + seed topics.** Either run `ahg_article_automation.sql` in
   phpMyAdmin, OR just run the cron once — it auto-creates the tables and seeds 5 topics.
3. **Test it** (browser): `https://auctionhubgroup.co.za/cron_generate_article.php?key=<AHG_CRON_KEY>`
   or CLI: `php cron_generate_article.php`. It writes ONE draft (`is_active=0`).
4. **Approve the draft.** It won't show on the site until approved. In phpMyAdmin or admin:
   `UPDATE news_posts SET is_active=1 WHERE id=<draft id>;` → it appears on `news.php`,
   `tag.php`, and the sitemap automatically.
5. **Schedule it** (cPanel → Cron Jobs), e.g. one article every other day at 06:00:
   `0 6 */2 * * /usr/local/bin/php /home/<cpaneluser>/public_html/cron_generate_article.php`

Files added/changed:
- `config.php` — `GROQ_API_KEY`, `GROQ_MODEL`, `AHG_CRON_KEY` constants.
- `ahg_article_automation.sql` — `news_tags`, `news_post_tags`, `article_topics` + seed topics.
- `cron_generate_article.php` — the generator (topic → Groq → draft + tags + backlinks).
- `tag.php` — tag hub pages (`/tag.php?slug=...`).
- `sitemap.php` — now also lists tag pages (news posts were already included).

**Still to wire (small):** show tag chips on `news-post.php` linking to `tag.php` (closes the
backlink loop on the article page). Ask and I'll add it.

---

## 0. Current state (what already exists)

- `news_posts` — articles. Columns that matter for SEO:
  `title, slug, excerpt, content (HTML), featured_image_url, category_id,`
  `post_type ENUM('article','review','news','rss'), source_name, author_id,`
  `is_active, is_featured, meta_title, meta_description, published_at`.
- `news_categories` (has a `buying-guides` category), `news_comments`, `rss_feeds`.
- `news-post.php` renders a post (canonical URL + Open Graph already done).
- `ahg_seed_articles.php` is the working insert pattern (manual `<a href>` backlinks).
- `sitemap.php`, `robots.txt` exist.
- `cron_close_auctions.php` proves cPanel cron + PDO already work.
- A Python bot is deployed on **Render** (`social_media_bot/`) using **Groq (free tier)**.

**Missing:** a tags system, and the article generator.

---

## 1. Schema: add tags + backlink hubs

Run once in phpMyAdmin (production: `auctionhubgroupc_db`).

```sql
-- Tags
CREATE TABLE IF NOT EXISTS news_tags (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80)  NOT NULL,
  slug       VARCHAR(80)  NOT NULL UNIQUE,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Many-to-many: post <-> tag
CREATE TABLE IF NOT EXISTS news_post_tags (
  post_id INT NOT NULL,
  tag_id  INT NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  CONSTRAINT fk_npt_post FOREIGN KEY (post_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_npt_tag  FOREIGN KEY (tag_id)  REFERENCES news_tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A topic queue feeds the generator (so it never depends solely on live Google Trends)
CREATE TABLE IF NOT EXISTS article_topics (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  topic       VARCHAR(255) NOT NULL,           -- "2019 Toyota Hilux at auction"
  primary_kw  VARCHAR(160) DEFAULT NULL,       -- target keyword
  category_id INT          DEFAULT NULL,
  source      VARCHAR(40)  DEFAULT 'manual',   -- manual | trends | inventory
  trend_score INT          DEFAULT 0,          -- higher = write sooner
  status      ENUM('queued','drafted','published','skipped') NOT NULL DEFAULT 'queued',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at     TIMESTAMP    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

---

## 2. How tags + backlinks work for SEO (the "hub & spoke" model)

Two link types do the heavy lifting:

1. **Internal backlinks inside the article body** — the generator is *instructed* to link to
   real pages from a fixed menu:
   `index.php, auctions.php, preauction.php, financing.php, auction-guide.php,`
   `sold_vehicles.php, login.php?tab=register`, plus 2–3 existing article slugs
   (`news-post.php?slug=...`). This passes "link juice" to your money pages.
2. **Tag pages = hubs.** Each tag gets a page listing every article with that tag
   (e.g. `/tag.php?slug=toyota-hilux`). Articles → tag hub → other articles. Google loves
   this clustering, and tag pages themselves rank for the keyword.

**Rule for the generator:** every article must contain **3–6 internal links** (at least one
to `auctions.php` or `preauction.php`) and be assigned **3–6 tags** (make/model, body type,
province, intent e.g. "repossessed", "buying-guide").

---

## 3. Feeding trending data (be realistic about Google Trends)

`pytrends` (unofficial) and the Trends site return **429 / blocks from server IPs**. Use a
**hybrid feed**, most-reliable first:

1. **Your own inventory (best).** Query live `vehicles`/auction stock for the makes/models
   you're actually selling → `article_topics`. Highest buyer intent, zero rate limits.
   Example topic: *"2020 Ford Ranger at auction in South Africa — what to check before you bid"*.
2. **Evergreen buyer questions (curated list).** Seed `article_topics` with the list in §6.
3. **Google Trends RSS (booster, optional).** `https://trends.google.com/trending/rss?geo=ZA`
   — free, no key, no auth. Parse it, keep rows whose title matches a car keyword
   (`car, bakkie, SUV, Toyota, VW, Ford, Hilux, Ranger, finance, repossessed...`), bump their
   `trend_score`. If it 429s, the queue still has topics 1 & 2 — generation never stops.

The cron always picks the highest `trend_score`, oldest `queued` topic. Trends is a *nice-to-have
ranker*, never a single point of failure.

---

## 4. Recommended engine — PHP cron (no Python/.env pain)

You already run `cron_close_auctions.php`, so:

- New file `cron_generate_article.php` (CLI-guarded like the seed script).
- It: picks a topic → calls the LLM (Groq free tier, or Claude for best quality) → gets
  back JSON `{title, slug, excerpt, meta_title, meta_description, html_body, tags[]}` →
  inserts into `news_posts` as **draft** (`is_active=0`) → links tags in `news_post_tags`.
- **API key**: store it in `config.php` next to the DB constants (same pattern you already
  use) — **no `.env` needed at all** on PHP.
- cPanel cron (daily, off-peak):
  ```
  /usr/local/bin/php /home/<cpaneluser>/public_html/cron_generate_article.php
  ```
- You approve drafts in admin (flip `is_active=1`) → it appears on `news.php` + sitemap.

### Alternative engine — extend the Render Python bot
Already has Groq + APScheduler + `.env`. Add `articles.py`, connect to production MySQL with
`PyMySQL`, write the same `news_posts` rows. Use this if you'd rather keep generation load off
the shared cPanel host. (APScheduler replaces cron — no cron entry needed.)

### Avoid unless required — cPanel "Setup Python App"
Passenger/WSGI + `.env` loading is fiddly, and Trends is blocked from the host anyway. Only
go here if you specifically want Python *on cPanel* rather than Render.

---

## 5. LLM model choice

- **Groq free tier** — already integrated. For long-form, use a **70B-class** model
  (`llama-3.3-70b-versatile`), NOT the 8B used for social captions (too weak for articles).
- **Claude (paid)** — best quality/SEO structure; worth it for cornerstone guides.
- Always force **JSON output** + a strict schema so the inserter is deterministic.

---

## 6. Starter topic queue (seed `article_topics`)

- How to read a DEKRA condition report before you bid
- Bank-repossessed bakkies in South Africa: Hilux vs Ranger vs D-Max
- What "voetstoots" really means at a car auction
- Auction vs dealership: total cost compared (with fees)
- First-time bidder checklist for online car auctions in SA
- Cheapest reliable family cars at auction under R150k
- How car finance works when buying at auction (link `financing.php`)
- Province-by-province: where to collect your auction vehicle
- (Auto-add per top-selling make/model from live inventory)

---

## 7. SEO TO-DO LIST

### A. Technical / on-page (do first)
- [ ] Run the §1 schema migration on production.
- [ ] Confirm every `news_posts` row has a unique `meta_title` (≤60 chars) + `meta_description` (≤155).
- [ ] Ensure `sitemap.php` includes news posts AND new `tag.php` pages; resubmit in Search Console.
- [ ] Add `<link rel="canonical">` check on `tag.php` (avoid duplicate-content with `news.php`).
- [ ] Add JSON-LD structured data to `news-post.php`: `Article` (headline, datePublished,
      author, image) and `BreadcrumbList`. Add `FAQPage` schema where the article has an FAQ.
- [ ] Verify Open Graph + Twitter Card tags render (test with the social debuggers).
- [ ] Confirm mobile-friendly + Core Web Vitals (compress `featured_image_url` images; lazy-load).
- [ ] `robots.txt` allows `/news.php`, `/news-post.php`, `/tag.php`; sitemap line present.

### B. Content / generator
- [ ] Build `cron_generate_article.php` (or `articles.py`) per §4.
- [ ] Generator inserts as **draft** (`is_active=0`) for human approval.
- [ ] Enforce: 800–1500 words, 1 `<h1>`-equiv title + `<h2>`/`<h3>` structure, 3–6 internal
      links, 3–6 tags, 1 FAQ block, 1 closing CTA to `auctions.php`/`preauction.php`.
- [ ] De-dupe: skip if slug exists OR title is >85% similar to an existing post.
- [ ] Each article targets ONE primary keyword (from `article_topics.primary_kw`).

### C. Internal linking / authority
- [ ] Build `tag.php` (hub pages) + show tag chips on `news-post.php`.
- [ ] Add a "Related articles" block (same category or shared tag) on each post.
- [ ] Link new articles from high-traffic pages (`index.php`, `auctions.php` sidebar).
- [ ] Pick 3–4 cornerstone guides; link every new article back to the relevant one.

### C2. Images (free, automated)
- [x] Featured images auto-generated in `cron_generate_article.php` via **Pollinations.ai**
      (free, no key, Flux model) → stored permanently on **Cloudinary** → `featured_image_url`.
- [ ] Note: Groq does **not** generate images (text-only). Alternatives if you want higher
      quality / real photos: **Cloudflare Workers AI** (free Flux, needs account),
      **Pexels/Unsplash API** (free, real stock car photos — often look more professional than
      AI cars, which can render warped badges/wheels). Toggle with `AHG_IMAGE_ENABLED`.
- [ ] Optional: inject 1 in-body image per article (currently featured image only).

### D1. Google News & Opera News
- **Google News:** inclusion is automatic (no manual submission since 2019) IF content meets
  policy. To manage presence, create a publication in **Google Publisher Center**
  (publishercenter.google.com) and add the site + an RSS feed. For Top Stories eligibility:
  original reporting, visible byline + date, and a **Google News sitemap** (articles from the
  last 48h, `<news:>` namespace). ⚠️ Mass AI content can be excluded — keep it
  high-quality and human-approved (see guardrails). Build `news-sitemap.php` for this.
- **Opera News:** apply via the **Opera News Hub / Publishers program** (region-dependent,
  application-reviewed). They ingest via RSS — needs an outbound RSS feed of `news_posts`.
- [ ] Build an outbound RSS feed (`feed.php`) of published articles (serves both above).
- [ ] Build `news-sitemap.php` (Google News sitemap, last 48h).

### D. Off-page / measurement
- [ ] Submit sitemap + monitor coverage/indexing weekly in Google Search Console.
- [ ] Track keyword rankings + organic clicks per article monthly; prune/refresh losers.
- [ ] Get the site listed in SA auction/car directories (real backlinks).
- [ ] Quarterly: update top articles with fresh prices/stock (freshness signal).

### E. Guardrails (avoid Google penalties)
- [ ] Cap publish rate (≤4/week initially); human approves every draft.
- [ ] No duplicated/spun content; every article must add a unique angle or your own data.
- [ ] Author attribution + "Last updated" date on every post.

---

## 8. Build order
1. §1 schema → 2. `tag.php` + tag chips/related block → 3. seed `article_topics` (§6) →
4. `cron_generate_article.php` writing drafts → 5. inventory feeder → 6. Trends-RSS booster →
7. JSON-LD + sitemap for tags → 8. Search Console submit & measure.
```
