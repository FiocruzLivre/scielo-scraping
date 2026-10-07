<?php

namespace ScieloScrapping;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ScieloScrapping\Parser\Article;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\HttpClient;

class ScieloClient
{
    /**
     * http browser
     *
     * @var HttpBrowser
     *
     */
    private $browser;
    /**
     * All data of grid
     *
     * @var array
     */
    private $grid = [];
    /**
     * Logger
     *
     * @var Logger
     */
    private $logger;
    /**
     * Current language
     *
     * @var string
     */
    private $lang;
    /**
     * Languages
     *
     * @var array
     */
    private $langs = [
        'pt_BR' => 'pt_BR',
        'pt-BR' => 'pt_BR',
        'pt' => 'pt_BR',
        'es' => 'es_ES',
        'en' => 'en_US'
    ];
    private $settings = [
        'journal_slug' => null,
        'base_directory' => 'output',
        'https' => true,
        'http_host' => 'www.scielo.br',
        'assets_folder' => 'assets',
        'logger' => null,
        'browser' => null,
        'default_language' => 'pt_BR'
    ];
    public function __construct(array $settings = [])
    {
        if (isset($settings['browser'])) {
            $this->browser = $settings['browser'];
            unset($settings['browser']);
        } else {
            $this->browser = new HttpBrowser(HttpClient::create());
            $this->browser->setServerParameter('HTTPS', $this->settings['https']);
            $this->browser->setServerParameter('HTTP_HOST', $this->settings['http_host']);
        }
        if (isset($settings['logger'])) {
            $this->logger = $settings['logger'];
            unset($settings['logger']);
        } else {
            $this->logger = new Logger('SCIELO');
            $this->logger->pushHandler(new StreamHandler('logs/scielo.log', Logger::DEBUG));
        }
        $this->settings = array_merge($this->settings, $settings);
    }

    private function getGridUrl()
    {
        return '/j/' . $this->settings['journal_slug'] . '/grid';
    }

