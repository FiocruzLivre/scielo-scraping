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
    private function getIssueCrawlers(string $url, string $year, string $volume, string $issueName): Crawler
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
            return new Crawler(file_get_contents($htmlFile));
        }
        $crawler = $this->browser->request('GET', $url);
        $fileLang = $this->langs[$crawler->filter('html')->attr('lang')];
        if ($this->settings['default_language'] == $fileLang) {
            file_put_contents($htmlFile, $crawler->outerHtml());
            return $crawler;
        }
        $this->browser->request('GET', '/set_locale/' . substr($this->settings['default_language'], 0, 2));
        return $this->getIssueCrawlers($url, $year, $volume, $issueName);
    }

    /**
     * Discover articles within an issue page.
     *
     * The current www.scielo.br template carries no per-article data (title,
     * resume, authors, dates) on the issue listing page itself - only links to
     * each article's own page, identified by an opaque id (e.g.
     * "mMBFtKqzbFVFQCr95kV8mKR") rather than the old page-range-based path. So,
     * unlike the old template, every discovered article needs its own page
     * fetched to learn anything about it at all; see Article::loadFromArticlePage().
     */
    private function getIssueFromHtml(string $url, string $year, string $volume, string $issueName, $articleId = null)
    {
        $crawler = $this->getIssueCrawlers($url, $year, $volume, $issueName);
        $articleLangs = [];
        $crawler->filter('a[href*="/a/"][href*="format=pdf"]')->each(function (Crawler $a) use (&$articleLangs) {
            $href = $a->attr('href');
            if (!preg_match('#/a/([A-Za-z0-9]+)/#', $href, $m)) {
                return;
            }
            $id = $m[1];
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            $articleLangs[$id] = $query['lang'] ?? 'pt';
        });

        if (!$articleLangs) {
            $this->logger->error('No articles found on issue page', [
                'year' => $year,
                'volume' => $volume,
                'issueName' => $issueName,
                'method' => 'getIssueFromHtml'
            ]);
        }

        foreach ($articleLangs as $id => $listedLang) {
            if ($articleId && $articleId != $id) {
                continue;
            }
            $alreadyDone = glob(implode(DIRECTORY_SEPARATOR, [
                $this->settings['base_directory'], $year, $volume, $issueName, $id, 'metadata_*.json'
            ]));
            if ($alreadyDone) {
                continue;
            }
            $article = new Article([
                'base_directory' => $this->settings['base_directory'],
                'assets_folder' => $this->settings['assets_folder'],
                'logger' => $this->logger,
                'browser' => $this->browser
            ]);
            $article->setId($id);
            $article->setYear($year);
            $article->setVolume($volume);
            $article->setIssueName($issueName);
            $article->loadFromArticlePage($this->settings['journal_slug'], $id, $listedLang);
        }
    }
}
