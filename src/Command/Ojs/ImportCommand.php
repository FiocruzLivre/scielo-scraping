<?php

namespace ScieloScrapping\Command\Ojs;

use APP\category\Category;
use APP\facades\Repo;
use OjsSdk\Providers\Ojs\OjsProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Illuminate\Support\Facades\DB;
use PKP\galley\Galley;
use PKP\security\Role;
use PKP\submission\Genre;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use APP\publication\Publication;
use ScieloScrapping\Service\ArticleService;
use APP\section\Section;
use APP\core\Services;
use SplFileInfo;
use APP\submission\Submission;
use PKP\submissionFile\SubmissionFile;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Finder\Finder;
use PKP\userGroup\UserGroup;
use APP\core\Application;
use PKP\db\DAORegistry;

class ImportCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'ojs:import';
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var string */
    private $outputDirectory;
    /** @var \stdClass */
    private $grid;
    /** @var bool */
    private $doUpgradeGrid = false;
    /** @var Finder */
    private $metadataFinder;
    /** @var int */
    private $totalMetadata = 0;
    /** @var ProgressBar */
    private $progressBar;
    /** @var Category[] */
    private $category = [];
    /** @var Section[] */
    private $section = [];
    /** @var UserGroup */
    private $authorGroup;
    /** @var Journal */
    private $journal;
    /** @var Genre */
    private $genre;

    protected function configure()
    {
        $this
            ->setDescription('Import all to OJS')
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug of journal')
            ->addOption('ojs-basedir', null, InputOption::VALUE_REQUIRED, 'Base directory of OJS setup', '/ojs')
            ->addOption('journal-path', null, InputOption::VALUE_REQUIRED, 'Journal to import')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory', 'output')
            ->addOption('default-genre', null, InputOption::VALUE_REQUIRED, 'Default genre key', 'OTHER')
            ->addOption('default-section', null, InputOption::VALUE_REQUIRED, 'Section title used when the article category has no known mapping', 'Artigo')
            ->addOption('insert-category', null, InputOption::VALUE_NONE, 'Insert category')
            ->addOption('import-without-keywords', null, InputOption::VALUE_NONE, 'Import without keywords')
            ->addOption('import-without-binaries', null, InputOption::VALUE_NONE, 'Import without binaries');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->loadOjsBasedir();
        OjsProvider::getApplication($input->getArgument('slug'));
        // only for validate before start import
        $this->getDefaultSupplementaryGenre();
        $this->logger = new Logger('SCIELO');
        $this->logger->pushHandler(new StreamHandler('logs/scielo.log', Logger::DEBUG));

        $this->startProgressBar();

        $this->saveIssues();
        $this->saveSubmission();

        $this->progressBar->setMessage('Done! :-D', 'status');
        $this->progressBar->finish();

        $output->writeln('');
        return Command::SUCCESS;
    }

    private function startProgressBar()
    {
        $this->output->writeln('');
        $this->output->writeln('');
        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->start();
        $this->progressBar->setMessage('Calculating total itens to import...', 'status');
        $this->progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n" .
            "\n" .
            "%memory:44s%"
        );
        $this->progressBar->display();
        $this->progressBar->setBarCharacter('<fg=green>⚬</>');
        $this->progressBar->setEmptyBarCharacter("<fg=red>⚬</>");
        $this->progressBar->setProgressCharacter("<fg=green>➤</>");
        $this->progressBar->setFormat(
            "<fg=white;bg=cyan> %status:-45s%</>\n" .
            "%current%/%max% [%bar%] %percent:3s%%\n" .
            "  %estimated:-20s%  %memory:20s%"
        );
        $total = $this->countIssues() + $this->countMetadata();
        $this->progressBar->setMaxSteps($total);
    }

    private function getMetadataFinder(): Finder
    {
        if (!$this->metadataFinder) {
            $this->metadataFinder = Finder::create()
                ->files()
                ->name('metadata_*.json')
                ->in($this->getOutputDirectory());
        }
        return $this->metadataFinder;
    }

    private function countMetadata(): int
    {
        if (!$this->totalMetadata) {
            $finder = $this->getMetadataFinder();
            $this->totalMetadata = $finder->count();
        }
        return $this->totalMetadata;
    }

    private function saveSubmission()
    {
        $finder = $this->getMetadataFinder();
        $total = $this->countMetadata();

        if (!$total) {
            throw new RuntimeException('Metadata json files not found.');
        }
        $this->progressBar->setMessage('Importing submission...', 'status');
        foreach ($finder as $file) {
            $article = new ArticleService([
                'logger' => $this->logger,
                'base_directory' => $this->getOutputDirectory()
            ]);
            if (!$article->loadFromFile($file->getRealPath())) {
                $this->progressBar->advance();
                continue;
            }

            if (!$this->input->getOption('import-without-binaries')) {
                $haveBinaries = Finder::create()
                    ->files()
                    ->name(['*.pdf', '*.html'])
                    ->in(
                        $article->getBasedir() .
                        DIRECTORY_SEPARATOR .
                        $article->getBinaryDirectory()
                    );
                if (!count($haveBinaries)) {
                    $this->progressBar->advance();
                    continue;
                }
            }
            if (!$this->input->getOption('import-without-keywords')) {
                if (!count($article->getKeywords())) {
                    $this->progressBar->advance();
                    continue;
                }
            }
            if (!empty($article->getOjs()['submissionId'])) {
                $this->progressBar->advance();
                continue;
            }

            [$submission, $publication] = $this->insertSubmissionAndPublication($file, $article);

            $insertCategory = $this->input->getOption('insert-category');
            if ($insertCategory) {
                $this->assignPublicationToCategory($publication, $article->getCategory());
            }
            $this->attachFiles($publication, $submission, $article, $file);

            // Index article.
            $submission = Repo::submission()->get($submission->getId());
            $articleSearchIndex = Application::getSubmissionSearchIndex();
            $articleSearchIndex->submissionMetadataChanged($submission);
            $articleSearchIndex->submissionFilesChanged($submission);
            $articleSearchIndex->submissionChangesFinished();

            $this->progressBar->advance();
        }
    }

    private function attachFiles(Publication $publication, Submission $submission, ArticleService $article, SplFileInfo $file)
    {
        foreach ($article->getFormats() as $format => $langs) {
            foreach (array_keys($langs) as $lang) {
                $galley = Repo::galley()->newDataObject();
                $galley->setData('publicationId', $publication->getId());
                $galley->setLabel(strtoupper($format));
                $ojsLocale = $this->normalizeLocale($lang);
                $galley->setLocale($ojsLocale);
                $galleyId = Repo::galley()->add($galley);
                $galley = Repo::galley()->get($galleyId);

                switch ($format) {
                    case 'text':
                        $fileName = $lang . '.html';
                        break;
                    case 'pdf':
                        $fileName = $lang . '.pdf';
                        break;
                }
                $submissionFile = $this->insertSubmissionFile(
                    $galley,
                    $submission,
                    $article,
                    $ojsLocale,
                    $fileName
                );
                Repo::galley()->edit($galley, ['submissionFileId' => $submissionFile->getId()]);
            }
        }
    }

    private function insertSubmissionFile(
        Galley $galley,
        Submission $submission,
        ArticleService $article,
        string $lang,
        string $fileName
    ): SubmissionFile {
        $genreId = $this->getDefaultSupplementaryGenre()->getId();

        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('locale', $lang);
        $submissionFile->setData('genreId', $genreId);
        $submissionFile->setData('createdAt', $article->getPublished());
        $submissionFile->setData('updatedAt', $article->getUpdated());
        $submissionFile->setData('assocId', $galley->getId());
        $fullFilename = implode(DIRECTORY_SEPARATOR, [
            $article->getBasedir(),
            $article->getBinaryDirectory(),
            $fileName
        ]);
        $fileData = $this->insertFile($submission, $fullFilename);
        $submissionFile->setData('fileId', $fileData['fileId']);
        $submissionFile->setData('name', $fileName, $lang);
        switch ($fileData['extension']) {
            case 'html':
            case 'pdf':
                $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
                $submissionFile->setData('assocType', Application::ASSOC_TYPE_REPRESENTATION);

                break;
            default:
                $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_DEPENDENT);
                $submissionFile->setData('assocType', Application::ASSOC_TYPE_SUBMISSION_FILE);
        }
        $submissionFileId = Repo::submissionFile()->add($submissionFile);
        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        if ($fileData['extension'] == 'html') {
            $htmlGalley = Repo::galley()->newDataObject();
            $htmlGalley->setId($submissionFile->getId());
            $this->insertSubmissionAttachments(
                $submissionFile,
                $htmlGalley,
                $submission,
                $article,
                $lang
            );
            $this->replaceFileIdOnAttachment($submissionFile, $fileData['filePath'], $fileName, $fileData['fileId']);
        }
        return $submissionFile;
    }

    private function insertFile(Submission $submission, string $fileName):array{
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $journal = $this->getJournal();
        $submissionDir = Repo::submissionFile()->getSubmissionDir($journal->getId(), $submission->getId());
        $filePath = $submissionDir . '/' . uniqid() . '.' . $extension;
		$fileId = Services::get('file')->add(
			$fileName,
			$filePath
		);
        return [
            'fileId' => $fileId,
            'extension' => $extension,
            'filePath' => $filePath,
        ];
    }

    private function insertSubmissionAttachments(
        SubmissionFile $submissionFile,
        Galley $articleGalley,
        Submission $submission,
        ArticleService $article,
        string $lang
    ) {
        $finder = Finder::create()
            ->files()
            ->notName(['*.pdf', '*.html'])
            ->in(
                $article->getBasedir() .
                DIRECTORY_SEPARATOR .
                $article->getBinaryDirectory()
            );
        foreach ($finder as $file) {
            $attachmentSubmissionFile = $this->insertSubmissionFile(
                $articleGalley,
                $submission,
                $article,
                $lang,
                $file->getFilename()
            );
        }
    }

    private function replaceFileIdOnAttachment($submissionFile, string $filePath, string $fileName, int $fileId): void {
        $content = file_get_contents($filePath);
        $content = str_replace(
            $fileName,
            $submissionFile->getData('assocId') . '/' . $fileId,
            $content
        );
        file_put_contents($filePath, $content);
    }

    private function getDefaultSupplementaryGenre(): Genre
    {
        if (!$this->genre) {
            /** @var GenreDAO */
            $genreDao = DAORegistry::getDAO('GenreDAO');
            $defaultKey = $this->input->getOption('default-genre');
            $this->genre = $genreDao->getByKey($defaultKey);
            if (!$this->genre) {
                throw new RuntimeException('Invalid default genre key');
            }
            if (!$this->genre->getSupplementary()) {
                $this->genre =  null;
                throw new RuntimeException('Grenre need is a supplementary');
            }
            if (!$this->genre->getEnabled()) {
                $this->genre =  null;
                throw new RuntimeException('Grenre need is enabled');
            }
        }
        return $this->genre;
    }

    /**
     * @return array{0: Submission, 1: Publication}
     */
    private function insertSubmissionAndPublication(SplFileInfo $file, ArticleService &$article): array
    {
        $journal = $this->getJournal();
        $issue = $this->getIssueByFile($file);

        $submission = Repo::submission()->newDataObject();
        $submission->setData('contextId', 1); // Journal = CSP
        $submission->setData('status', Submission::STATUS_PUBLISHED);
        $submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
        $submission->setData('dateLastActivity', str_pad($article->getUpdated(), 10, '-01', STR_PAD_RIGHT));
        $submission->setData('dateSubmitted', str_pad($article->getPublished(), 10, '-01', STR_PAD_RIGHT));
        $submission->setData('lastModified', str_pad($article->getUpdated(), 10, '-01', STR_PAD_RIGHT));
        $submission->setData('submissionProgress', ''); // '' means complete (submissionProgress is a string as of OJS 3.4)
        $submission->setData('locale', $this->normalizeLocale($article->getFirstLanguage()));

        $publication = Repo::publication()->newDataObject();
        $publication->setData('status', Submission::STATUS_PUBLISHED);
        $publication->setData('issueId', $issue['issueId']);
        $publication->setData('locale', $this->normalizeLocale($this->identifyPrimaryLanguage($article)));
        $publication->setData('pub-id::doi', $article->getDoi());
        $publication->setData('copyrightYear', substr($article->getPublished(), 0, 4));
        $publication->setData('datePublished', $article->getPublished());
        $publication->setData('lastModified', $article->getUpdated());
        $keywords = [];
        foreach ($article->getKeywords() as $lang => $keywordList) {
            $keywords[$this->normalizeLocale($lang)] = $keywordList;
        }
        $publication->setData('keywords', $keywords);
        foreach ($article->getTitle() as $lang => $title) {
            $publication->setData('title', $title, $this->normalizeLocale($lang));
        }
        foreach ($article->getResume() as $lang => $resume) {
            $publication->setData('abstract', $resume, $this->normalizeLocale($lang));
        }

        // A publication with no sectionId is silently dropped from the issue's
        // table of contents by IssueHandler - not an import error, just invisible
        // content - so every publication must get one, not just those where a
        // flag happened to be passed.
        $section = $this->getSection($this->resolveSectionTitle($article));
        $publication->setData('sectionId', $section->getId());

        $submissionId = Repo::submission()->add($submission, $publication, $journal);
        $submission = Repo::submission()->get($submissionId);
        $publication = Repo::publication()->get($submission->getData('currentPublicationId'));

        $article->setOjs(array_merge($article->getOjs(), [
            'submissionId' => $submissionId,
            'publicationId' => $publication->getId(),
        ]));

        $this->insertAuthor($publication, $article);

        return [$submission, $publication];
    }

    private function insertAuthor(Publication $publication, ArticleService $article)
    {
        $lang = $this->normalizeLocale($this->identifyPrimaryLanguage($article));
        foreach ($article->getAuthors() as $key => $row) {
            if (!isset($row['name'])) {
                $this->logger->error('Author Name', [
                    'method' => 'Importcommand::insertAuthor',
                    'directory' => $article->getBasedir()
                ]);
                continue;
            }
            $author = Repo::author()->newDataObject();
            $author->setData('publicationId', $publication->getId());
            $author->setData('seq', $key);
            [$familyName, $givenName] = $this->splitAuthorName($row['name']);
            if ($familyName !== '') {
                $author->setFamilyName($familyName, $lang);
            }
            $author->setGivenName($givenName, $lang);
            $author->setData('userGroupId', $this->getAuthorGroup()->getId());
            if (!empty($row['decreased'])) {
                $author->setData('authorContribution', $row['decreased']);
            }
            if (!empty($row['foundation'])) {
                $author->setAffiliation($row['foundation'], $lang);
            }
            if (!empty($row['orcid'])) {
                $author->setData('orcid', $row['orcid']);
            }
            if (empty($row['email'])) {
                $author->setData('email', $this->getJournal()->getContactEmail());
            } else {
                $author->setData('email', $row['email']);
            }
            if ($key == 0) {
                $author->setPrimaryContact(true);
            }
            $author->setIncludeInBrowse(true);
            $authorId = Repo::author()->add($author);
            if ($key == 0) {
                Repo::publication()->edit($publication, ['primaryContactId' => $authorId]);
            }
        }
    }

    private function getAuthorGroup(): UserGroup
    {
        if (!$this->authorGroup) {
            $journal = $this->getJournal();
            $this->authorGroup = Repo::userGroup()
                ->getByRoleIds([Role::ROLE_ID_AUTHOR], $journal->getId(), true)
                ->first();
        }
        return $this->authorGroup;
    }

    /**
     * Maps SciELO's citation_article_type values (NISO JATS vocabulary, in
     * English) to this journal's actual section titles (in Portuguese).
     * Anything not listed here falls back to the --default-section option.
     */
    private const CATEGORY_SECTION_MAP = [
        // SciELO's own TOC heading (from each article's XML <subj-group
        // subj-group-type="heading">), confirmed against issue 1998.v14suppl3.
        'articles' => 'Artigo',
        'article' => 'Artigo',
        'opinion' => 'Opinião',
        'editorial' => 'Editorial',
        'research notes' => 'Notas',
        'debate' => 'Debate',
        'review' => 'Revisão',
        'letter' => 'Carta',
        'interview' => 'Entrevista',
        'obituary' => 'Obituário',
        'retraction' => 'Retraction',
        'correction' => 'Errata',
        'article-commentary' => 'Comentários',
        // citation_article_type fallback values (used only if the XML heading
        // can't be fetched) and the one-off issue-66 recovery script's hardcode
        'research-article' => 'Artigo',
        'review-article' => 'Revisão',
        'brief-report' => 'Notas',
        'artigo' => 'Artigo',
    ];

    private function resolveSectionTitle(ArticleService $article): string
    {
        $category = strtolower(trim((string) $article->getCategory()));
        if (isset(self::CATEGORY_SECTION_MAP[$category])) {
            return self::CATEGORY_SECTION_MAP[$category];
        }
        $default = $this->input->getOption('default-section');
        $this->logger->warning('Unmapped article category, using default section', [
            'method' => 'ImportCommand::resolveSectionTitle',
            'category' => $article->getCategory(),
            'default' => $default,
        ]);
        return $default;
    }

    private function getSection($name)
    {
        if (!isset($this->section[$name])) {
            $journal = $this->getJournal();
            $section = Repo::section()->getCollector()
                ->filterByContextIds([$journal->getId()])
                ->filterByTitles([$name])
                ->getMany()
                ->first();
            if (!$section) {
                $langs = $journal->getSupportedLocales();
                $section = Repo::section()->newDataObject();
                $section->setContextId($journal->getId());
                foreach ($langs as $lang) {
                    $section->setTitle($name, $lang);
                }
                Repo::section()->add($section);
            }
            $this->section[$name] = $section;
        }
        return $this->section[$name];
    }

    private function assignPublicationToCategory(Publication $publication, string $categoryName)
    {
        $category = $this->getCategory($categoryName);
        Repo::category()->dao->insertPublicationAssignment($category->getId(), $publication->getId());
    }

    private function getCategory($name)
    {
        if (!isset($this->category[$name])) {
            $journal = $this->getJournal();
            $category = Repo::category()->getCollector()
                ->filterByContextIds([$journal->getId()])
                ->filterByPaths([$name])
                ->getMany()
                ->first();
            if (!$category) {
                $langs = $journal->getSupportedLocales();
                $category = Repo::category()->newDataObject();
                $category->setContextId($journal->getId());
                $category->setPath($name);
                foreach ($langs as $lang) {
                    $category->setTitle($name, $lang);
                }
                Repo::category()->add($category);
            }
            $this->category[$name] = $category;
        }
        return $this->category[$name];
    }

    private function getIssueByFile(SplFileInfo $file)
    {
        $path = $file->getRelativePath();
        $path = preg_replace('/' . $this->getOutputDirectory() . '/', '', $path, 1);
        $path = trim($path, '/');
        list($year, $volume, $issueName) = explode('/', $path);
        return $this->getGrid()[$year][$volume][$issueName];
    }

    private function saveIssues()
    {
        $journal = $this->getJournal();
        $langs = $journal->getSupportedLocales();

        $total = $this->countIssues();
        if (!$total) {
            throw new RuntimeException('No issues to import.');
        }

        $this->progressBar->setMessage('Importing issues...', 'status');
        $grid = $this->getGrid();

        foreach ($grid as $year => $volumes) {
        //    if($year < 2022){
                foreach ($volumes as $volume => $issues) {
                    foreach ($issues as $issueName => $attr) {
                        if (isset($attr['issueId'])) {
                            $this->progressBar->advance();
                            continue;
                        }
                        $issuesFromDatabase = $this->getIssueFromDb($journal->getId(), $volume, $year, $attr['text']);
                        if (count($issuesFromDatabase)) {
                            $issue = $issuesFromDatabase[0];
                            $this->setGridAttribute($issue->getYear(), $issue->getvolume(), $issueName, 'issueId', $issue->getId());
                            $this->progressBar->advance();
                            continue;
                        }
                        // Insert issue
                        $issue = Repo::issue()->newDataObject();
                        $issue->setJournalId($journal->getId());
                        $issue->setVolume($volume);
                        $issue->setShowVolume(1);
                        $issue->setNumber($attr['text']);
                        $issue->setShowNumber(1);
                        $issue->setYear($year);
                        $issue->setShowYear(1);
                        foreach ($langs as $lang) {
                            $issue->setTitle($attr['text'], $lang);
                        }
                        $issue->setShowTitle(1);
                        $issue->setPublished(1);
                        $issueId = Repo::issue()->add($issue);
                        $this->setGridAttribute($year, $volume, $issueName, 'issueId', $issueId);
                        $this->progressBar->advance();
                    }
                }
        //    }
        }
        $this->saveGrid();
    }

    /**
     * @return \APP\issue\Issue[]
     */
    private function getIssueFromDb(int $journalId, int $volume, int $year, string $title): array
    {
        $like = str_replace('.', '%', strtolower($title));
        $issueIds = DB::table('issues as i')
            ->leftJoin('issue_settings as iss1', function ($join) {
                $join->on('i.issue_id', '=', 'iss1.issue_id')
                    ->where('iss1.setting_name', '=', 'title');
            })
            ->where('i.journal_id', $journalId)
            ->where('i.volume', $volume)
            ->where('i.year', $year)
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(i.number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(iss1.setting_value) LIKE ?', [$like]);
            })
            ->distinct()
            ->pluck('i.issue_id');

        return $issueIds
            ->map(fn ($issueId) => Repo::issue()->get((int) $issueId))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Count issues
     *
     * @return integer
     */
    private function countIssues(): int
    {
        $total = 0;
        $grid = $this->getGrid();
        foreach ($grid as $volumes) {
            foreach ($volumes as $issues) {
                $total += count($issues);
            }
        }
        return $total;
    }

    private function setGridAttribute($year, $volume, $issueName, $attribute, $value)
    {
        $this->doUpgradeGrid = true;
        $this->grid[$year][$volume][$issueName][$attribute] = $value;
    }

    private function loadOjsBasedir()
    {
        $ojsBasedir = $this->input->getOption('ojs-basedir');
        if (!is_dir($ojsBasedir)) {
            $ojsBasedir = getenv('OJS_WEB_BASEDIR');
            if (!$ojsBasedir) {
                throw new RuntimeException('Inform a valid path in ojs-basedir option');
            }
        }
        putenv('OJS_WEB_BASEDIR=' . $ojsBasedir);
    }

    private function getOutputDirectory()
    {
        if (!$this->outputDirectory) {
            $this->outputDirectory = $this->input->getOption('output');
            if (!is_dir($this->outputDirectory)) {
                $this->output->writeln('Run frist scielo download command or fix directory path');
                throw new RuntimeException('Error on create output directory called [' . $this->outputDirectory . ']');
            }
        }
        return $this->outputDirectory;
    }

    private function getGrid()
    {
        if (!$this->grid) {
            $outputDirectory = $this->getOutputDirectory();
            if (!is_file($outputDirectory . '/grid.json')) {
                throw new RuntimeException('grid.json not found');
            }
            $this->grid = file_get_contents($outputDirectory . '/grid.json');
            $this->grid = json_decode($this->grid, true);
            if (!$this->grid) {
                throw new RuntimeException('Invalid content in grid.json</error>');
            }
        }
        return $this->grid;
    }

    private function getJournal(): Journal
    {
        if ($this->journal) {
            return $this->journal;
        }
        /**
         * @var JournalDAO
         */
        $JournalDAO = DAORegistry::getDAO('JournalDAO');
        $journals = $JournalDAO->getAll();
        if (!$journals) {
            throw new RuntimeException('Create a journal in OJS first');
        }
        while ($journal = $journals->next()) {
            $options[$journal->getPath()] = $journal;
        }
        $journalPath = $this->input->getOption('journal-path');
        if ($journalPath) {
            if (!isset($options[$journalPath])) {
                throw new RuntimeException('Invalid option: journal-path not found in OJS');
            }
            $this->journal = $options[$journalPath];
        } elseif (count($options) > 1) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'Select the destination journal',
                array_keys($options)
            );
            $question->setErrorMessage('Journal path %s is invalid.');
            $journalPath = $helper->ask($this->input, $this->output, $question);
            $this->journal = $journals[array_keys($options)[$journalPath]];
        } else {
            $this->journal = current($options);
        }
        $email = $this->journal->getContactEmail();
        if (!$email) {
            throw new RuntimeException('Configure default email of journal: settings > context > contacts');
        }
        return $this->journal;
    }

    /**
     * The scraper produces standard OJS locale codes (en_US, es_ES, pt_BR),
     * but this journal's configured supportedLocales are ["pt_BR","en","es"]
     * - short codes for en/es. Templates that gate on an exact match against
     * the journal's locale set (e.g. the PDF galley link in the csp theme's
     * aside.tpl) never match "en_US"/"es_ES", so the content silently fails
     * to render even though the data imported without any error.
     */
    private function normalizeLocale(?string $lang): ?string
    {
        if (!$lang) {
            return $lang;
        }
        $supported = $this->getJournal()->getSupportedLocales();
        if (in_array($lang, $supported, true)) {
            return $lang;
        }
        $base = substr($lang, 0, 2);
        foreach ($supported as $locale) {
            if (substr($locale, 0, 2) === $base) {
                return $locale;
            }
        }
        return $lang;
    }

    /**
     * SciELO's citation_author meta tag (the scraper's source for author
     * names) uses the standard bibliographic "Family name(s), Given name(s)"
     * convention. Splitting it is what lets OJS sort/cite authors correctly
     * (e.g. "Koifman S" instead of the whole string); every name scraped
     * from issue 66 followed this convention with zero exceptions.
     *
     * @return array{0: string, 1: string} [familyName, givenName]
     */
    private function splitAuthorName(string $name): array
    {
        if (!str_contains($name, ',')) {
            return ['', trim($name)];
        }
        [$family, $given] = explode(',', $name, 2);
        return [trim($family), trim($given)];
    }

    private function identifyPrimaryLanguage($article)
    {
        $primary = $this->identifyPrimaryLanguageWIthOneLang($article);
        if ($primary) {
            return $primary;
        }
        return $this->identifyPrimaryLanguageWIthManyLang($article);
    }

    private function identifyPrimaryLanguageWIthOneLang($article) {
        $formats = $article->getFormats();
        if (isset($formats['text'])) {
            if (count($formats['text']) === 1) {
                return array_key_first($formats['text']);
            }
        }
        if (isset($formats['pdf'])) {
            if (count($formats['pdf']) === 1) {
                return array_key_first($formats['pdf']);
            }
        }
        if ($article->getTitle()) {
            if (count($article->getTitle()) === 1) {
                return array_key_first($article->getTitle());
            }
        }
        if ($article->getKeywords()) {
            if (count($article->getKeywords()) === 1) {
                return array_key_first($article->getKeywords());
            }
        }
    }

    private function identifyPrimaryLanguageWIthManyLang($article) {
        $formats = $article->getFormats();
        if (isset($formats['text'])) {
            if (count($formats['text'])) {
                return array_key_first($formats['text']);
            }
        }
        if (isset($formats['pdf'])) {
            if (count($formats['pdf'])) {
                return array_key_first($formats['pdf']);
            }
        }
        if ($article->getTitle()) {
            if (count($article->getTitle())) {
                return array_key_first($article->getTitle());
            }
        }
        if ($article->getKeywords()) {
            if (count($article->getKeywords())) {
                return array_key_first($article->getKeywords());
            }
        }
    }

    private function saveGrid()
    {
        if ($this->doUpgradeGrid) {
            file_put_contents($this->getOutputDirectory() . '/grid.json', json_encode($this->getGrid()));
        }
    }
}
