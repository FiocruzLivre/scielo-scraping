# Update scraper for www.scielo.br's current template

**Goal:** Make `ScieloClient`/`Article` parse `www.scielo.br`'s current site template, so `scielo:download-all`/`scielo:download-binary` work again for any journal/issue — not just the one issue recovered by hand on 2026-10-06/07.

**Why:** `www.scielosp.org` (the scraper's original host) is now behind Bunny Shield's bot-protection challenge and permanently unreachable by a plain HTTP client. `www.scielo.br` is reachable, but serves a different template entirely. This was already worked around once, by hand, for a single issue (1998 v14 suppl.3 / OJS issue_id 66) — this plan turns that one-off recovery into permanent, reusable scraper code.

**Spec:** This document. No separate spec file — the "what changed on SciELO's side" findings below were verified directly against the live site (`www.scielo.br`) across both a 1998 issue and a 2024 issue, not assumed.

**Branch:** `scielo-br-template` (already created off `ojs-3.4`, which carries the PHP 8.2 bump this also needs; first commit already made: switching the default host).

## What actually changed on SciELO's side (verified)

| Concern | Old template (`scielosp.org`) | New template (`scielo.br`) |
|---|---|---|
| Issue grid/listing page (`/j/{slug}/grid`) | `#issueList table tbody tr` | **Unchanged** — same selector still matches |
| Article discovery within an issue page | `.articles>li` container, `a[href^="/article/"]`/`a[href^="/pdf/"]` | No `.articles` class at all. Articles instead appear as `/j/{slug}/a/{opaqueId}/...` links (verified on both a 1998 and a 2024 issue page) |
| Article id | Page-range string parsed from the old URL path (e.g. `629-640`) | Opaque ~23-char base62-ish code (e.g. `mMBFtKqzbFVFQCr95kV8mKR`), stable per article, used in every URL for that article |
| PDF download URL | `/pdf/{slug}/{issueName}/{pages}/{lang}` | `/j/{slug}/a/{id}/?format=pdf&lang={lang}` |
| Full HTML text URL | separate HTML page, `#standalonearticle` body | **Same `#standalonearticle` container id**, just reached via `/j/{slug}/a/{id}/?lang={lang}&ilang=pt_BR` (no `format=` param, or `format=html` also works) |
| Article metadata (doi/title/abstract/keywords/authors/language/pub-date) | `meta[name="citation_*"]` tags | **Same citation_\* meta tags, same names**, still present on the article page |
| Author affiliation | `.contribGroup span[class="dropdown"]`, paired by position | `meta[name="citation_author"]` + `meta[name="citation_author_affiliation"]`, same count, paired by position |
| Embedded figure/image URLs | `/media/assets/csp/...` | `https://minio.scielo.br/documentstore/{issn}/{articleId}/{hash}.{ext}` |
| Per-article language availability | — | Confirmed: an article typically has exactly **one** full-text/PDF language (the issue page lists one `format=pdf` link per article id), but can have abstract-only translations at `/j/{slug}/a/{id}/abstract/?lang={other}` — out of scope here, the old scraper never captured those either |

Net effect: article **discovery** (how to find the list of articles in an issue, and their PDF/HTML/image URLs) is what actually broke. Metadata extraction and full-text body extraction are *almost* unchanged — same meta tag names, same `#standalonearticle` container id. This is a narrower fix than a full scraper rewrite.

## Global Constraints

- No automated test suite exists for this project (`composer run test` only runs `phpcs`) — every task below is verified by hand against the real site, scoped to a single already-known issue/article to avoid unnecessary load on SciELO's infrastructure, plus a final full run against a second, different (non-1998) issue to confirm the fix isn't overfit to one recovery case.
- Keep `ScieloClient`'s public method signatures (`getGrid()`, `saveAllMetadata()`, `downloadAllBinaries()`, `getIssue()`) and `Article`'s public methods (`load()`, `downloadBinaries()`) unchanged — `BaseCommand`/`DownloadAllCommand`/`DownloadBinaryCommand`/`ImportCommand` all consume this class and should not need to change at all.
- Do not touch `src/Command/Ojs/ImportCommand.php` or `assets/template.html` — nothing about the OJS-import side changes; only the SciELO-side fetching/parsing does.
- Match PSR-12 (existing `composer run phpcs`).

## Review Focus

- **Author/affiliation count mismatch**: `citation_author` and `citation_author_affiliation` meta tags are paired by array position, same as the manual recovery script did — but nothing guarantees every author has exactly one affiliation tag (co-authors can share one affiliation tag, or have none). The manual script used `isset($authorAffils[$i])` defensively; the permanent code must do the same, not assume equal counts.
- **Multi-format articles**: most articles have exactly one PDF language and, separately, may or may not also expose the same article at `#standalonearticle` (full HTML). Don't assume an article has both — some of the 21 recovered articles had an HTML body but it's not guaranteed every article across the whole site does.
- **Image URLs outside the content area**: the article page has several non-content images (open-access badge, funder logos, license buttons) on domains other than `minio.scielo.br/documentstore`. The filter must stay scoped to that specific CDN path, not "any `<img>` in the page" — already proven correct by the manual recovery (4 real figures correctly captured, 2 chrome images correctly ignored on the same page).
- **DOI-suffix-based filenames**: `ArticleService::getEndOfDoi()`/`getBinaryDirectory()` already derive the on-disk layout from the DOI, which is unaffected by any of this — don't change `ArticleService`, only how `ScieloClient`/`Article` populate it.
- **Issue-level grid parsing is NOT broken** — resist the temptation to "fix" `getGrid()`/`getIssueCrawlers()`; they already work against the new host unchanged (verified: `#issueList` still matches). Only the per-issue article-discovery and per-article extraction need to change.

---

## File Structure

- **Modify:** `src/ScieloClient.php` — `getIssueFromHtml()` (article discovery within an issue page), `getArticleId()` (becomes unnecessary/replaced), `getTextPdfUrl()` (PDF/text URL construction).
- **Modify:** `src/Parser/Article.php` — `incrementMetadata()` (author/affiliation pairing via meta tags instead of DOM classes — citation_doi/title/keywords selectors already work as-is, confirm during Task 2), `getAllAssets()` (new image URL filter), `getRawCrawler()`/`downloadBinaries()` URL construction (new `?lang=`/`?format=` query pattern instead of old path-based URLs).
- No changes to `ArticleService.php`, `ImportCommand.php`, `assets/template.html`, or any `Command/Scielo/*Command.php` (their option/argument surface is unaffected).

---

### Task 1: Replace article discovery in `ScieloClient::getIssueFromHtml()`

**Files:**
- Modify: `src/ScieloClient.php`

**Interfaces:**
- Consumes: the issue page HTML already fetched by `getIssueCrawlers()` (unchanged).
- Produces: same per-article `Article` construction and population this method already does (`setId`, `setDoi`, `setYear`, `setVolume`, `setIssueName`, `setCategory`, `setResume`, `setFormats`, `setAuthors`, `setPublished`, `setUpdated`) — callers (`saveAllMetadata()`/`downloadAllBinaries()`/`ImportCommand`) see no change.

Current (`getIssueFromHtml()`, relevant excerpt):

```php
    private function getIssueFromHtml(string $url, string $year, string $volume, string $issueName, $articleId = null)
    {
        $crawlers = $this->getIssueCrawlers($url, $year, $volume, $issueName);
        $crawlers['html']->filter('.articles>li')
            ->each(function (Crawler $node, $index) use ($year, $volume, $issueName, $articleId, $crawlers) {
                $id = $this->getArticleId($node);
                if (($articleId && $articleId != $id) || !$id) {
                    if (!$id) {
                        $this->logger->error('Article ID is null', [
                            'index' => $index,
                            'year' => $year,
                            'volume' => $volume,
                            'issueName' => $issueName,
                            'method' => 'getIssueFromHtml'
                        ]);
                    }
                    return;
                }
                $article = new Article([
                    'base_directory' => $this->settings['base_directory'],
                    'assets_folder' => $this->settings['assets_folder'],
                    'logger' => $this->logger,
                    'browser' => $this->browser
                ]);
                $doi = $crawlers['xml']->filter('entry')->eq($index)->filter('id')->text();
                $article->load($year, $volume, $issueName, $id, $doi);
                $article->setId($id);
                $article->setDoi($doi);
                $article->setYear($year);
                $article->setVolume($volume);
                $article->setIssueName($issueName);
                $article->setCategory(strtolower($node->filter('h2 span')->text('article')) ?: 'article');
                $article->setResume($this->getResume($node));
                $article->setFormats($this->getTextPdfUrl($node));
                $article->setAuthors($node->filter('a[href*="//search"]')->each(fn($a) => ['name' => $a->text()]));
                $article->setPublished($this->getPublishedDate($crawlers['xml'], $index, $node));
                $updated = $crawlers['xml']->filter('entry')->eq($index)->filter('updated')->text();
                $updated = (\DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $updated));
                // The date from FeedRSS xml was wrong, to every articles return 2017 in case of CSP Journal.
                if ($updated->format('Y-m-d') === '2017-01-28') {
                    $article->setUpdated($article->getPublished());
                } else {
                    $article->setUpdated($updated->format('Y-m-d H:i:s'));
                }
            });
    }
```

This whole method assumed the issue-listing page's DOM carried everything (resume, authors, category, dates) per article via the old per-issue list markup, cross-referenced against the RSS feed (`$crawlers['xml']`) by list position (`eq($index)`). That per-position cross-reference is fragile against the new template regardless of markup changes (the new page may order/paginate articles differently than the RSS feed does) and the new template simply doesn't carry per-article resume/category/author data on the *listing* page at all — only a link to the article's own page. So this task replaces the whole discovery+population strategy: discover article ids+languages from the issue page, then populate each one from its own page (where `citation_*` tags are reliable), matching what the manual recovery already proved works.

Replace with:

```php
    private function getIssueFromHtml(string $url, string $year, string $volume, string $issueName, $articleId = null)
    {
        $crawlers = $this->getIssueCrawlers($url, $year, $volume, $issueName);
        $articleLangs = [];
        $crawlers['html']->filter('a[href*="/a/"][href*="format=pdf"]')->each(function (Crawler $a) use (&$articleLangs) {
            $href = $a->attr('href');
            if (!preg_match('#/a/([A-Za-z0-9]+)/#', $href, $m)) {
                return;
            }
            $id = $m[1];
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $articleLangs[$id] = $query['lang'] ?? 'pt';
        });

        foreach ($articleLangs as $id => $listedLang) {
            if ($articleId && $articleId != $id) {
                continue;
            }
            $article = new Article([
                'base_directory' => $this->settings['base_directory'],
                'assets_folder' => $this->settings['assets_folder'],
                'logger' => $this->logger,
                'browser' => $this->browser,
                'http_host' => $this->settings['http_host'],
            ]);
            $article->setId($id);
            $article->setYear($year);
            $article->setVolume($volume);
            $article->setIssueName($issueName);
            $article->loadFromArticlePage($issueName === '' ? $this->settings['journal_slug'] : $this->settings['journal_slug'], $id, $listedLang);
        }
    }
```

(`loadFromArticlePage()` is new — see Task 2. It replaces `incrementMetadata()`'s old role of "fill in whatever's missing from the already-loaded article DOM node" with "fetch the article's own page and populate everything from it," since the new template has nothing worth reading on the listing page itself.)

Delete `getArticleId()` entirely (no longer called — article ids now come directly from the href regex above, not from parsing an `<a href="/article/...">`/`/pdf/...` pattern).

Delete `getPublishedDate()` and the `$crawlers['xml']`/RSS cross-referencing in this method — `citation_publication_date` (fetched in Task 2) replaces it. Keep `getIssueCrawlers()`'s RSS fetch itself (`$crawlers['xml']`) only if Task 2 still needs it for anything; if not, simplify `getIssueCrawlers()` to stop fetching the RSS feed at all (confirm during implementation whether anything still reads `$crawlers['xml']`).

- [ ] Verify: run against the already-recovered 1998 v14 suppl.3 issue (`--year=1998 --volume=14 --issue=1998.v14suppl3`) and confirm all 21 known article ids are discovered with the correct listed language (cross-check against the hand-built metadata files still on disk, before they're overwritten).
- [ ] Verify: run against one 2024 issue (e.g. `2024.v40n3`, already confirmed reachable above) and confirm a plausible number of articles are discovered (sanity check only — no hand-built reference data exists for this one).

---

### Task 2: Populate an `Article` from its own page (metadata + format URLs)

**Files:**
- Modify: `src/Parser/Article.php`

**Interfaces:**
- Consumes: `$this->getId()`, `$this->getBasedir()` (unchanged, from `ArticleService`), a journal slug and listed language (passed in).
- Produces: fully populated `doi`, `title`, `resume`, `keywords`, `authors`, `published`, `updated`, `formats` — same shape `ImportCommand`/`ArticleService::loadFromFile()` already expect, since this is the exact shape the manual recovery script already wrote into 21 real metadata files that `ojs:import` successfully consumed unmodified.

Add a new public method (replaces the old `incrementMetadata()`'s call sites — keep `incrementMetadata()` itself only if Task 3's body-extraction step still wants it for something; otherwise fold its logic in here and delete it):

```php
    public function loadFromArticlePage(string $journalSlug, string $id, string $listedLang): self
    {
        $pageUrl = $this->getBaseUrl() . "/j/{$journalSlug}/a/{$id}/?lang={$listedLang}&ilang=pt_BR";
        $html = $this->fetchPage($pageUrl);
        $crawler = new Crawler($html);

        $doi = $this->metaContent($crawler, 'citation_doi');
        if (!$doi) {
            $this->logger->error('Without DOI', ['method' => 'Article::loadFromArticlePage', 'id' => $id]);
            return $this;
        }
        $this->setDoi($doi);

        $langMap = ['pt' => 'pt_BR', 'en' => 'en_US', 'es' => 'es_ES'];
        $lang = $langMap[$this->metaContent($crawler, 'citation_language') ?? $listedLang] ?? $listedLang;

        $this->setTitle($this->metaContent($crawler, 'citation_title') ?: '', $lang);
        $this->setResume($this->metaContent($crawler, 'citation_abstract') ?: '', $lang);
        $this->setKeywords($this->metaContentAll($crawler, 'citation_keywords'), $lang);

        $authorNames = $this->metaContentAll($crawler, 'citation_author');
        $authorAffils = $this->metaContentAll($crawler, 'citation_author_affiliation');
        $authors = [];
        foreach ($authorNames as $i => $name) {
            $row = ['name' => $name];
            if (isset($authorAffils[$i]) && $authorAffils[$i] !== '') {
                $row['foundation'] = $authorAffils[$i];
            }
            $authors[] = $row;
        }
        $this->setAuthors($authors);

        $pubDate = $this->metaContent($crawler, 'citation_publication_date') ?: '1998';
        $pubDate = preg_match('/^\d{4}$/', $pubDate) ? $pubDate . '-01-01 00:00:00' : $pubDate;
        $this->setPublished($pubDate);
        $this->setUpdated($pubDate);

        $formats = ['pdf' => [$lang => "/j/{$journalSlug}/a/{$id}/?format=pdf&lang={$listedLang}"]];
        if ($crawler->filter('#standalonearticle')->count() > 0) {
            $formats['text'] = [$lang => "/j/{$journalSlug}/a/{$id}/?lang={$listedLang}&ilang=pt_BR"];
        }
        $this->setFormats($formats);

        return $this;
    }

    private function metaContent(Crawler $crawler, string $name): ?string
    {
        $all = $this->metaContentAll($crawler, $name);
        return $all[0] ?? null;
    }

    private function metaContentAll(Crawler $crawler, string $name): array
    {
        return $crawler->filter("meta[name=\"{$name}\"]")->each(fn (Crawler $m) => $m->attr('content'));
    }
```

(`fetchPage()` is a small new private helper wrapping the existing `$this->getBrowser()->request('GET', $url)` pattern already used elsewhere in this class, with the same retry-on-transient-error behavior the manual recovery script added — reuse `getRawCrawler()`'s existing try/catch-and-log pattern for the network call itself rather than inventing a new one.)

Note the `published` year-only padding (`$pubDate . '-01-01 00:00:00'`) is **required**, not cosmetic — `ImportCommand::insertSubmissionAndPublication()` writes `$article->getPublished()` straight into `publication.date_published` with no padding of its own (confirmed directly: this exact gap crashed the recovery import on 2026-10-06 with `SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '1998'`). Don't regress this.

- [ ] Verify: call this method directly for one already-known article (`mMBFtKqzbFVFQCr95kV8mKR`, DOI `...700017`) and diff the resulting in-memory `Article` state against the hand-built `metadata_S0102-311X1998000700017.json` still on disk — title/abstract/keywords/authors/doi should match exactly.

---

### Task 3: Fix embedded-image URL matching in `Article::getAllAssets()`

**Files:**
- Modify: `src/Parser/Article.php`

**Interfaces:** none external — this is a private method, same call site (`downloadBinaries()`'s `'text'` case) unchanged.

Current:

```php
    private function getAllAssets(Crawler $crawler)
    {
        $path = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory()
        ]);
        if (!is_dir($path)) {
            mkdir($path);
        }
        $crawler->filter('img')->each(function ($img) use ($path) {
            $src = $img->attr('src');

            if (str_starts_with($src, "/media/assets/csp/")){
                $filename = $path . DIRECTORY_SEPARATOR . basename($src);
                if (file_exists($filename)) {
                    return;
                }
                $this->downloadBinaryAssync($src, $filename);
            }
        });
        $crawler->filter('a')->each(function ($img) use ($path) {
            $src = $img->attr('href');
            if (str_starts_with($src, "/media/assets/csp/")){
                $filename = $path . DIRECTORY_SEPARATOR . basename($src);
                if (file_exists($filename)) {
                    return;
                }
                $this->downloadBinaryAssync($src, $filename);
            }
        });
        $crawler->filter('.modal-body object')->each(function ($img) use ($path) {
            $src = $img->attr('data');
            $filename = $path . DIRECTORY_SEPARATOR . basename($src);
            if (file_exists($filename)) {
                return;
            }
            $this->downloadBinaryAssync($src, $filename);
        });
    }
```

Replace the `str_starts_with($src, "/media/assets/csp/")` checks (both occurrences) with:

```php
str_contains($src, 'minio.scielo.br/documentstore')
```

Leave the `.modal-body object` branch as-is for now (not verified against the new template — no article in the recovered batch exercised it; flag as untested rather than guessing at a new selector).

Also: these image URLs are now **absolute** (`https://minio.scielo.br/...`), not root-relative (`/media/assets/csp/...`) — confirm `downloadBinaryAssync()`'s `getBaseUrl() . $url` concatenation (used for the old relative URLs) isn't applied to these; `downloadBinaryAssync()` should request `$src` directly when it's already an absolute URL. Check this during implementation; if `getBaseUrl()` prefixing happens unconditionally today, gate it on `str_starts_with($src, '/')`.

Also update `formatHtml()` (currently strips `/media/assets/csp\S+/` path prefixes to bare filenames via regex) to also strip the new `minio.scielo.br/documentstore/...` prefix down to a bare filename — or better, since `Task 2`'s body-extraction (Task 4) already does this rewrite explicitly (matching the manual recovery script's `str_replace($src, $filename, $bodyHtml)` approach) rather than via a blanket regex, consider whether `formatHtml()` is still needed at all once Task 4 lands. Don't delete it without checking whether anything else calls it.

- [ ] Verify: reprocess article `x74t6HZWCJMFPvYDsrxMMqx` (DOI `...700004`, known to have 4 real figures) and confirm all 4 download with the same filenames/sizes already captured by the manual recovery (`22c1e082c2298bb00d5f2dab4e388aa0aa6a34c0.gif` = 14816 bytes, etc.) — this is a cheap, exact regression check since the reference data already exists on disk.

---

### Task 4: Fix full-text body extraction to use the new URL and rewrite embedded image paths

**Files:**
- Modify: `src/Parser/Article.php`

**Interfaces:** `downloadBinaries()` (public, unchanged signature) → still produces `{basedir}/{binaryDir}/{lang}.html` and `{basedir}/{binaryDir}/{lang}.pdf`, same as before.

Current `downloadBinaries()`'s `'text'` case calls `getRawCrawler($url, $lang)` where `$url` comes from `$this->getFormats()['text'][$lang]` (a relative path like the old `/article/...`). With Task 2 now storing `/j/{slug}/a/{id}/?lang={lang}&ilang=pt_BR` there instead, `getRawCrawler()`'s request should work unchanged (it already does `$this->getBrowser()->request('GET', $url)` against whatever URL is stored) — the main change needed is in `extractBody()`'s image-reference rewriting, to match what the manual recovery proved necessary: rewrite each `<img src="...">` inside the extracted body to the bare filename it was saved under, not leave it pointing at the live CDN URL.

Current `extractBody()`:

```php
    private function extractBody(Crawler $crawler, string $lang)
    {
        $bodyFilename = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory(),
            $lang . '.html'
        ]);
        if (file_exists($bodyFilename)) {
            return;
        }
        $selectors = [
            '#standalonearticle'
        ];
        $html = '';
        foreach ($selectors as $selector) {
            try {
                $html .= $crawler->filter($selector)->outerHtml();
            } catch (\Throwable $th) {
                $this->logger->error('Invalid selector', [
                    'method' => 'getAllArcileData',
                    'selector' => $selector,
                    'directory' => $this->getBasedir()
                ]);
            }
        }
        $html = str_replace('{{body}}', $html, $this->getTemplate());
        file_put_contents($bodyFilename, $html);
    }
```

The `#standalonearticle` selector itself is confirmed unchanged and correct. Add image-reference rewriting before the final `str_replace('{{body}}', ...)`, matching the manual recovery script exactly:

```php
        $binaryDir = implode(DIRECTORY_SEPARATOR, [$this->getBasedir(), $this->getBinaryDirectory()]);
        $crawler = new Crawler('<div>' . $html . '</div>');
        $crawler->filter('img')->each(function (Crawler $img) use (&$html, $binaryDir) {
            $src = $img->attr('src');
            if (!$src || !str_contains($src, 'minio.scielo.br/documentstore')) {
                return;
            }
            $filename = basename((string) parse_url($src, PHP_URL_PATH));
            $html = str_replace($src, $filename, $html);
        });
```

(This only rewrites the saved HTML's references; actually *downloading* those same images is `getAllAssets()`'s job from Task 3, called separately by `downloadBinaries()`'s existing flow — confirm the call order already has `getAllAssets()` run before/alongside `extractBody()` so the files exist at the filenames being referenced, same as the manual recovery did both steps per article.)

- [ ] Verify: reprocess the same 1998 v14 suppl.3 issue end-to-end (`scielo:download-all` + `scielo:download-binary`) and diff every resulting `{lang}.html` file against the hand-recovered ones already on disk — they should be near-identical (same body text, same rewritten image filenames), modulo whitespace/attribute-ordering differences from using `Crawler::outerHtml()` consistently instead of mixing manual string ops.
- [ ] Verify: reprocess one 2024 issue's articles and confirm no PHP errors/warnings across a full `download-all` + `download-binary` + `ojs:import --import-without-keywords` dry run against a scratch/test OJS database (not the real one) — sanity check that the rewrite generalizes beyond the one 1998 supplement it was built against.

---

### Task 5: Full regression pass against the known-good recovery data

**Files:** none (verification only).

- [ ] Clear `scielo-scraping/output/1998/` entirely and re-run the full pipeline from scratch: `scielo:download-all csp --year=1998 --volume=14`, then `scielo:download-binary csp --year=1998 --volume=14`.
- [ ] For each of the 21 known articles, diff the freshly-generated `metadata_*.json` against the hand-built ones from 2026-10-06/07 (title/doi/abstract/keywords/authors/formats should match field-for-field; `updated`/`published` should both be full `YYYY-MM-DD HH:MM:SS` strings, not bare years).
- [ ] Diff file sizes of every downloaded PDF and image against the hand-recovered versions (should match exactly — same source, same bytes).
- [ ] Run `composer run phpcs` and fix anything flagged in the touched files.
- [ ] Only once all of the above match: run `ojs:import` against a **scratch/test** OJS database (not the real `ojs` database this recovery touched) and confirm it completes with exit 0, same as the manual recovery's two `ojs:import` runs did.

### Task 6: Hand off

- [ ] Push `scielo-br-template` to `origin`.
- [ ] Do not merge into `ojs-3.4` or `main` without running Task 5 against a *different* journal too (this plan and its verification data are entirely CSP-specific; confirm another SciELO journal's `/j/{slug}/...` pages follow the same template before calling this general-purpose).