    public function getGrid()
    {
        if ($this->grid) {
            return $this->grid;
        }
        if (file_exists($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json')) {
            $grid = file_get_contents($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json');
            $grid = json_decode($grid, true);
            if ($grid) {
                return $this->grid = $grid;
            }
        }
        $crawler = $this->browser->request('GET', $this->getGridUrl());
        $grid = [];
        $crawler->filter('#issueList table tbody tr')->each(function ($tr) use (&$grid) {
            $td = $tr->filter('td');

            $links = [];
            $td->last()->filter('.btn')->each(function ($linkNode) use (&$links) {
                $link = $linkNode->link();
                $url = $link->getUri();
                $tokens = explode('/', $url);
                $issueCode = $tokens[sizeof($tokens) - 2];
                $links[$issueCode] = [
                    'text' => $linkNode->text(),
                    'url' => $url
                ];
            });

            $grid[$td->first()->text()] = [
                $tr->filter('th')->text() => $links
            ];
        });
        if (!is_dir($this->settings['base_directory'])) {
            mkdir($this->settings['base_directory'], 0666);
        }
        ksort($grid);
        file_put_contents($this->settings['base_directory'] . DIRECTORY_SEPARATOR . 'grid.json', json_encode($grid));
        $this->grid = $grid;
        return $grid;
    }

    public function saveAllMetadata(array $selectedYears = [], array $selectedVolumes = [], array $selectedIssues = [])
    {
        $grid = $this->getGrid();
        foreach ($selectedYears as $year) {
            foreach ($selectedVolumes as $volume) {
                if (!isset($grid[$year][$volume])) {
                    continue;
                }
                foreach ($grid[$year][$volume] as $issueName => $data) {
                    if ($selectedIssues && !in_array($issueName, $selectedIssues)) {
                        continue;
                    }
                    $this->getIssue($year, $volume, $issueName);
                }
            }
        }
    }

    public function downloadAllBinaries(?string $year = '*', ?string $volume = '*', ?string $issue = '*', ?string $articleId = '*')
    {
        if (!$this->settings['base_directory'] || !is_dir($this->settings['base_directory'])) {
            return;
        }
        try {
            $finder = Finder::create()
                ->files()
                ->name('metadata_*.json')
                ->in(implode(DIRECTORY_SEPARATOR, [$this->settings['base_directory'], $year, $volume, $issue, $articleId]));
        } catch (\Throwable $th) {
            return;
        }
        foreach ($finder as $file) {
            $article = new Article([
                'base_directory' => $this->settings['base_directory'],
                'assets_folder' => $this->settings['assets_folder'],
                'logger' => $this->logger,
                'browser' => $this->browser
            ]);
            $article->loadFromFile($file->getRealPath());
            $article->downloadBinaries();
        }
    }

    private function getFeedUrl(string $url)
    {
        return preg_replace(['/j/', '/\/i/'], ['feed', ''], $url);
    }

    public function setLanguage(string $lang)
    {
        $this->browser->request('GET', '/set_locale/' . $lang);
        $this->settings['default_language'] = $this->langs[$lang];
    }

    public function getIssue(string $year, string $volume, string $issueName, $articleId = null)
    {
        $grid = $this->getGrid();

        $htmlUrl = $grid[$year][$volume][$issueName]['url'];
        $this->getIssueFromHtml($htmlUrl, $year, $volume, $issueName, $articleId);
    }

    /**
     * Get crawler of issue
     *
     * @param string $url
     * @param string $year
     * @param string $volume
     * @param string $issueName
     * @param string $filename
     * @return [Crawler,Crawler]
     */
    private function getIssueCrawlers(string $url, string $year, string $volume, string $issueName)
    {
        $basepath = implode(
            DIRECTORY_SEPARATOR,
            [$this->settings['base_directory'], $year, $volume, $issueName]
        );
        if (!is_dir($basepath)) {
            mkdir($basepath, 0666, true);
        }
        $htmlFile = $basepath . DIRECTORY_SEPARATOR . $this->settings['default_language'] . '.html';
        if (file_exists($htmlFile)) {
            $crawler['html'] = new Crawler(file_get_contents($htmlFile));
        } else {
            $crawler['html'] = $this->browser->request('GET', $url);
            $fileLang = $this->langs[$crawler['html']->filter('html')->attr('lang')];
            if ($this->settings['default_language'] == $fileLang) {
                file_put_contents($htmlFile, $crawler['html']->outerHtml());
            } else {
                $this->browser->request('GET', '/set_locale/' . substr($this->settings['default_language'], 0, 2));
                return $this->getIssueCrawlers($url, $year, $volume, $issueName);
            }
        }
        $xmlFile = $basepath . DIRECTORY_SEPARATOR . 'issue.xml';
        if (file_exists($xmlFile)) {
            $crawler['xml'] = new Crawler(file_get_contents($xmlFile));
        } else {
            $crawler['xml'] = $this->browser->request('GET', $this->getFeedUrl($url));
            file_put_contents($xmlFile, $crawler['xml']->outerHtml());
        }
        return $crawler;
    }

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

    private function getPublishedDate(Crawler $xml, int $index, Crawler $li): string
    {
        // The date from FeedRSS xml was wrong, to every articles return 2017 in case of CSP Journal.
        $publishedXml = $xml->filter('entry')->eq($index)->filter('updated')->text();
        $His = (\DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $publishedXml))->format('H:i:s');

        $published = str_pad($li->attr('data-date'), 8, '01', STR_PAD_RIGHT);
        $Ymd = (\DateTime::createFromFormat('Ymd', $published))->format('Y-m-d');
        return $Ymd . ' ' . $His;
    }

    private function getTextPdfUrl(Crawler $node)
    {
        $return = [];
        $node->filter('ul.links li')->each(function ($li) use (&$return) {
            $prefix = substr($li->text(), 0, 3);
            $prefixList = [
                'Tex' => 'text',
                'PDF' => 'pdf'
            ];
            if (!isset($prefixList[$prefix])) {
                return;
            }
            $type = $prefixList[$prefix];
            $li->filter('a')->each(function ($a) use (&$return, $type) {
                $lang = $a->text();
                if (isset($this->langs[$lang])) {
                    $lang = $this->langs[$lang];
                }
                $return[$type][$lang] = $a->attr('href');
            });
        });
        return $return;
    }

    private function getResume(Crawler $node)
    {
        $return = [];
        $node->filter('div[data-toggle="tooltip"]')->each(function ($div) use (&$return) {
            $lang = $this->langs[substr($div->attr('id'), -2)];
            foreach ($div as $nodeElement) {
                $resume = trim($nodeElement->childNodes->item(2)->data);
                $resume = preg_replace(
                    ['/^Resumo: /', '/^Resumen: /', '/^Abstract: /'],
                    [],
                    $resume
                );
            }
            $return[$lang] = $resume;
        });
        return $return;
    }

    private function getArticleId(Crawler $node)
    {
        $link = $node->filter('ul.links li a[href^="/article/"]');
        if ($link->count()) {
            $id = $link->first()->attr('href');
        } else {
            $link = $node->filter('ul.links li a[href^="/pdf/"]');
            if ($link->count()) {
                $id = $link->first()->attr('href');
            }
        }
        if (isset($id)) {
            return explode('/', $id)[4];
        }
        $this->logger->error('Article ID not found', ['article' => $node, 'method' => 'getArticleId']);
    }
}
