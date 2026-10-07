<?php

namespace ScieloScrapping\Parser;

use ScieloScrapping\Service\ArticleService;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;

class Article extends ArticleService
{
    /**
     * http browser
     *
     * @var HttpBrowser
     *
     */
    private $browser;
    private $template;

    private $settings = [
        'assets_folder' => 'assets'
    ];

    public function __construct(array $settings)
    {
        parent::__construct($settings);

        if (isset($settings['browser'])) {
            $this->browser = $settings['browser'];
            unset($settings['browser']);
        }

        $this->settings = array_merge($this->settings, $settings);
    }

    private function getBrowser(): HttpBrowser
    {
        if (!$this->browser) {
            $this->browser = new HttpBrowser(HttpClient::create());
        }
        return $this->browser;
    }

    public function load(string $year, string $volume, string $issueName, string $articleId, string $doi)
    {
        if (!$doi) {
            $this->logger->error(
                'DOI not found',
                [
                    'year' => $year,
                    'volume' => $volume,
                    'issueName' => $issueName,
                    'articleId' => $articleId,
                    'doi' => $doi,
                    'method' => 'Article::load'
                ]
            );
            throw new \Exception('DOI not found', 1);
        }
        $this->setYear($year);
        $this->setVolume($volume);
        $this->setIssueName($issueName);
        $this->setId($articleId);
        $outputDir = $this->getBasedir();
        if (!is_dir($outputDir)) {
            return false;
        }
        $this->setDoi($doi);
        $jsonFile = $outputDir . DIRECTORY_SEPARATOR . $this->getMetadataFilename();
        if (!file_exists($jsonFile)) {
            return false;
        }
        return $this->loadFromFile($jsonFile);
    }

    /**
     * Fetch an article's own page and populate everything about it: doi,
     * title, abstract, keywords, authors, dates, and the pdf/text format
     * URLs. The current www.scielo.br template carries none of this on the
     * issue listing page (unlike the old template), but keeps standard
     * citation_* meta tags on the article page itself - the same convention
     * search engines rely on, which is presumably why it survived the
     * redesign even though the surrounding markup didn't.
     */
    public function loadFromArticlePage(string $journalSlug, string $id, string $listedLang): self
    {
        $url = "/j/{$journalSlug}/a/{$id}/?lang={$listedLang}&ilang=pt_BR";
        $crawler = $this->fetchPage($url, $id);
        if (!$crawler) {
            return $this;
        }

        $doi = $this->metaContent($crawler, 'citation_doi');
        if (!$doi) {
            $this->logger->error('Without DOI', ['method' => 'Article::loadFromArticlePage', 'id' => $id]);
            return $this;
        }
        $this->setDoi($doi);
        $this->setCategory(strtolower($this->metaContent($crawler, 'citation_article_type') ?: 'article'));

        $pubDate = $this->metaContent($crawler, 'citation_publication_date');
        if ($pubDate) {
            $pubDate = preg_match('/^\d{4}$/', $pubDate) ? $pubDate . '-01-01 00:00:00' : $pubDate;
            $this->setPublished($pubDate);
            $this->setUpdated($pubDate);
        } else {
            $this->logger->error('Without publication_date', ['method' => 'Article::loadFromArticlePage', 'id' => $id]);
        }

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
        if ($authors) {
            $this->setAuthors($authors);
        }

        $lang = $this->extractLanguageMetadata($crawler, $listedLang, $id);

        $formats = ['pdf' => [$lang => "/j/{$journalSlug}/a/{$id}/?format=pdf&lang={$listedLang}"]];
        if ($crawler->filter('#standalonearticle')->count() > 0) {
            $formats['text'] = [$lang => $url];
        }

        // Some articles have full text in only one language but are still
        // indexed with a translated title/abstract/keywords for the others -
        // SciELO exposes those via a separate, lightweight /abstract/ page
        // per language rather than on the main article page itself.
        $otherLangs = [];
        $crawler->filter('a[href*="/abstract/"]')->each(function (Crawler $a) use (&$otherLangs, $listedLang) {
            $href = $a->attr('href');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $otherLang = $query['lang'] ?? null;
            if ($otherLang && $otherLang !== $listedLang) {
                $otherLangs[$otherLang] = true;
            }
        });
        foreach (array_keys($otherLangs) as $otherListedLang) {
            $abstractUrl = "/j/{$journalSlug}/a/{$id}/abstract/?lang={$otherListedLang}&ilang=pt_BR";
            $abstractCrawler = $this->fetchPage($abstractUrl, $id);
            if ($abstractCrawler) {
                $this->extractLanguageMetadata($abstractCrawler, $otherListedLang, $id);
            }
        }

        $this->setFormats($formats);

        return $this;
    }

    private function fetchPage(string $url, string $id): ?Crawler
    {
        try {
            $crawler = $this->getBrowser()->request('GET', $url);
            if ($this->getBrowser()->getResponse()->getStatusCode() == 404) {
                $this->logger->error('404', ['method' => 'Article::loadFromArticlePage', 'id' => $id, 'url' => $url]);
                return null;
            }
            return $crawler;
        } catch (TimeoutException $th) {
            $this->logger->error('timeout', ['method' => 'Article::loadFromArticlePage', 'id' => $id, 'url' => $url]);
            return null;
        }
    }

