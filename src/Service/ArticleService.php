<?php

namespace ScieloScrapping\Service;

use BadMethodCallException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * @method void setId(int $id)
 * @method int getId()
 * @method void setDoi(string @doi)
 * @method string getDoi()
 * @method void setYear(int $year)
 * @method int getYear()
 * @method void setVolume(string $volume)
 * @method string getVolume()
 * @method void setIssueName(string @issueName)
 * @method string getIssueName()
 * @method void setCategory(string $category)
 * @method string getCategory()
 * @method void setUpdated(string $date)
 * @method string getUpdated()
 * @method void setPublished(string $date)
 * @method string getPublished()
 * @method void setKeywords(array $keywords)
 * @method aray getKeywords()
 * @method void setResume(array )
 * @method array getResume()
 * @method void setTitle(array $title)
 * @method array getTitle()
 * @method void setFormats(array $formats)
 * @method array getFormats()
 * @method void setAuthors(array $authors)
 * @method array getAuthors()
 * @method void setOjs(array $ojs)
 * @method array getOjs()
 */
class ArticleService
{
    /** @var Logger */
    protected $logger;
    private $originalFileRaw;
    private $outputDir;
    private $metadataFilename;
    private $binaryDirectory;
    private $data = [
        'id' => null,
        'doi' => null,
        'year' => null,
        'volume' => null,
        'issueName' => null,
        'category' => null,
        'updated' => null,
        'published' => null,
        'keywords' => [],
        'resume' => [],
        'title' => [],
        'formats' => [],
        'authors' => [],
        'ojs' => []
    ];

    private $settings = [
        'base_directory' => 'output'
    ];

    public function __construct(?array $settings = [])
    {
        if (isset($settings['logger'])) {
            $this->logger = $settings['logger'];
            unset($settings['logger']);
        } else {
            $this->logger = new Logger('SCIELO');
            $this->logger->pushHandler(new StreamHandler('logs/scielo.log', Logger::DEBUG));
        }

        $this->settings = array_merge($this->settings, $settings);
    }

    private function getAllData(): array
    {
        return $this->data;
    }

    public function __call(string $name, $arguments)
    {
        preg_match('/(?P<action>set|get)(?P<property>.*)/', $name, $matches);
        if (!isset($matches['property']) || !isset($matches['action'])) {
            throw new BadMethodCallException('Invalid method: ' . $name);
        }
        $property = $matches['property'];
        $property = strtolower($property[0]) . substr($property, 1);
        if (!array_key_exists($property, $this->data)) {
            throw new BadMethodCallException('No such method: ' . $name);
        }
        if (isset($arguments[1])) {
            if ($matches['action'] == 'set') {
                $this->data[$property][$arguments[1]] = $arguments[0];
                return $this;
            }
            return $this->data[$property][$arguments[1]];
        }
        if ($matches['action'] == 'set') {
            $this->data[$property] = $arguments[0];
            return $this;
        }
        if (isset($arguments[0])) {
            if (isset($this->data[$property][$arguments[0]])) {
                return $this->data[$property][$arguments[0]];
            }
            return;
        }
        return $this->data[$property];
    }

    public function getEndOfDoi()
    {
        $doi = $this->getDoi();
        if (!$doi) {
            $this->logger->error('DOI is required', [
                'data' => $this->data,
                'method' => 'ArticleService::getEndOfDoi'
            ]);
            throw new \Exception('DOI is required', 1);
        }
        $array = explode('/', $doi);
        if (isset($array[1])) {
            return $array[1];
        }
        $this->logger->error('DOI incomplete', [
            'basedir' => $this->getBasedir(),
            'method' => 'ArticleService::getEndOfDoi'
        ]);
        return $array[0];
    }

    public function loadFromFile(string $filename)
    {
        if (!file_exists($filename)) {
            return false;
        }
        $this->originalFileRaw = file_get_contents($filename);
        if (!$this->originalFileRaw) {
            return false;
        }
        $originalFileArray = json_decode($this->originalFileRaw, true);
        if (!$originalFileArray) {
            $this->logger->error('Invalid metadata content', [
                'filename' => $filename,
                'method' => 'ArticleService::loadFromFile'
            ]);
            throw new \Exception('Invalid metadata content', 1);
        }
        foreach ($originalFileArray as $property => $data) {
            if (array_key_exists($property, $this->data)) {
                $this->{'set' . strtoupper($property[0]) . substr($property, 1)}($data);
            }
        }
        return $this;
    }

    private function save()
    {
        $output = json_encode($this->data);
        if ($this->originalFileRaw == $output) {
            return;
        }
        $outputDir = $this->getBasedir();
        if (!$outputDir) {
            $this->logger->error('Invalid output dir', [
                'data' => $this->data,
                'method' => 'ArticleService::save'
            ]);
            return;
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $filename = $this->getMetadataFilename();
        file_put_contents(
            $outputDir . DIRECTORY_SEPARATOR . $filename,
            $output
        );
    }

    public function getBasedir()
    {
        if ($this->outputDir) {
            return $this->outputDir;
        }
        $filtered = array_filter($this->data, fn($v) => $v !== null);
        $total = array_reduce(
            ['year', 'volume', 'issueName', 'id'],
            fn($c, $i) => $c += isset($filtered[$i]) ? 1 : 0
        );
        if ($total != 4) {
            $this->logger->error('Required elements to generate filename not found', [
                'data' => $this->data,
                'method' => 'ArticleService::getFilename'
            ]);
            return;
        }
        $this->outputDir = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->settings['base_directory'],
                $this->data['year'],
                $this->data['volume'],
                $this->data['issueName'],
                $this->data['id']
            ]
        );
        return $this->outputDir;
    }

    public function getFirstLanguage()
    {
        return array_key_first($this->data['title']);
    }

    public function getMetadataFilename()
    {
        if ($this->metadataFilename) {
            return $this->metadataFilename;
        }
        $this->metadataFilename = 'metadata_' . $this->getEndOfDoi() . '.json';
        return $this->metadataFilename;
    }

    public function getBinaryDirectory()
    {
        if ($this->binaryDirectory) {
            return $this->binaryDirectory;
        }
        $this->binaryDirectory = $this->getEndOfDoi();
        return $this->binaryDirectory;
    }

    public function __destruct()
    {
        $this->save();
    }
}