    private function extractLanguageMetadata(Crawler $crawler, string $listedLang, string $id): string
    {
        $langMap = ['pt' => 'pt_BR', 'en' => 'en_US', 'es' => 'es_ES'];
        $lang = $langMap[$this->metaContent($crawler, 'citation_language') ?? $listedLang] ?? $listedLang;

        $title = $this->metaContent($crawler, 'citation_title');
        if ($title) {
            $this->setTitle($title, $lang);
        } else {
            $this->logger->error('Without Title', ['method' => 'Article::extractLanguageMetadata', 'id' => $id, 'lang' => $lang]);
        }

        $abstract = $this->metaContent($crawler, 'citation_abstract');
        if ($abstract) {
            $this->setResume($abstract, $lang);
        }

        $keywords = $this->metaContentAll($crawler, 'citation_keywords');
        if ($keywords) {
            $this->setKeywords($keywords, $lang);
        } else {
            $this->logger->error('Without keywords', ['method' => 'Article::extractLanguageMetadata', 'id' => $id, 'lang' => $lang]);
        }

        return $lang;
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

    private function getRawCrawler(string $url, string $lang)
    {
        $rawFilename = implode(DIRECTORY_SEPARATOR, [
            $this->getBasedir(),
            $this->getBinaryDirectory(),
            $lang . '.raw.html'
        ]);
        if (file_exists($rawFilename)) {
            return new Crawler(file_get_contents($rawFilename));
        }
        try {
            $crawler = $this->getBrowser()->request('GET', $url);
            if ($this->getBrowser()->getResponse()->getStatusCode() == 404) {
                $this->logger->error('404', [
                    'method' => 'Article::getRawCrawler',
                    'article' => $this->getBasedir(),
                    'url' => $url
                ]);
                return;
            }
            file_put_contents($rawFilename, $crawler->outerHtml());
        } catch (TimeoutException $th) {
            $this->logger->error('timeout', [
                'method' => 'Article::getRawCrawler',
                'article' => $this->getBasedir(),
                'url' => $url
            ]);
            return;
        }
        return $crawler;
    }

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

            if ($src && str_contains($src, 'minio.scielo.br/documentstore')) {
                $filename = $path . DIRECTORY_SEPARATOR . basename(parse_url($src, PHP_URL_PATH));
                if (file_exists($filename)) {
                    return;
                }
                $this->downloadBinaryAssync($src, $filename);
            }
        });
        $crawler->filter('a')->each(function ($img) use ($path) {
            $src = $img->attr('href');
            if ($src && str_contains($src, 'minio.scielo.br/documentstore')) {
                $filename = $path . DIRECTORY_SEPARATOR . basename(parse_url($src, PHP_URL_PATH));
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

    private function getBaseUrl()
    {
        $protocol = $this->getBrowser()->getServerParameter('HTTPS') ? 'https' : 'http';
        $host = $this->getBrowser()->getServerParameter('HTTP_HOST');
        return $protocol . '://' . $host;
    }

    public function downloadBinaryAssync($url, $destination)
    {
        if (file_exists($destination)) {
            return;
        }
        $fileHandler = fopen($destination, 'w');
        // $client = new HttplugClient();
        // $request = $client->createRequest('GET', $this->getBaseUrl() . $url);
        // $client->sendAsyncRequest($request)
        //     ->then(
        //         function (Response $response) use ($fileHandler) {
        //             fwrite($fileHandler, $response->getBody());
        //         }
        //     );

        $absoluteUrl = str_starts_with($url, 'http') ? $url : $this->getBaseUrl() . $url;
        try {
            new AsyncResponse(
                HttpClient::create(),
                'GET',
                $absoluteUrl,
                [],
                function ($chunk, AsyncContext $context) use ($fileHandler) {
                    if ($chunk->isLast()) {
                        yield $chunk;
                    };
                    fwrite($fileHandler, $chunk->getContent());
                }
            );
        } catch (\Throwable $th) {
            $this->logger->error('Invalid request on donload binary', ['method' => 'downloadBinaryAssync', 'url' => $url]);
        }
    }

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
        // Rewrite embedded figures to the local filename getAllAssets() saves them under -
        // the OJS importer resolves embedded images as sibling dependent files by filename.
        $imgCrawler = new Crawler('<div>' . $html . '</div>');
        $imgCrawler->filter('img')->each(function (Crawler $img) use (&$html) {
            $src = $img->attr('src');
            if ($src && str_contains($src, 'minio.scielo.br/documentstore')) {
                $html = str_replace($src, basename(parse_url($src, PHP_URL_PATH)), $html);
            }
        });
        $html = str_replace('{{body}}', $html, $this->getTemplate());
        file_put_contents($bodyFilename, $html);
    }

    private function formatHtml(string $html)
    {
        return preg_replace('/\/media\/assets\/csp\S+\/([\da-z-.]+)/i', '$1', $html);
    }

    private function getTemplate()
    {
        if ($this->template) {
            return $this->template;
        }
        $this->template = file_get_contents($this->settings['assets_folder'] . DIRECTORY_SEPARATOR . '/template.html');
        return $this->template;
    }

    public function downloadBinaries()
    {
        foreach ($this->getFormats() as $format => $data) {
            foreach ($data as $lang => $url) {
                $path = $this->getBasedir() . DIRECTORY_SEPARATOR . $this->getBinaryDirectory();
                if (!is_dir($path)) {
                    mkdir($path, 0666, true);
                }
                switch ($format) {
                    case 'text':
                        $crawler = $this->getRawCrawler($url, $lang);
                        if (!$crawler) {
                            break;
                        }
                        $this->extractBody($crawler, $lang);
                        $this->getAllAssets($crawler);
                        break;
                    case 'pdf':
                        $this->downloadBinaryAssync(
                            $url,
                            $path . DIRECTORY_SEPARATOR . $lang . '.pdf'
                        );
                        break;
                }
            }
        }
    }
}
