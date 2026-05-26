<?php

namespace BookStack\Entities\Controllers;

use BookStack\Activity\Models\View;
use BookStack\Activity\Tools\CommentTree;
use BookStack\Activity\Tools\UserEntityWatchOptions;
use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Queries\EntityQueries;
use BookStack\Entities\Queries\PageQueries;
use BookStack\Entities\Repos\PageRepo;
use BookStack\Entities\Tools\BookContents;
use BookStack\Entities\Tools\Cloner;
use BookStack\Entities\Tools\NextPreviousContentLocator;
use BookStack\Entities\Tools\PageContent;
use BookStack\Entities\Tools\PageEditActivity;
use BookStack\Entities\Tools\PageEditorData;
use BookStack\Exceptions\NotFoundException;
use BookStack\Exceptions\PermissionsException;
use BookStack\Http\Controller;
use BookStack\Permissions\Permission;
use BookStack\References\ReferenceFetcher;
use Exception;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

use ZipArchive;  // 请在文件顶部添加此 use

class PageController extends Controller
{
    public function __construct(
        protected PageRepo $pageRepo,
        protected PageQueries $queries,
        protected EntityQueries $entityQueries,
        protected ReferenceFetcher $referenceFetcher
    ) {
    }

    /**
     * Show the form for creating a new page.
     *
     * @throws Throwable
     */
    public function create(string $bookSlug, ?string $chapterSlug = null)
    {
        if ($chapterSlug) {
            $parent = $this->entityQueries->chapters->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        } else {
            $parent = $this->entityQueries->books->findVisibleBySlugOrFail($bookSlug);
        }

        $this->checkOwnablePermission(Permission::PageCreate, $parent);

        // Redirect to draft edit screen if signed in
        if ($this->isSignedIn()) {
            $draft = $this->pageRepo->getNewDraftPage($parent);

            return redirect($draft->getUrl());
        }

        // Otherwise show the edit view if they're a guest
        $this->setPageTitle(trans('entities.pages_new'));

        return view('pages.guest-create', ['parent' => $parent]);
    }

    /**
     * Create a new page as a guest user.
     *
     * @throws ValidationException
     */
    public function createAsGuest(Request $request, string $bookSlug, ?string $chapterSlug = null)
    {
        $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($chapterSlug) {
            $parent = $this->entityQueries->chapters->findVisibleBySlugsOrFail($bookSlug, $chapterSlug);
        } else {
            $parent = $this->entityQueries->books->findVisibleBySlugOrFail($bookSlug);
        }

        $this->checkOwnablePermission(Permission::PageCreate, $parent);

        $page = $this->pageRepo->getNewDraftPage($parent);
        $this->pageRepo->publishDraft($page, [
            'name' => $request->get('name'),
        ]);

        return redirect($page->getUrl('/edit'));
    }

    /**
     * Show form to continue editing a draft page.
     *
     * @throws NotFoundException
     */
    public function editDraft(Request $request, string $bookSlug, int $pageId)
    {
        $draft = $this->queries->findVisibleByIdOrFail($pageId);
        $this->checkOwnablePermission(Permission::PageCreate, $draft->getParent());

        $editorData = new PageEditorData($draft, $this->entityQueries, $request->query('editor', ''));
        $this->setPageTitle(trans('entities.pages_edit_draft'));

        return view('pages.edit', $editorData->getViewData());
    }

    /**
     * Store a new page by changing a draft into a page.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function store(Request $request, string $bookSlug, int $pageId)
    {
        $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
        ]);

        $draftPage = $this->queries->findVisibleByIdOrFail($pageId);
        $this->checkOwnablePermission(Permission::PageCreate, $draftPage->getParent());

        $page = $this->pageRepo->publishDraft($draftPage, $request->all());

        return redirect($page->getUrl());
    }

    /**
     * Display the specified page.
     * If the page is not found via the slug the revisions are searched for a match.
     *
     * @throws NotFoundException
     */
    public function show(string $bookSlug, string $pageSlug)
    {
        try {
            $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        } catch (NotFoundException $e) {
            $page = $this->entityQueries->findVisibleByOldSlugs('page', $pageSlug, $bookSlug);
            if (is_null($page)) {
                throw $e;
            }

            return redirect($page->getUrl());
        }

        $pageContent = (new PageContent($page));
        $page->html = $pageContent->render();
        $pageNav = $pageContent->getNavigation($page->html);

        $sidebarTree = (new BookContents($page->book))->getTree();
        $commentTree = (new CommentTree($page));
        $nextPreviousLocator = new NextPreviousContentLocator($page, $sidebarTree);

        View::incrementFor($page);
        $this->setPageTitle($page->getShortName());

        return view('pages.show', [
            'page'            => $page,
            'book'            => $page->book,
            'current'         => $page,
            'sidebarTree'     => $sidebarTree,
            'commentTree'     => $commentTree,
            'pageNav'         => $pageNav,
            'watchOptions'    => new UserEntityWatchOptions(user(), $page),
            'next'            => $nextPreviousLocator->getNext(),
            'previous'        => $nextPreviousLocator->getPrevious(),
            'referenceCount'  => $this->referenceFetcher->getReferenceCountToEntity($page),
        ]);
    }

    /**
     * 判断表格是否为"长表格"，需要强制分页。
     * 不再仅依赖行数判断，因为单元格内文字可能很多行。
     * 综合评估：
     * - 总字符数（表格内容越多，越可能跨页）
     * - 单元格段落数（单元格内文字换行越多，占用越高）
     * - 简单行数（作为辅助判断）
     */
    private function isLongTable(\DOMElement $table, \DOMXPath $xpath): bool
    {
        $tableRows = $xpath->query('.//w:tr', $table);
        $rowCount = $tableRows->length;

        $totalTextLength = 0;
        $totalCellParagraphs = 0;

        foreach ($tableRows as $row) {
            $cells = $xpath->query('.//w:tc', $row);
            foreach ($cells as $cell) {
                $totalTextLength += strlen(trim($cell->textContent));

                $cellParagraphs = $xpath->query('.//w:p', $cell);
                $totalCellParagraphs += $cellParagraphs->length;
            }
        }

        $LONG_TABLE_TEXT_THRESHOLD = 500;
        $LONG_TABLE_PARAGRAPH_THRESHOLD = 15;
        $LONG_TABLE_ROW_THRESHOLD = 8;

        if ($totalTextLength > $LONG_TABLE_TEXT_THRESHOLD) {
            return true;
        }

        if ($totalCellParagraphs > $LONG_TABLE_PARAGRAPH_THRESHOLD) {
            return true;
        }

        if ($rowCount > $LONG_TABLE_ROW_THRESHOLD) {
            return true;
        }

        return false;
    }

    /**
     * Export page as Word document.
     *
     * @throws NotFoundException
     */
    public function exportAsWord(string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageView, $page);

        try {
            $tempDir = sys_get_temp_dir();
            $tempDocx = tempnam($tempDir, 'bookstack_word_') . '.docx';

            if (!empty($page->markdown)) {
                $markdownContent = $this->buildExportMarkdown($page);

                if ($this->hasComplexHtmlTables($page->markdown)) {
                    Log::info('Using HTML export path due to complex table structure (rowspan/colspan)');
                    $pageContent = (new PageContent($page));
                    $page->html = $pageContent->render();
                    $tempHtml = $this->createTempHtmlFile($page, $tempDir);
                    $this->convertHtmlToDocx($tempHtml, $tempDocx);
                } else {
                    $tempMarkdown = $this->createTempMarkdownFileFromContent($markdownContent, $tempDir);
                    $this->convertMarkdownToDocx($tempMarkdown, $tempDocx);
                }
            } else {
                $pageContent = (new PageContent($page));
                $page->html = $pageContent->render();
                $tempHtml = $this->createTempHtmlFile($page, $tempDir);
                $this->convertHtmlToDocx($tempHtml, $tempDocx);
            }

            return $this->sendDocxDownload($tempDocx, $page);

        } catch (NotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Word export failed - Page ID: ' . ($page->id ?? 'unknown'), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect($page->getUrl())
                ->with('error', trans('errors.export_word_failed') . ': ' . $e->getMessage());
        }
    }

    /**
     * Create temporary Markdown file for export from page.
     */
    private function createTempMarkdownFile($page, string $tempDir): string
    {
        $markdownContent = $this->buildExportMarkdown($page);

        $tempFile = tempnam($tempDir, 'bookstack_md_') . '.md';
        file_put_contents($tempFile, $markdownContent);

        return $tempFile;
    }

    /**
     * Create temporary Markdown file from content string.
     */
    private function createTempMarkdownFileFromContent(string $content, string $tempDir): string
    {
        $tempFile = tempnam($tempDir, 'bookstack_md_') . '.md';
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /**
     * Build Markdown content for export.
     */
    private function buildExportMarkdown($page): string
    {
        $markdown = $page->markdown ?? '';

        Log::info('Building export markdown', ['has_markdown' => !empty($markdown), 'html_length' => strlen($page->html ?? ''), 'md_length' => strlen($markdown)]);

        if (strpos($markdown, '<table') !== false) {
            Log::info('Found HTML table in markdown content before conversion');
        }

        if ($this->hasComplexHtmlTables($markdown)) {
            Log::info('HTML table contains rowspan/colspan, will use HTML export path instead');
            return $markdown;
        }

        $markdown = $this->convertHtmlTablesToMarkdown($markdown);

        if (strpos($markdown, '<table') !== false) {
            Log::warning('HTML table still present after conversion attempt');
        }

        $markdown = $this->removeFirstH1FromMarkdown($markdown);
        $markdown = $this->normalizeMarkdownContent($markdown);
        $markdown = $this->replaceNonBreakingSpacesInMarkdown($markdown);
        $markdown = $this->convertCheckboxesInMarkdown($markdown);
        $markdown = $this->processApprovalTableInMarkdown($markdown);

        return "# " . $page->name . "\n\n" . $markdown;
    }

    /**
     * Check if HTML tables contain rowspan or colspan attributes.
     * Such tables cannot be properly converted to Markdown format.
     */
    private function hasComplexHtmlTables(string $markdown): bool
    {
        if (strpos($markdown, '<table') === false) {
            return false;
        }

        if (preg_match('/<table[^>]*>[\s\S]*?<\/table>/i', $markdown, $matches)) {
            $tableContent = $matches[0];
            if (stripos($tableContent, 'rowspan') !== false || stripos($tableContent, 'colspan') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert checkbox Unicode characters in Markdown to Word-compatible format.
     * Uses HTML character references to avoid encoding issues during Pandoc conversion.
     */
    private function convertCheckboxesInMarkdown(string $markdown): string
    {
        $checkboxMappings = [
            '☐' => '&#9744;',
            '☒' => '&#9746;',
            '☑' => '&#9745;',
            '☸' => '&#9784;',
            '○' => '&#9675;',
            '●' => '&#9679;',
            '◉' => '&#9673;',
            '✅' => '&#9989;',
            '❌' => '&#10060;',
        ];

        foreach ($checkboxMappings as $unicode => $htmlEntity) {
            $markdown = str_replace($unicode, $htmlEntity, $markdown);
        }

        return $markdown;
    }

    /**
     * Convert HTML tables to Markdown tables.
     */
    private function convertHtmlTablesToMarkdown(string $markdown): string
    {
        if (!extension_loaded('dom')) {
            return $markdown;
        }

        $pattern = '/<table[^>]*>[\s\S]*?<\/table>/i';
        $convertedCount = 0;

        $result = preg_replace_callback($pattern, function ($matches) use (&$convertedCount) {
            $htmlTable = $matches[0];
            $converted = $this->convertSingleHtmlTableToMarkdown($htmlTable);
            if ($converted !== $htmlTable) {
                $convertedCount++;
                Log::info('Converted HTML table to Markdown', ['table_length' => strlen($htmlTable)]);
            }
            return $converted;
        }, $markdown);

        if ($convertedCount > 0) {
            Log::info("Successfully converted $convertedCount HTML table(s) to Markdown");
        }

        return $result;
    }

    /**
     * Convert a single HTML table to Markdown format.
     * Handles complex tables with rowspan and nested elements.
     */
    private function convertSingleHtmlTableToMarkdown(string $htmlTable): string
    {
        try {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $htmlTable, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $tables = $dom->getElementsByTagName('table');
            if ($tables->length === 0) {
                return $htmlTable;
            }

            $table = $tables->item(0);

            $allRows = [];
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $allRows[] = $tr;
            }

            if (empty($allRows)) {
                return $htmlTable;
            }

            $maxCols = 0;
            foreach ($allRows as $row) {
                $cols = $this->countRowSpannedColumns($row);
                $maxCols = max($maxCols, $cols);
            }

            $markdownRows = [];

            foreach ($allRows as $rowIndex => $row) {
                $cellContents = array_fill(0, $maxCols, '');
                $this->populateRowCells($row, $cellContents, $rowIndex);

                $markdownRows[] = '| ' . implode(' | ', $cellContents) . ' |';

                if ($rowIndex === 0) {
                    $separatorCells = array_fill(0, $maxCols, '---');
                    $markdownRows[] = '| ' . implode(' | ', $separatorCells) . ' |';
                }
            }

            return "\n" . implode("\n", $markdownRows) . "\n";
        } catch (\Exception $e) {
            Log::warning('Failed to convert HTML table to Markdown: ' . $e->getMessage());
            return $htmlTable;
        }
    }

    private function countRowSpannedColumns(\DOMElement $row): int
    {
        $cells = $row->getElementsByTagName('th');
        if ($cells->length === 0) {
            $cells = $row->getElementsByTagName('td');
        }

        $totalCols = 0;
        foreach ($cells as $cell) {
            $colspan = intval($cell->getAttribute('colspan')) ?: 1;
            $totalCols += $colspan;
        }

        return $totalCols;
    }

    private function populateRowCells(\DOMElement $row, array &$cellContents, int $rowIndex): void
    {
        $cells = $row->getElementsByTagName('th');
        if ($cells->length === 0) {
            $cells = $row->getElementsByTagName('td');
        }

        $cellArray = [];
        foreach ($cells as $cell) {
            $cellArray[] = $cell;
        }

        $col = 0;
        $cellIndex = 0;

        while ($cellIndex < count($cellArray) && $col < count($cellContents)) {
            while ($col < count($cellContents) && trim($cellContents[$col]) !== '') {
                $col++;
            }

            if ($col >= count($cellContents)) {
                break;
            }

            $cell = $cellArray[$cellIndex];
            $cellText = trim($this->getElementTextWithBreaks($cell));
            $cellText = str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $cellText);

            $colspan = intval($cell->getAttribute('colspan')) ?: 1;
            $colspan = min($colspan, count($cellContents) - $col);

            for ($i = 0; $i < $colspan; $i++) {
                $cellContents[$col + $i] = ($i === 0) ? $cellText : '';
            }

            $cellIndex++;
            $col += $colspan;
        }
    }

    /**
     * Get text content from a DOM element, including nested elements.
     * Converts <br> tags to spaces and adds proper separators between block elements.
     */
    private function getElementTextWithBreaks(\DOMElement $element): string
    {
        $text = '';
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'blockquote', 'pre'];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->nodeValue;
            } elseif ($child instanceof \DOMElement) {
                if (strtolower($child->tagName) === 'br') {
                    $text .= ' ';
                } else {
                    $innerText = $this->getElementTextWithBreaks($child);
                    if (in_array(strtolower($child->tagName), $blockTags)) {
                        $text .= "\n" . $innerText . "\n";
                    } else {
                        $text .= $innerText;
                    }
                }
            }
        }
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n\s+/', "\n", $text);
        $text = preg_replace('/\s+\n/', "\n", $text);
        return trim($text);
    }

    /**
     * Remove first h1 heading from markdown content.
     */
    private function removeFirstH1FromMarkdown(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $firstLineRemoved = false;

        $filtered = array_filter($lines, function ($line) use (&$firstLineRemoved) {
            if (!$firstLineRemoved && preg_match('/^#\s+/', $line)) {
                $firstLineRemoved = true;
                return false;
            }
            return true;
        });

        return implode("\n", array_values($filtered));
    }

    /**
     * Normalize markdown content for pandoc conversion.
     * Handles encoding issues, line endings, and preserves markdown structure.
     */
    private function normalizeMarkdownContent(string $markdown): string
    {
        if (empty($markdown)) {
            return $markdown;
        }

        $markdown = $this->normalizeLineEndings($markdown);

        $markdown = $this->decodeHtmlEntities($markdown);

        $markdown = $this->preserveTreeStructures($markdown);

        $markdown = $this->preserveCustomTocStructures($markdown);

        $markdown = $this->fixMarkdownStructure($markdown);

        $markdown = $this->preserveCodeBlocks($markdown);

        return $markdown;
    }

    /**
     * Preserve custom table of contents (TOC) structures with dot leaders.
     * Handles TOC formats like:
     *   1. [标题](#锚点) ........................................................................................................ 4
     *     - 1.1. [子标题](#子锚点) .................................................................................................... 4
     * Wraps TOC in HTML <pre> tag to preserve formatting in Word output.
     */
    private function preserveCustomTocStructures(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $result = [];
        $inTocBlock = false;
        $tocStartPattern = false;
        $consecutiveEmptyLines = 0;
        $tocLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (!$inTocBlock && preg_match('/^#{1,6}\s+(?:目录|table\s*of\s*contents|toc|outline|目录表)/i', $trimmed)) {
                $inTocBlock = true;
                $tocStartPattern = true;
                $tocLines = [];
                $tocLines[] = $line;
                continue;
            }

            if ($inTocBlock) {
                if (empty($trimmed)) {
                    $consecutiveEmptyLines++;
                    $tocLines[] = '';
                } else {
                    $consecutiveEmptyLines = 0;
                }

                if ($consecutiveEmptyLines >= 2) {
                    $inTocBlock = false;
                    $result[] = $this->wrapTocInPreTag($tocLines);
                    $result[] = $line;
                    continue;
                }

                $isTocLine = $this->isTocListItem($trimmed);

                if (!$isTocLine && !empty($trimmed) && !$this->isHeadingLine($trimmed) && $trimmed !== '---') {
                    $inTocBlock = false;
                    $result[] = $this->wrapTocInPreTag($tocLines);
                    $result[] = $line;
                    continue;
                }

                if ($isTocLine) {
                    $processedLine = $this->processTocListItem($line);
                    $tocLines[] = $processedLine;
                    continue;
                } elseif ($inTocBlock) {
                    $tocLines[] = $line;
                    continue;
                }
            }

            $result[] = $line;
        }

        if (!empty($tocLines)) {
            $result[] = $this->wrapTocInPreTag($tocLines);
        }

        return implode("\n", $result);
    }

    /**
     * Wrap TOC lines in HTML <pre> tag to preserve line structure in Word output.
     */
    private function wrapTocInPreTag(array $tocLines): string
    {
        $tocContent = implode("\n", $tocLines);
        return "<pre style=\"font-family: SimSun, serif; white-space: pre-wrap;\">" . htmlspecialchars($tocContent, ENT_QUOTES) . "</pre>";
    }

    /**
     * Check if a line is a TOC list item with dot leader pattern.
     */
    private function isTocListItem(string $line): bool
    {
        $trimmed = trim($line);

        if (empty($trimmed)) {
            return false;
        }

        if (preg_match('/^#\s+/', $trimmed)) {
            return false;
        }

        if (preg_match('/^(\d+\.)\s+.*\.{3,}\s*\d+\s*$/', $trimmed)) {
            return true;
        }

        if (preg_match('/^([\*\-\+])\s*(\d+\.\d+(?:\.\d+)*)\s+.*\.{3,}\s*\d+\s*$/', $trimmed)) {
            return true;
        }

        if (preg_match('/^([\*\-\+])\s+.*\.{3,}\s*\d+\s*$/', $trimmed)) {
            return true;
        }

        if (preg_match('/\.{3,}\s*\d+\s*$/', $trimmed)) {
            return true;
        }

        if (preg_match('/^(\d+\.)\s+\S/', $trimmed)) {
            return true;
        }

        if (preg_match('/^([\*\-\+])\s*(\d+\.\d+(?:\.\d+)*)\s+\S/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a line is a markdown heading.
     */
    private function isHeadingLine(string $line): bool
    {
        return preg_match('/^#{1,6}\s+.*$/', trim($line)) === 1;
    }

    /**
     * Process a TOC list item line, normalizing dot leaders.
     * Converts patterns like "1. [标题](#anchor) ..... 4" to proper list format.
     * Preserves indentation and dot leaders for nested list items.
     */
    private function processTocListItem(string $line): string
    {
        $leadingSpaces = strlen($line) - strlen(ltrim($line));
        $leadingIndent = substr($line, 0, $leadingSpaces);
        $trimmed = trim($line);

        if (preg_match('/^(\d+\.)\s*([^\s].*?)(\s*)(\.{3,})(\s*)(\d+)\s*$/', $trimmed, $matches)) {
            $listMarker = $matches[1];
            $content = trim($matches[2]);
            $dotLeaders = $matches[4];
            $pageNumber = $matches[6];

            $content = $this->cleanTocContent($content);

            return $leadingIndent . $listMarker . ' ' . $content . $dotLeaders . ' ' . $pageNumber;
        }

        if (preg_match('/^([\-\*\+])(\s*)(\d+\.\d+(?:\.\d+)*)\s*([^\s].*?)(\s*)(\.{3,})(\s*)(\d+)\s*$/', $trimmed, $matches)) {
            $listMarker = $matches[1];
            $subNumber = $matches[3];
            $content = trim($matches[4]);
            $dotLeaders = $matches[6];
            $pageNumber = $matches[8];

            $content = $this->cleanTocContent($content);

            return $leadingIndent . $listMarker . ' ' . $subNumber . '. ' . $content . $dotLeaders . ' ' . $pageNumber;
        }

        if (preg_match('/^([\-\*\+])(\s*)([^\s].*?)(\s*)(\.{3,})(\s*)(\d+)\s*$/', $trimmed, $matches)) {
            $listMarker = $matches[1];
            $content = trim($matches[3]);
            $dotLeaders = $matches[5];
            $pageNumber = $matches[7];

            $content = $this->cleanTocContent($content);

            return $leadingIndent . $listMarker . ' ' . $content . $dotLeaders . ' ' . $pageNumber;
        }

        $line = preg_replace('/\.{3,}/', str_repeat('.', 20), $line);

        $line = preg_replace('/(\s+)-\s*(\d+\.)/', '$1- $2', $line);

        $line = $this->cleanTocContent($line);

        return $line;
    }

    /**
     * Clean TOC content by removing markdown links and normalizing characters.
     */
    private function cleanTocContent(string $content): string
    {
        $content = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $content);

        $content = str_replace('–', '-', $content);
        $content = str_replace('—', '-', $content);

        $content = trim($content);

        return $content;
    }

    /**
     * Process table of contents in HTML to prevent line breaks in Word output.
     * Wraps TOC items in a <pre> tag to preserve single line formatting.
     */
    private function processTableOfContentsInHtml(string $html): string
    {
        $pattern = '/(<h[1-6][^>]*>目录<\/h[1-6]>)(.*?)(?=<h[1-6]|\z)/si';

        return preg_replace_callback($pattern, function ($matches) {
            $heading = $matches[1];
            $tocContent = $matches[2];

            $lines = explode("\n", $tocContent);
            $processedLines = [];

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (empty($trimmed)) {
                    $processedLines[] = '';
                    continue;
                }

                if (preg_match('/^(\d+\.\s+.*?)\.{3,}\s*\d+\s*$/', $trimmed, $m)) {
                    $content = preg_replace('/\.{3,}\s*\d+\s*$/', '', $trimmed);
                    $content = trim($content);
                    $processedLines[] = $content;
                } elseif (preg_match('/^[\*\-\+]\s*(\d+\.\d+(?:\.\d+)*)\.\s+.*?\.{3,}\s*\d+\s*$/', $trimmed, $m)) {
                    $content = preg_replace('/\.{3,}\s*\d+\s*$/', '', $trimmed);
                    $content = trim($content);
                    $content = str_replace(['–', '—'], '-', $content);
                    $processedLines[] = $content;
                } elseif (preg_match('/\.{3,}\s*\d+\s*$/', $trimmed)) {
                    $content = preg_replace('/\.{3,}\s*\d+\s*$/', '', $trimmed);
                    $content = trim($content);
                    $content = str_replace(['–', '—'], '-', $content);
                    $processedLines[] = $content;
                } else {
                    $processedLines[] = $line;
                }
            }

            $processedToc = implode("\n", $processedLines);

            return $heading . "\n<pre style=\"font-family: SimSun, serif; white-space: pre-wrap;\">" . htmlspecialchars($processedToc, ENT_QUOTES) . "</pre>";
        }, $html);
    }

    /**
     * Detect and wrap tree/directory structures in code blocks to preserve formatting.
     * Tree structures typically use patterns like:
     *   |-- filename
     *   |   |-- subdir
     *   `-- file.txt
     */
    private function preserveTreeStructures(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $result = [];
        $inTreeBlock = false;
        $treeBlockLines = [];
        $codeBlockDepth = 0;
        $previousLine = '';

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                $codeBlockDepth = ($codeBlockDepth + 1) % 2;
                $result[] = $line;
                $previousLine = '';
                continue;
            }

            if ($codeBlockDepth > 0) {
                $result[] = $line;
                $previousLine = '';
                continue;
            }

            $isTreeLine = $this->isTreeStructureLine($line);

            if ($isTreeLine && !$inTreeBlock) {
                $inTreeBlock = true;
                $treeBlockLines = [];
                if ($previousLine !== '' && trim($previousLine) !== '') {
                    $treeBlockLines[] = $previousLine;
                }
            }

            if ($inTreeBlock) {
                $treeBlockLines[] = $line;
                if (!$isTreeLine && trim($line) !== '') {
                    $result[] = "```\n" . implode("\n", $treeBlockLines) . "\n```";
                    $treeBlockLines = [];
                    $inTreeBlock = false;
                    $previousLine = '';
                } elseif (!$isTreeLine && trim($line) === '') {
                    $treeBlockLines[] = $line;
                } else {
                    $previousLine = '';
                }
            } else {
                $result[] = $line;
                $previousLine = $line;
            }
        }

        if (!empty($treeBlockLines)) {
            $result[] = "```\n" . implode("\n", $treeBlockLines) . "\n```";
        }

        return implode("\n", $result);
    }

    /**
     * Check if a line is part of a tree/directory structure.
     * Supports various tree formats including leading whitespace.
     * Distinguishes between tree syntax (|--, |   --, `--) and table syntax (| content |).
     */
    private function isTreeStructureLine(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^\|$/', $trimmed)) {
            return true;
        }

        if (preg_match('/^(\s*)-- /', $trimmed)) {
            return true;
        }

        if (preg_match('/^(\s*)\|(\s)-- /', $trimmed)) {
            return true;
        }

        if (preg_match('/^(\s*)\|(\s{2,})-- /', $trimmed)) {
            return true;
        }

        if (preg_match('/^(\s*)`-- /', $trimmed)) {
            return true;
        }

        if (preg_match('/^\s*\|$/U', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize line endings to Unix style (\n).
     */
    private function normalizeLineEndings(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        return $markdown;
    }

    /**
     * Decode HTML entities that might interfere with pandoc parsing.
     * Only decodes entities that are safe for markdown processing.
     */
    private function decodeHtmlEntities(string $markdown): string
    {
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $markdown = str_replace(['&lt;', '&gt;', '&amp;', '&quot;'], ['<', '>', '&', '"'], $markdown);

        return $markdown;
    }

    /**
     * Fix common markdown structure issues.
     * Ensures proper spacing around headers, lists, and code blocks.
     */
    private function fixMarkdownStructure(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $fixedLines = [];
        $inPreBlock = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (strpos($line, '<pre') !== false) {
                $inPreBlock = true;
            }

            if ($inPreBlock) {
                $fixedLines[] = $line;
                if (strpos($line, '</pre>') !== false) {
                    $inPreBlock = false;
                }
                continue;
            }

            if (preg_match('/^#{1,6}\s+.*$/', $trimmed)) {
                $fixedLines[] = $trimmed;
            } elseif (preg_match('/^[\*\-\+]\s+.*$/', $trimmed) || preg_match('/^\d+\.\s+.*$/', $trimmed)) {
                $leadingSpaces = strlen($line) - strlen(ltrim($line));
                $leadingIndent = substr($line, 0, $leadingSpaces);
                $fixedLines[] = $leadingIndent . $trimmed;
            } elseif (preg_match('/^```/', $trimmed)) {
                $fixedLines[] = $trimmed;
            } elseif ($trimmed !== '') {
                $fixedLines[] = $line;
            } else {
                $fixedLines[] = $line;
            }
        }

        return implode("\n", $fixedLines);
    }

    /**
     * Preserve code block markers by ensuring proper line breaks.
     */
    private function preserveCodeBlocks(string $markdown): string
    {
        $inCodeBlock = false;
        $inPreBlock = false;
        $lines = explode("\n", $markdown);
        $result = [];

        foreach ($lines as $line) {
            if (strpos($line, '<pre') !== false) {
                $inPreBlock = true;
            }

            if ($inPreBlock) {
                $result[] = $line;
                if (strpos($line, '</pre>') !== false) {
                    $inPreBlock = false;
                }
                continue;
            }

            if (preg_match('/^```/', $line)) {
                $inCodeBlock = !$inCodeBlock;
                $result[] = $line;
            } elseif (!$inCodeBlock && trim($line) !== '' && !preg_match('/^#{1,6}\s+.*$/', trim($line)) && !preg_match('/^[\*\-\+]\s+.*$/', trim($line)) && !preg_match('/^\d+\.\s+.*$/', trim($line))) {
                $result[] = $line;
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Replace non-breaking spaces in markdown content.
     */
    private function replaceNonBreakingSpacesInMarkdown(string $markdown): string
    {
        $markdown = str_replace(['&nbsp;', '&#160;', '&#xA0;'], ' ', $markdown);
        $markdown = str_replace("\xC2\xA0", ' ', $markdown);
        return $markdown;
    }

    /**
     * Convert Markdown to DOCX using pandoc.
     */
    private function convertMarkdownToDocx(string $inputMarkdown, string $outputDocx): void
    {
        $command = [
            'pandoc',
            $inputMarkdown,
            '-o', $outputDocx,
            '--resource-path=' . dirname($inputMarkdown),
            '--self-contained',
            '--from=markdown',
            '--wrap=preserve',
            '--standalone',
        ];

        $templatePath = config('app.word_export_template', '/app/medical_device_template.docx');
        if (file_exists($templatePath)) {
            $command[] = '--reference-doc=' . $templatePath;
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (file_exists($inputMarkdown)) {
            @unlink($inputMarkdown);
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->removeSoftBreaksFromDocx($outputDocx);
        $this->addTableBordersToDocx($outputDocx);
        $this->preventTableBreakAcrossPages($outputDocx);
        $this->centerHeadingsInDocx($outputDocx);
        $this->applyFirstParagraphStyleToDocx($outputDocx);
        $this->centerTablesInDocx($outputDocx);
        $this->setWideTablesToLandscape($outputDocx);

        if (!file_exists($outputDocx) || filesize($outputDocx) === 0) {
            throw new \Exception('Generated Word document is empty or does not exist');
        }
    }

    /**
     * Create temporary HTML file for export.
     */
    private function createTempHtmlFile($page, string $tempDir): string
    {
        // Build full HTML document
        $htmlContent = $this->buildExportHtml($page);
        
        // Create temporary file
        $tempFile = tempnam($tempDir, 'bookstack_html_') . '.html';
        file_put_contents($tempFile, $htmlContent);
        
        return $tempFile;
    }

    /**
     * Build HTML content for export.
     */
    private function buildExportHtml($page): string
    {
        $pageContent = (new PageContent($page));
        $page->html = $pageContent->render();
        
        $baseUrl = url('/');
        $htmlContent = preg_replace('/src="\/(uploads\/[^"]+)"/', 'src="' . $baseUrl . '/$1"', $page->html);
        $htmlContent = preg_replace('/src="\/(storage\/[^"]+)"/', 'src="' . $baseUrl . '/$1"', $htmlContent);
        
        // 移除第一个 h1 标签（文档名称）
        $htmlContent = $this->removeFirstH1($htmlContent);

        // 处理审批表格（编制/审核/批准），清除填写内容并在表格后添加分页
        $htmlContent = $this->processApprovalTable($htmlContent);

        // 处理目录结构，防止 Word 转换时换行
        $htmlContent = $this->processTableOfContentsInHtml($htmlContent);

        // 为正文第一个块级元素应用样式（居中、作为标题）
        $htmlContent = $this->styleFirstElementAsHeading($htmlContent);

        // 3. 缩进占位符（模拟首行缩进）
        $htmlContent = $this->insertIndentPlaceholders($htmlContent);
        
        // 4. 替换不间断空格（消除小圆圈）
        $htmlContent = $this->replaceNonBreakingSpaces($htmlContent);

        // 5. 移除不存在的图片（防止 pandoc 报错）
        $htmlContent = $this->removeInvalidImages($htmlContent);

        // 6. 转换勾选框字符为 Word 兼容格式
        $htmlContent = $this->convertCheckboxesForWord($htmlContent);

        // 7. 转换字母列表项为 HTML 列表格式
        $htmlContent = $this->convertLetterListsToHtml($htmlContent);

        // 8. 处理 br 标签，转换为独立段落以保留换行
        $htmlContent = $this->convertBrTagsToParagraphs($htmlContent);

        // 9. 构建最终 HTML
        $exportStyles = $this->getExportStyles();
        

        $htmlString = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>{$exportStyles}</style>
</head>
<body>
    {$htmlContent}
</body>
</html>
HTML;

        return $htmlString;
    }

    /**
     * 移除HTML内容中的第一个h1标签
     */
    private function removeFirstH1(string $html): string
    {
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $h1s = $dom->getElementsByTagName('h1');
            if ($h1s->length > 0) {
                $firstH1 = $h1s->item(0);
                $firstH1->parentNode->removeChild($firstH1);
                $html = $dom->saveHTML();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove first h1 tag: ' . $e->getMessage());
        }

        return $html;
    }

    /**
     * 保存调试用的 HTML 到日志目录，方便排查问题。
     *
     * @param string $html HTML 内容
     * @param string $stage 阶段名称
     * @return void
     */
    private function saveDebugHtml(string $html, string $stage): void
    {
        $debugPath = storage_path('logs/export_debug_' . time() . '_' . $stage . '_' . uniqid() . '.html');
        file_put_contents($debugPath, $html);
        Log::info('Word export debug HTML saved', ['path' => $debugPath, 'stage' => $stage]);
    }

    /**
     * 保护表格内容：将表格用占位符替换，处理完成后再还原。
     *
     * @param string $html 原始 HTML
     * @return array [$modifiedHtml, $tablePlaceholders
     */
    private function protectTableContent(string $html): array
    {
        $tablePlaceholders = [];
        $tableIndex = 0;

        if (strpos($html, '<table') === false) {
            return [$html, $tablePlaceholders];
        }

        $html = preg_replace_callback('/<table[^>]*>[\s\S]*?<\/table>/i', function ($matches) use (&$tablePlaceholders, &$tableIndex) {
            $placeholder = "\x00TABLE_PLACEHOLDER_{$tableIndex}\x00";
            $tablePlaceholders[$tableIndex++] = $matches[0];
            return $placeholder;
        }, $html);

        return [$html, $tablePlaceholders];
    }

    /**
     * 还原表格内容，将占位符替换回原表格内容。
     *
     * @param string $html 处理后的 HTML（含占位符）
     * @param array $tablePlaceholders 表格占位符数组
     * @return string 还原后的 HTML
     */
    private function restoreTableContent(string $html, array $tablePlaceholders): string
    {
        foreach ($tablePlaceholders as $index => $tableHtml) {
            $html = str_replace("\x00TABLE_PLACEHOLDER_{$index}\x00", $tableHtml, $html);
        }
        return $html;
    }

    /**
     * 处理文档中的审批表格（编制/审核/批准）。
     * 检测 HTML 中是否包含审批信息表格，如果找到则：
     * 1. 清除填写内容（xxx 部分），保留标签（编制：、审核：、批准：、日期：）
     * 2. 在标签后保留6个全角空格占位符
     * 3. 在表格后添加分页符，使后续内容从新页开始
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function processApprovalTable(string $html): string
    {
        if (strpos($html, '<table') === false) {
            return $html;
        }

        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tables = $dom->getElementsByTagName('table');
        $found = false;

        foreach ($tables as $table) {
            if ($this->isApprovalTable($table)) {
                $this->clearApprovalTableContent($table);

                $changeRecordTable = $this->findChangeRecordTable($table);
                if ($changeRecordTable !== null) {
                    $this->addPageBreakAfterTable($changeRecordTable, $dom);
                    Log::info('Found change record table, added page break after it');
                } else {
                    $this->addPageBreakAfterTable($table, $dom);
                }

                $found = true;
                Log::info('Found and processed approval table (编制/审核/批准) in HTML export');
                break;
            }
        }

        if (!$found) {
            return $html;
        }

        $innerHtml = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $innerHtml .= $dom->saveHTML($child);
        }

        return $innerHtml;
    }

    /**
     * 查找审批表格后的"更改记录"相关表格。
     * 如果审批表格后有"更改记录"标题，并且"更改记录"后紧跟一个表格，返回该表格。
     *
     * @param \DOMElement $approvalTable 审批表格元素
     * @return \DOMElement|null 更改记录表格，如果没找到则返回null
     */
    private function findChangeRecordTable(\DOMElement $approvalTable): ?\DOMElement
    {
        $sibling = $approvalTable->nextSibling;
        $changeRecordFound = false;
        $pendingTable = null;

        while ($sibling !== null) {
            if ($sibling instanceof \DOMText && trim($sibling->textContent) !== '') {
                if (preg_match('/更改记录/i', $sibling->textContent)) {
                    $changeRecordFound = true;
                    if ($pendingTable !== null) {
                        return $pendingTable;
                    }
                }
            } elseif ($sibling instanceof \DOMElement) {
                if ($sibling->nodeName === 'table') {
                    if ($changeRecordFound) {
                        return $sibling;
                    }
                    $pendingTable = $sibling;
                } else {
                    if ($this->elementContainsText($sibling, '更改记录')) {
                        $changeRecordFound = true;
                        if ($pendingTable !== null) {
                            return $pendingTable;
                        }
                    }
                }
            }

            $sibling = $sibling->nextSibling;
        }

        return null;
    }

    /**
     * 检查元素及其子元素是否包含指定文本。
     */
    private function elementContainsText(\DOMElement $element, string $searchText): bool
    {
        if (mb_strpos($element->textContent, $searchText) !== false) {
            return true;
        }
        return false;
    }

    /**
     * 检查表格是否为审批表格。
     * 审批表格必须至少包含「编制」「审核」「批准」中的2个关键词。
     *
     * @param \DOMElement $table 表格元素
     * @return bool 是否为审批表格
     */
    private function isApprovalTable(\DOMElement $table): bool
    {
        $text = $table->textContent;
        $keywords = ['编制', '审核', '批准'];
        $foundCount = 0;

        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                $foundCount++;
            }
        }

        return $foundCount >= 2;
    }

    /**
     * 清除审批表格中各个单元格的填写内容。
     * 保留标签（编制：、审核：、批准：、日期：），将标签后的内容替换为6个全角空格。
     *
     * 支持两种表格格式：
     *   A) 标签和值在同一单元格：<td>编制：张三</td> → <td>编制：　　　　　　</td>
     *   B) 标签和值在不同单元格：<td>编制</td><td>张三</td> → <td>编制：</td><td>　　　　　　</td>
     *
     * @param \DOMElement $table 表格元素
     */
    private function clearApprovalTableContent(\DOMElement $table): void
    {
        $placeholder = str_repeat('_', 10);

        $labelKeywords = ['编制', '审核', '批准', '日期'];

        $rows = $table->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['td', 'th'])) {
                    $cells[] = $child;
                }
            }

            $cellCount = count($cells);
            for ($i = 0; $i < $cellCount; $i++) {
                $cell = $cells[$i];
                $text = trim($cell->textContent);

                foreach ($labelKeywords as $keyword) {
                    $fullContent = $keyword . '：' . $placeholder;

                    if (preg_match('/^' . preg_quote($keyword, '/') . '\s*[：:]\s*\S+/u', $text)) {
                        $this->replaceCellContentRecursive($cell, $keyword . '：', $placeholder);
                        break;
                    }

                    if (preg_match('/^' . preg_quote($keyword, '/') . '\s*[：:]?\s*$/u', $text)) {
                        $this->replaceCellContentRecursive($cell, $keyword . '：', $placeholder);
                        for ($j = $i + 1; $j < $cellCount; $j++) {
                            $nextText = trim($cells[$j]->textContent);
                            $isNextLabel = false;
                            foreach ($labelKeywords as $lk) {
                                if (preg_match('/^' . preg_quote($lk, '/') . '\s*[：:]?\s*$/u', $nextText)) {
                                    $isNextLabel = true;
                                    break;
                                }
                            }
                            if ($isNextLabel) {
                                break;
                            }
                            $this->replaceCellContentRecursive($cells[$j], '', $placeholder);
                            break;
                        }
                        break;
                    }
                }
            }
        }
    }

    private function replaceCellContentRecursive(\DOMElement $cell, string $prefix, string $placeholder): void
    {
        $fullText = $prefix . $placeholder;

        $hasNestedElements = false;
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $hasNestedElements = true;
                break;
            }
        }

        if (!$hasNestedElements) {
            $this->replaceCellContent($cell, $fullText);
            return;
        }

        $text = trim($cell->textContent);
        $keywords = ['编制', '审核', '批准', '日期'];

        foreach ($keywords as $keyword) {
            $pattern = '/(' . preg_quote($keyword, '/') . '\s*[：:]\s*)\S.*/u';
            if (preg_match($pattern, $text)) {
                $text = preg_replace($pattern, $keyword . '：' . $placeholder, $text);
                break;
            }
        }

        if ($text === trim($cell->textContent)) {
            $text = $fullText;
        }

        while ($cell->hasChildNodes()) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->appendChild($cell->ownerDocument->createTextNode($text));
    }

    /**
     * 替换单元格的全部内容。
     *
     * @param \DOMElement $cell 单元格元素
     * @param string $newContent 新的文本内容
     */
    private function replaceCellContent(\DOMElement $cell, string $newContent): void
    {
        while ($cell->hasChildNodes()) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->appendChild($cell->ownerDocument->createTextNode($newContent));
    }

    /**
     * 在表格后添加分页段落，使表格往下的内容在新的一页展示。
     * 使用不含空格的段落 + CSS page-break-before，pandoc 转换后为空段接新页。
     *
     * @param \DOMElement $table 表格元素
     * @param \DOMDocument $dom DOM 文档对象
     */
    private function addPageBreakAfterTable(\DOMElement $table, \DOMDocument $dom): void
    {
        $pageBreakP = $dom->createElement('p');
        $pageBreakP->setAttribute('style', 'page-break-before: always;');
        $pageBreakP->appendChild($dom->createTextNode("\xC2\xA0"));

        if ($table->nextSibling) {
            $table->parentNode->insertBefore($pageBreakP, $table->nextSibling);
        } else {
            $table->parentNode->appendChild($pageBreakP);
        }
    }

    /**
     * 在 Word 文档中审批表格后添加分页。
     * 通过直接操作 docx 的 OOXML 结构，在审批表格后的段落添加分页属性。
     * 如果审批表格后有"更改记录"，分页点延迟到"更改记录"表格之后。
     *
     * @param string $docxPath Word 文档路径
     */
    private function addPageBreakAfterApprovalTableInDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot add page break after approval table');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for adding page break after approval table');
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $tables = $xpath->query('//w:tbl');

        foreach ($tables as $table) {
            if ($this->isApprovalTableInDocx($table, $xpath)) {
                $changeRecordParagraph = null;
                $changeRecordTable = $this->findChangeRecordTableInDocx($table, $xpath, $changeRecordParagraph);
                if ($changeRecordTable !== null) {
                    if ($changeRecordParagraph !== null) {
                        $paragraphFirst = $this->isElementBeforeInDocument($changeRecordParagraph, $changeRecordTable);

                        if ($paragraphFirst) {
                            $firstElement = $changeRecordParagraph;
                            $lastElement = $changeRecordTable;
                        } else {
                            $firstElement = $changeRecordTable;
                            $lastElement = $changeRecordParagraph;
                        }

                        $this->removePageBreaksBetweenElements($firstElement, $lastElement, $dom, $wNs);

                        if ($firstElement->nodeName === 'w:p') {
                            $this->addPageBreakBeforeParagraph($firstElement, $dom, $wNs);
                        } else {
                            $firstElement->parentNode->insertBefore(
                                $this->createPageBreakParagraph($dom, $wNs),
                                $firstElement
                            );
                        }

                        if ($lastElement->nodeName === 'w:tbl') {
                            $this->addPageBreakAfterTableInDocx($lastElement, $dom, $xpath, $wNs);
                        } else {
                            $nextSibling = $lastElement->nextSibling;
                            $breakP = $this->createPageBreakParagraph($dom, $wNs);
                            if ($nextSibling) {
                                $lastElement->parentNode->insertBefore($breakP, $nextSibling);
                            } else {
                                $lastElement->parentNode->appendChild($breakP);
                            }
                        }
                    } else {
                        $this->addPageBreakAfterTableInDocx($changeRecordTable, $dom, $xpath, $wNs);
                    }
                    Log::info('Added page break before and after change record table in docx');
                } else {
                    $this->addPageBreakAfterTableInDocx($table, $dom, $xpath, $wNs);
                    Log::info('Added page break after approval table in docx');
                }
                break;
            }
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();
    }

    /**
     * 在 Word 文档中查找审批表格后的"更改记录"表格。
     *
     * @param \DOMElement $approvalTable 审批表格元素
     * @param \DOMXPath $xpath DOM XPath 对象
     * @param \DOMElement|null $changeRecordParagraph [输出] 包含"更改记录"的段落元素引用
     * @return \DOMElement|null 更改记录表格，如果没找到则返回null
     */
    private function findChangeRecordTableInDocx(\DOMElement $approvalTable, \DOMXPath $xpath, ?\DOMElement &$changeRecordParagraph = null): ?\DOMElement
    {
        $sibling = $approvalTable->nextSibling;
        $changeRecordFound = false;
        $pendingTable = null;
        $changeRecordParagraph = null;

        while ($sibling !== null) {
            if ($sibling instanceof \DOMElement) {
                if ($sibling->nodeName === 'w:tbl') {
                    if ($changeRecordFound) {
                        return $sibling;
                    }
                    $pendingTable = $sibling;
                } elseif ($sibling->nodeName === 'w:p') {
                    $textContent = '';
                    $textNodes = $xpath->query('.//w:t', $sibling);
                    foreach ($textNodes as $t) {
                        $textContent .= $t->textContent;
                    }

                    if (preg_match('/更改记录/i', $textContent)) {
                        $changeRecordFound = true;
                        $changeRecordParagraph = $sibling;
                        if ($pendingTable !== null) {
                            return $pendingTable;
                        }
                    }
                }
            }

            $sibling = $sibling->nextSibling;
        }

        return null;
    }

    /**
     * 检查 Word 文档中的表格是否为审批表格。
     */
    private function isApprovalTableInDocx(\DOMElement $table, \DOMXPath $xpath): bool
    {
        $textContent = '';
        $textNodes = $xpath->query('.//w:t', $table);
        foreach ($textNodes as $t) {
            $textContent .= $t->textContent;
        }

        $keywords = ['编制', '审核', '批准'];
        $foundCount = 0;
        foreach ($keywords as $keyword) {
            if (mb_strpos($textContent, $keyword) !== false) {
                $foundCount++;
            }
        }

        return $foundCount >= 2;
    }

    /**
     * 在 Word 文档的表格后添加分页段落的实际逻辑。
     */
    private function addPageBreakAfterTableInDocx(\DOMElement $table, \DOMDocument $dom, \DOMXPath $xpath, string $wNs): void
    {
        $tableRows = $xpath->query('w:tr', $table);
        if ($tableRows->length === 0) {
            return;
        }

        $lastRow = $tableRows->item($tableRows->length - 1);
        $lastRowCells = $xpath->query('w:tc', $lastRow);
        if ($lastRowCells->length === 0) {
            return;
        }

        $pageBreakParagraph = $dom->createElementNS($wNs, 'w:p');
        $pageBreakPPr = $dom->createElementNS($wNs, 'w:pPr');
        $pageBreakBefore = $dom->createElementNS($wNs, 'w:pageBreakBefore');
        $pageBreakPPr->appendChild($pageBreakBefore);
        $pageBreakParagraph->appendChild($pageBreakPPr);

        $nextSibling = $table->nextSibling;
        if ($nextSibling) {
            $table->parentNode->insertBefore($pageBreakParagraph, $nextSibling);
        } else {
            $table->parentNode->appendChild($pageBreakParagraph);
        }
    }

    /**
     * 在 Word 文档的指定段落前添加分页属性。
     * 通过给该段落的 w:pPr 添加 w:pageBreakBefore 元素实现。
     *
     * @param \DOMElement $paragraph 目标段落元素
     * @param \DOMDocument $dom DOM 文档对象
     * @param string $wNs WordprocessingML 命名空间
     */
    private function addPageBreakBeforeParagraph(\DOMElement $paragraph, \DOMDocument $dom, string $wNs): void
    {
        $pPr = null;
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'w:pPr') {
                $pPr = $child;
                break;
            }
        }

        if ($pPr === null) {
            $pPr = $dom->createElementNS($wNs, 'w:pPr');
            if ($paragraph->firstChild) {
                $paragraph->insertBefore($pPr, $paragraph->firstChild);
            } else {
                $paragraph->appendChild($pPr);
            }
        }

        $pageBreakBefore = $dom->createElementNS($wNs, 'w:pageBreakBefore');
        $pPr->appendChild($pageBreakBefore);
    }

    /**
     * 判断两个同级 DOM 元素在文档中的顺序。
     * 通过遍历 sibling 链表来判断 a 是否在 b 之前。
     *
     * @param \DOMElement $a 第一个元素
     * @param \DOMElement $b 第二个元素
     * @return bool true 表示 $a 在 $b 之前
     */
    private function isElementBeforeInDocument(\DOMElement $a, \DOMElement $b): bool
    {
        $sibling = $a->nextSibling;
        while ($sibling !== null) {
            if ($sibling === $b) {
                return true;
            }
            $sibling = $sibling->nextSibling;
        }
        return false;
    }

    /**
     * 创建一个带 w:pageBreakBefore 属性的分页段落。
     *
     * @param \DOMDocument $dom DOM 文档对象
     * @param string $wNs WordprocessingML 命名空间
     * @return \DOMElement 创建的分页段落元素
     */
    private function createPageBreakParagraph(\DOMDocument $dom, string $wNs): \DOMElement
    {
        $p = $dom->createElementNS($wNs, 'w:p');
        $pPr = $dom->createElementNS($wNs, 'w:pPr');
        $pageBreakBefore = $dom->createElementNS($wNs, 'w:pageBreakBefore');
        $pPr->appendChild($pageBreakBefore);
        $p->appendChild($pPr);
        return $p;
    }

    /**
     * 移除两个同级 DOM 元素之间所有带分页属性的 w:p 段落。
     * 用于在"更改记录"段落和其表格之间清理被 preventTableBreakAcrossPages
     * 误插入的分页段落，确保段落和表格连续排版在同一页面。
     *
     * @param \DOMElement $startElement 起始元素（不含）
     * @param \DOMElement $endElement 结束元素（不含）
     * @param \DOMDocument $dom DOM 文档对象
     * @param string $wNs WordprocessingML 命名空间
     */
    private function removePageBreaksBetweenElements(\DOMElement $startElement, \DOMElement $endElement, \DOMDocument $dom, string $wNs): void
    {
        $nodesToRemove = [];
        $sibling = $startElement->nextSibling;

        while ($sibling !== null && $sibling !== $endElement) {
            if ($sibling instanceof \DOMElement && $sibling->nodeName === 'w:p') {
                $pPrNodes = $sibling->getElementsByTagNameNS($wNs, 'pPr');
                if ($pPrNodes->length > 0) {
                    $pageBreaks = $pPrNodes->item(0)->getElementsByTagNameNS($wNs, 'pageBreakBefore');
                    if ($pageBreaks->length > 0) {
                        $nodesToRemove[] = $sibling;
                    }
                }
            }
            $sibling = $sibling->nextSibling;
        }

        foreach ($nodesToRemove as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    /**
     * 处理 Markdown 内容中的审批表格（编制/审核/批准）。
     * 用于 Markdown 导出路径，当 HTML 表格被转换为 Markdown 表格格式后调用。
     * 检测 Markdown 表格中的审批关键词，清除填写内容并添加分页。
     *
     * @param string $markdown Markdown 内容
     * @return string 处理后的 Markdown
     */
    private function processApprovalTableInMarkdown(string $markdown): string
    {
        $keywords = ['编制', '审核', '批准'];
        $foundCount = 0;
        foreach ($keywords as $kw) {
            if (mb_strpos($markdown, $kw) !== false) {
                $foundCount++;
            }
        }
        if ($foundCount < 2) {
            return $markdown;
        }

        $lines = explode("\n", $markdown);
        $inTable = false;
        $tableStartIndex = -1;
        $tableLines = [];
        $fixedLines = [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            $isTableRow = preg_match('/^\|.+\\|$/', $trimmed) && strpos($trimmed, '---') === false && strpos($trimmed, ':--') === false && !preg_match('/^\|[\s\-:]+\|$/', $trimmed);
            $isSeparator = preg_match('/^\|[\s\-:]+\|$/', $trimmed);

            if ($isTableRow && !$inTable) {
                $inTable = true;
                $tableStartIndex = count($fixedLines);
                $tableLines = [$line];
            } elseif (($isTableRow || $isSeparator) && $inTable) {
                $tableLines[] = $line;
            } elseif ($inTable) {
                $tableText = implode("\n", $tableLines);
                $isApproval = false;
                foreach ($keywords as $kw) {
                    if (mb_strpos($tableText, $kw) !== false) {
                        $isApproval = true;
                        break;
                    }
                }

                if ($isApproval) {
                    $clearedLines = $this->clearApprovalMarkdownTableLines($tableLines);
                    $fixedLines = array_merge($fixedLines, $clearedLines);
                    $fixedLines[] = '';
                    $fixedLines[] = '\newpage';
                    $fixedLines[] = '';
                    Log::info('Found and processed approval table in Markdown export');
                } else {
                    $fixedLines = array_merge($fixedLines, $tableLines);
                }

                $fixedLines[] = $line;
                $inTable = false;
                $tableLines = [];
            } else {
                $fixedLines[] = $line;
            }
        }

        if ($inTable && !empty($tableLines)) {
            $tableText = implode("\n", $tableLines);
            $isApproval = false;
            foreach ($keywords as $kw) {
                if (mb_strpos($tableText, $kw) !== false) {
                    $isApproval = true;
                    break;
                }
            }

            if ($isApproval) {
                $clearedLines = $this->clearApprovalMarkdownTableLines($tableLines);
                $fixedLines = array_merge($fixedLines, $clearedLines);
                $fixedLines[] = '';
                $fixedLines[] = '\newpage';
                $fixedLines[] = '';
                Log::info('Found and processed approval table in Markdown export (end of content)');
            } else {
                $fixedLines = array_merge($fixedLines, $tableLines);
            }
        }

        return implode("\n", $fixedLines);
    }

    /**
     * 清除 Markdown 审批表格行中的填写内容。
     * 保留标签（编制：、审核：、批准：、日期：），将标签后的内容替换为6个全角空格。
     *
     * 支持两种表格格式：
     *   A) 标签和值在同一单元格：| 编制：张三 | → | 编制：　　　　　　|
     *   B) 标签和值在不同单元格：| 编制 | 张三 | → | 编制： | 　　　　　　|
     *
     * @param array $tableLines Markdown 表格行数组
     * @return array 处理后的表格行
     */
    private function clearApprovalMarkdownTableLines(array $tableLines): array
    {
        $fullwidthSpace = '　';
        $placeholder = str_repeat($fullwidthSpace, 6);
        $labelKeywords = ['编制', '审核', '批准', '日期'];

        $result = [];
        foreach ($tableLines as $line) {
            $trimmed = trim($line);

            // 分隔行保持不变
            if (preg_match('/^\|[\s\-:]+\|$/', $trimmed)) {
                $result[] = $line;
                continue;
            }

            $cells = explode('|', $trimmed);
            $cells = array_map('trim', $cells);
            // 过滤首尾空元素
            $cells = array_values(array_filter($cells, function ($c, $i) use ($cells) {
                return $c !== '' || ($i > 0 && $i < count($cells) - 1);
            }, ARRAY_FILTER_USE_BOTH));

            $cellCount = count($cells);
            $modifiedCells = $cells;
            $skipIndexes = [];

            for ($i = 0; $i < $cellCount; $i++) {
                if (isset($skipIndexes[$i])) {
                    continue;
                }

                $cell = $cells[$i];
                if ($cell === '') {
                    continue;
                }

                foreach ($labelKeywords as $keyword) {
                    // 情况A：标签+值在同一单元格，如 "编制：张三"
                    if (preg_match('/^' . preg_quote($keyword, '/') . '\s*[：:]\s*(.+)/u', $cell)) {
                        $modifiedCells[$i] = $keyword . '：' . $placeholder;
                        break;
                    }

                    // 情况B：单元格只有标签文本，如 "编制" 或 "编制："
                    if (preg_match('/^' . preg_quote($keyword, '/') . '\s*[：:]?\s*$/u', $cell)) {
                        $modifiedCells[$i] = $keyword . '：';

                        // 清空右侧相邻的值单元格
                        for ($j = $i + 1; $j < $cellCount; $j++) {
                            $nextCell = $cells[$j];
                            if ($nextCell === '') {
                                continue;
                            }
                            $isNextLabel = false;
                            foreach ($labelKeywords as $lk) {
                                if (preg_match('/^' . preg_quote($lk, '/') . '\s*[：:]?\s*$/u', $nextCell)) {
                                    $isNextLabel = true;
                                    break;
                                }
                            }
                            if ($isNextLabel) {
                                break;
                            }
                            $modifiedCells[$j] = $placeholder;
                            $skipIndexes[$j] = true;
                            break;
                        }
                        break;
                    }
                }
            }

            $result[] = '| ' . implode(' | ', $modifiedCells) . ' |';
        }

        return $result;
    }

    /**
     * 移除HTML中不存在的图片引用。
     * pandoc --self-contained 模式下，如果图片不存在会直接报错退出。
     * 此方法确保只有存在的图片会被包含在HTML中。
     */
    private function removeInvalidImages(string $html): string
    {
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $images = $dom->getElementsByTagName('img');

            $toRemove = [];
            foreach ($images as $img) {
                $src = $img->getAttribute('src');
                if (empty($src)) {
                    continue;
                }

                $isLocalFile = !$this->isRemoteUrl($src);
                if ($isLocalFile) {
                    $localPath = $this->resolveLocalPath($src);
                    if (!file_exists($localPath)) {
                        $toRemove[] = $img;
                    }
                }
            }

            foreach ($toRemove as $img) {
                $img->parentNode->removeChild($img);
            }

            if (count($toRemove) > 0) {
                Log::info('Removed invalid images from export HTML', [
                    'count' => count($toRemove)
                ]);
                $html = $dom->saveHTML();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to remove invalid images: ' . $e->getMessage());
        }

        return $html;
    }

    /**
     * 判断URL是否为远程URL
     */
    private function isRemoteUrl(string $url): bool
    {
        return str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, '//');
    }

    /**
     * 将相对路径或绝对URL转换为本地文件系统路径
     */
    private function resolveLocalPath(string $src): string
    {
        if (str_starts_with($src, 'file://')) {
            return substr($src, 7);
        }

        if (str_starts_with($src, '/')) {
            return public_path(ltrim($src, '/'));
        }

        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            $parsed = parse_url($src);
            if (isset($parsed['path'])) {
                return public_path(ltrim($parsed['path'], '/'));
            }
        }

        return public_path($src);
    }

    /**
     * 将 HTML 中的 Unicode 勾选框字符转换为 Word/WPS 兼容的格式。
     * Unicode 勾选框字符（☐☒☑）在转换后可能显示为 £ 或 R 等错误符号，
     * 此方法通过使用 HTML 字符引用来避免编码问题。
     * 同时处理 HTML checkbox input 元素和 Wingdings 字体符号。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function convertCheckboxesForWord(string $html): string
    {
        $codeBlocks = [];
        $codeBlockIndex = 0;

        $html = preg_replace_callback('/<(code|pre)[^>]*>.*?<\/\\1>/is', function ($matches) use (&$codeBlocks, &$codeBlockIndex) {
            $placeholder = "\x00CODE_BLOCK_PLACEHOLDER_{$codeBlockIndex}\x00";
            $codeBlocks[$codeBlockIndex++] = $matches[0];
            return $placeholder;
        }, $html);

        $html = $this->convertCheckboxInputsToCharacters($html);
        $html = $this->convertWingdingsCheckboxesToCharacters($html);

        $checkboxMappings = [
            '☐' => '&#9744;',
            '☒' => '&#9746;',
            '☑' => '&#9745;',
            '☸' => '&#9784;',
            '○' => '&#9675;',
            '●' => '&#9679;',
            '◉' => '&#9673;',
            '✅' => '&#9989;',
            '❌' => '&#10060;',
        ];

        foreach ($checkboxMappings as $unicode => $htmlEntity) {
            $html = str_replace($unicode, $htmlEntity, $html);
        }

        foreach ($codeBlocks as $index => $codeBlock) {
            $html = str_replace("\x00CODE_BLOCK_PLACEHOLDER_{$index}\x00", $codeBlock, $html);
        }

        return $html;
    }

    /**
     * 将 Wingdings/Wingdings 2 字体的勾选框符号转换为标准 Unicode 字符。
     * Wingdings 2 中：£/O = 空心方框(未勾选), R = 打勾方框, P = 打叉方框
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function convertWingdingsCheckboxesToCharacters(string $html): string
    {
        $html = $this->replaceNestedWingdingsCheckboxes($html);

        $charToEntity = [
            'R' => '&#9745;',
            '£' => '&#9744;',
            'O' => '&#9744;',
            'P' => '&#9746;',
        ];

        foreach ($charToEntity as $char => $entity) {
            $escapedChar = preg_quote($char, '/');
            $html = preg_replace('/<span[^>]*mso-symbol-font-family:\s*["\']Wingdings 2?["\'][^>]*>' . $escapedChar . '<\/span>/i', $entity, $html);
            $html = preg_replace('/<span[^>]*font-family:\s*["\']Wingdings 2?["\'][^>]*>' . $escapedChar . '<\/span>/i', $entity, $html);

            $html = preg_replace('/<span[^>]*mso-symbol-font-family:\s*["\']Wingdings 2?["\'][^>]*><span[^>]*>' . $escapedChar . '<\/span><\/span>/i', $entity, $html);
            $html = preg_replace('/<span[^>]*font-family:\s*["\']Wingdings 2?["\'][^>]*><span[^>]*>' . $escapedChar . '<\/span><\/span>/i', $entity, $html);
        }

        $html = str_replace('£', '&#9744;', $html);

        return $html;
    }

    /**
     * 处理嵌套的 Wingdings 勾选框 span 结构。
     * 将包含 £/O/R/P 字符及其父级 Wingdings span 一起替换。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function replaceNestedWingdingsCheckboxes(string $html): string
    {
        $charToEntity = [
            'R' => '&#9745;',
            '£' => '&#9744;',
            'O' => '&#9744;',
            'P' => '&#9746;',
        ];

        foreach ($charToEntity as $char => $entity) {
            $escapedChar = preg_quote($char, '/');
            $pattern = '/(<span[^>]*mso-symbol-font-family:\s*["\']Wingdings 2?["\'][^>]*>)<span[^>]*>' . $escapedChar . '<\/span>(<\/span>)/i';
            $html = preg_replace($pattern, $entity, $html);

            $pattern = '/(<span[^>]*font-family:\s*["\']Wingdings 2?["\'][^>]*>)<span[^>]*>' . $escapedChar . '<\/span>(<\/span>)/i';
            $html = preg_replace($pattern, $entity, $html);
        }

        return $html;
    }

    /**
     * 将 HTML checkbox input 元素转换为 Unicode 字符。
     * checked 状态的 checkbox 转换为 ☑，unchecked 转换为 ☐。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function convertCheckboxInputsToCharacters(string $html): string
    {
        $html = preg_replace('/<input[^>]*type=["\']checkbox["\'][^>]*checked[^>]*>/i', '☑', $html);
        $html = preg_replace('/<input[^>]*type=["\']checkbox["\'][^>]*>/i', '☐', $html);

        return $html;
    }

    /**
     * 将字母列表项（a), b), c) 等）转换为 HTML 有序列表格式。
     * 确保 Pandoc 转换时保留列表结构和换行。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function convertLetterListsToHtml(string $html): string
    {
        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $paragraphs = $xpath->query('//p[contains(text(), ")")]');

        if ($paragraphs->length < 2) {
            return $html;
        }

        $listItems = [];
        $listStartIndex = -1;
        $firstParagraph = null;

        foreach ($paragraphs as $index => $p) {
            if ($this->isElementInsideTable($p, $xpath)) {
                continue;
            }

            $text = trim($p->textContent);
            if (preg_match('/^([a-z])\)\s*/i', $text, $matches)) {
                if ($listStartIndex === -1) {
                    $listStartIndex = $index;
                    $firstParagraph = $p;
                }
                $content = preg_replace('/^[a-z]\)\s*/i', '', $text);
                $listItems[] = [
                    'paragraph' => $p,
                    'content' => $content,
                    'letter' => strtolower($matches[1])
                ];
            } else {
                if (count($listItems) >= 2) {
                    break;
                }
                $listItems = [];
                $listStartIndex = -1;
                $firstParagraph = null;
            }
        }

        if (count($listItems) < 2 || $firstParagraph === null) {
            return $html;
        }

        $ol = $dom->createElement('ol');
        $ol->setAttribute('type', 'a');

        foreach ($listItems as $item) {
            $li = $dom->createElement('li');
            $liText = $dom->createTextNode($item['content']);
            $li->appendChild($liText);
            $ol->appendChild($li);
        }

        $firstParagraph->parentNode->insertBefore($ol, $firstParagraph);

        foreach ($listItems as $item) {
            $p = $item['paragraph'];
            if ($p->parentNode) {
                $p->parentNode->removeChild($p);
            }
        }

        $body = $xpath->query('//body')->item(0);
        if ($body) {
            $innerHtml = '';
            foreach ($body->childNodes as $child) {
                $innerHtml .= $dom->saveHTML($child);
            }
            return $innerHtml;
        }

        return $dom->saveHTML();
    }

    /**
     * 检查元素是否位于表格单元格内。
     * 用于防止在表格内容上错误执行列表转换等操作。
     *
     * @param \DOMElement $element 要检查的元素
     * @param \DOMXPath $xpath DOM XPath 查询对象
     * @return bool 如果元素在表格单元格内返回 true，否则返回 false
     */
    private function isElementInsideTable(\DOMElement $element, \DOMXPath $xpath): bool
    {
        $ancestors = $xpath->query('ancestor::td|ancestor::th|ancestor::table', $element);
        return $ancestors->length > 0;
    }

    /**
     * 将 <p> 标签内的 <br> 标签转换为独立段落，以保留换行结构。
     * 处理包含字母列表项（a), b), c) 等）的段落。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function convertBrTagsToParagraphs(string $html): string
    {
        if (strpos($html, '<br') === false) {
            return $html;
        }

        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $paragraphs = $xpath->query('//p[br]');

        if ($paragraphs->length === 0) {
            return $html;
        }

        $modified = false;

        foreach ($paragraphs as $p) {
            if ($this->isElementInsideTable($p, $xpath)) {
                continue;
            }

            $innerHtml = $dom->saveHTML($p);

            if (preg_match_all('/<br\s*\/?>/i', $innerHtml, $brMatches)) {
                $parts = preg_split('/<br\s*\/?>/i', $innerHtml);

                if (count($parts) < 2) {
                    continue;
                }

                $listItems = [];
                $headingText = '';
                $nonListParts = [];

                foreach ($parts as $part) {
                    $part = trim($part);
                    $part = preg_replace('/^<p[^>]*>/', '', $part);
                    $part = preg_replace('/<\/p>$/', '', $part);

                    if (empty($part)) {
                        continue;
                    }

                    if (preg_match('/^<span[^>]*>3\.\d+/', $part)) {
                        $headingText = strip_tags($part);
                        continue;
                    }

                    if (preg_match('/^[a-z]\)/i', trim($part))) {
                        $listItems[] = trim(strip_tags($part));
                    } else {
                        $text = trim(strip_tags($part));
                        if (!empty($text) && !preg_match('/^[a-z]\)/i', $text)) {
                            $nonListParts[] = $text;
                        }
                    }
                }

                if (count($listItems) >= 2) {
                    $parent = $p->parentNode;
                    $nextSibling = $p->nextSibling;

                    if (!empty($headingText)) {
                        $headingP = $dom->createElement('p');
                        $headingTextNode = $dom->createTextNode($headingText);
                        $headingP->appendChild($headingTextNode);
                        $parent->insertBefore($headingP, $nextSibling);
                    }

                    if (!empty($nonListParts)) {
                        foreach ($nonListParts as $text) {
                            $textP = $dom->createElement('p');
                            $textNode = $dom->createTextNode($text);
                            $textP->appendChild($textNode);
                            $parent->insertBefore($textP, $nextSibling);
                        }
                    }

                    $ol = $dom->createElement('ol');
                    $ol->setAttribute('type', 'a');

                    foreach ($listItems as $item) {
                        $li = $dom->createElement('li');
                        $liText = $dom->createTextNode($item);
                        $li->appendChild($liText);
                        $ol->appendChild($li);
                    }

                    $parent->insertBefore($ol, $nextSibling);
                    $parent->removeChild($p);
                    $modified = true;
                }
            }
        }

        if ($modified) {
            $body = $xpath->query('//body')->item(0);
            if ($body) {
                $innerHtml = '';
                foreach ($body->childNodes as $child) {
                    $innerHtml .= $dom->saveHTML($child);
                }
                return $innerHtml;
            }
        }

        return $html;
    }

    /**
     * 将 HTML 中的所有不间断空格（&nbsp;, &#160;, \xC2\xA0）替换为普通空格。
     * 防止 WPS/Word 在显示段落标记时渲染为小圆圈（°）。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function replaceNonBreakingSpaces(string $html): string
    {
        // 替换 HTML 实体
        $html = str_replace(['&nbsp;', '&#160;', '&#xA0;'], ' ', $html);
        
        // 替换原始 Unicode 不间断空格字符 (U+00A0)
        $html = str_replace("\xC2\xA0", ' ', $html);
        
        // 可选：替换其他常见空白变体（如窄空格等），但通常不需要
        // $html = str_replace("\xE2\x80\xAF", ' ', $html); // 窄空格
        
        return $html;
    }
    
    /**
     * 打开 docx 文件，删除所有 <w:br/> 节点，保存修改。
     */
    private function removeSoftBreaksFromDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot remove soft breaks from docx');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for soft break removal', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $breaks = $xpath->query('//w:br');
        $removedCount = 0;
        foreach ($breaks as $br) {
            $br->parentNode->removeChild($br);
            $removedCount++;
        }

        if ($removedCount > 0) {
            $newXml = $dom->saveXML();
            $zip->addFromString('word/document.xml', $newXml);
        }

        $zip->close();

        // Log::info('Removed soft breaks from docx', [
        //     'count' => $removedCount,
        //     'path' => $docxPath
        // ]);
    }

    /**
     * 为 docx 文件中所有表格添加边框。
     * 通过修改 word/document.xml 中的 <w:tblPr> 和 <w:tcPr> 元素实现。
     */
    private function addTableBordersToDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot add table borders');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for adding table borders', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length === 0) {
            $zip->close();
            return;
        }

        foreach ($tables as $table) {
            $this->applyBorderToTable($dom, $xpath, $table);
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('Added table borders to docx', ['tables_count' => $tables->length, 'path' => $docxPath]);
    }

    /**
     * 确保Word文档中的表格不会跨页分割导致内容隐藏。
     * 当表格过长跨页时，强制整个表格从新页面开始展示。
     * 方法：
     * 1. 为表格属性添加 <w:keepLines/> 和 <w:keepNext/> 防止表格跨页分割
     * 2. 为每个表格行添加 <w:cantSplit/> 属性防止行跨页分割
     * 3. 分页策略：
     *    - 表格内容量超过阈值（基于字符数和单元格段落数）：强制分页
     *    - 表格行数 > 8 且内容较少：强制分页
     *    - 根据前面内容判断（前面有另一个表格/多段落/图片）：分页
     *    - 其他情况（只有标题/少数段落）：不分页，让小表格与前文共处一页
     * 说明：不再仅依赖行数判断，因为单元格内文字可能很多行
     */
    private function preventTableBreakAcrossPages(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot prevent table breaks');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for preventing table breaks', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length === 0) {
            $zip->close();
            return;
        }

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $modifiedCount = 0;
        $rowCantSplitCount = 0;
        $tableKeepLinesCount = 0;
        $tableKeepNextCount = 0;
        $longTableCount = 0;
        $LONG_TABLE_ROW_THRESHOLD = 8;

        foreach ($tables as $tableIndex => $table) {
            $tblPr = $xpath->query('w:tblPr', $table)->item(0);
            if (!$tblPr) {
                $tblPr = $dom->createElementNS($wNs, 'w:tblPr');
                $table->insertBefore($tblPr, $table->firstChild);
            }

            $keepLines = $xpath->query('w:keepLines', $tblPr)->item(0);
            if (!$keepLines) {
                $keepLines = $dom->createElementNS($wNs, 'w:keepLines');
                $tblPr->appendChild($keepLines);
                $tableKeepLinesCount++;
            }

            $keepNext = $xpath->query('w:keepNext', $tblPr)->item(0);
            if (!$keepNext) {
                $keepNext = $dom->createElementNS($wNs, 'w:keepNext');
                $tblPr->appendChild($keepNext);
                $tableKeepNextCount++;
            }

            $tableRows = $xpath->query('w:tr', $table);
            $rowCount = $tableRows->length;
            foreach ($tableRows as $row) {
                $trPr = $xpath->query('w:trPr', $row)->item(0);
                if (!$trPr) {
                    $trPr = $dom->createElementNS($wNs, 'w:trPr');
                    $row->insertBefore($trPr, $row->firstChild);
                }

                $cantSplit = $xpath->query('w:cantSplit', $trPr)->item(0);
                if (!$cantSplit) {
                    $cantSplit = $dom->createElementNS($wNs, 'w:cantSplit');
                    $trPr->appendChild($cantSplit);
                    $rowCantSplitCount++;
                }
            }

            $needsPageBreak = $this->isLongTable($table, $xpath);
            if (!$needsPageBreak) {
                $needsPageBreak = $this->hasSubstantialPrecedingContent($table, $xpath);
            }

            if ($needsPageBreak) {
                $breakParagraph = $dom->createElementNS($wNs, 'w:p');
                $breakPPr = $dom->createElementNS($wNs, 'w:pPr');
                $breakPageBreak = $dom->createElementNS($wNs, 'w:pageBreakBefore');
                $breakPPr->appendChild($breakPageBreak);
                $breakParagraph->appendChild($breakPPr);

                if ($table->previousSibling) {
                    $table->parentNode->insertBefore($breakParagraph, $table);
                } else {
                    $body = $xpath->query('//w:body')->item(0);
                    if ($body) {
                        $body->insertBefore($breakParagraph, $table);
                    }
                }
                $modifiedCount++;
                $longTableCount++;
            }
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('Prevented table breaks across pages', [
            'tables_count' => $tables->length,
            'pagebreaks_added' => $modifiedCount,
            'long_tables' => $longTableCount,
            'rows_cant_split' => $rowCantSplitCount,
            'tbl_keep_lines' => $tableKeepLinesCount,
            'tbl_keep_next' => $tableKeepNextCount,
            'path' => $docxPath
        ]);
    }

    /**
     * 检查表格前是否有较长的内容段落。
     * 如果前面只有标题或少量段落，不算"较长内容"；
     * 如果前面有多个段落、图片或另一个表格，则认为是"较长内容"。
     * 图片会占用较多页面空间，因此有图片时会降低段落数量阈值。
     */
    private function hasSubstantialPrecedingContent(\DOMElement $table, \DOMXPath $xpath): bool
    {
        $precedingSiblings = $xpath->query('preceding-sibling::*', $table);

        if ($precedingSiblings->length === 0) {
            return false;
        }

        $paragraphCount = 0;
        $tableCount = 0;
        $totalTextLength = 0;
        $hasImages = false;

        foreach ($precedingSiblings as $sibling) {
            if ($sibling instanceof \DOMElement) {
                if (strtolower($sibling->tagName) === 'p') {
                    $paragraphCount++;
                    $totalTextLength += strlen(trim($sibling->textContent));

                    if ($this->paragraphContainsImage($sibling, $xpath)) {
                        $hasImages = true;
                    }
                } elseif (strtolower($sibling->tagName) === 'tbl') {
                    $tableCount++;
                }
            }
        }

        if ($tableCount > 0) {
            return true;
        }

        if ($hasImages && $paragraphCount >= 2) {
            return true;
        }

        if ($paragraphCount >= 3 && $totalTextLength > 100) {
            return true;
        }

        return false;
    }

    /**
     * 检查段落内是否包含图片元素。
     * Word XML 中图片通常表示为 <w:drawing> 或 <w:pict>。
     */
    private function paragraphContainsImage(\DOMElement $paragraph, \DOMXPath $xpath): bool
    {
        $drawings = $xpath->query('.//w:drawing', $paragraph);
        if ($drawings->length > 0) {
            return true;
        }

        $picts = $xpath->query('.//w:pict', $paragraph);
        if ($picts->length > 0) {
            return true;
        }

        return false;
    }

    /**
     * 设置Word文档中所有表格居中对齐。
     * 通过修改 <w:tblPr><w:jc> 为 center 来实现。
     */
    private function centerTablesInDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot center tables');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for centering tables', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length === 0) {
            $zip->close();
            return;
        }

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        foreach ($tables as $table) {
            $tblPr = $xpath->query('w:tblPr', $table)->item(0);
            if (!$tblPr) {
                $tblPr = $dom->createElementNS($wNs, 'w:tblPr');
                $table->insertBefore($tblPr, $table->firstChild);
            }

            $jc = $xpath->query('w:jc', $tblPr)->item(0);
            if (!$jc) {
                $jc = $dom->createElementNS($wNs, 'w:jc');
                $tblPr->appendChild($jc);
            }
            $jc->setAttribute('w:val', 'center');
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('Centered tables in docx', ['tables_count' => $tables->length, 'path' => $docxPath]);
    }

    /**
     * 将列数超过10列的表格设置为横向（landscape）布局。
     * 原理：OOXML中sectPr定义的是该元素之前内容的section属性。
     * 因此在表格前插入竖置sectPr确保前面的段落保持竖置，
     * 在表格后插入横置sectPr使表格以横向展示，
     * 最后再插入竖置sectPr使后续内容恢复竖置。
     * A4纸横向尺寸：宽度29.7cm (16838 twips)，高度21cm (11906 twips)
     */
    private function setWideTablesToLandscape(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot set wide tables to landscape');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for setting wide tables to landscape', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length === 0) {
            $zip->close();
            return;
        }

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $wideTableCount = 0;
        $COLUMN_THRESHOLD = 10;

        $tablesToProcess = [];

        foreach ($tables as $index => $table) {
            $columnCount = $this->getTableColumnCount($table, $xpath);
            if ($columnCount > $COLUMN_THRESHOLD) {
                $tablesToProcess[] = [
                    'table' => $table,
                    'columns' => $columnCount,
                    'index' => $index
                ];
            }
        }

        if (empty($tablesToProcess)) {
            $zip->close();
            return;
        }

        foreach ($tablesToProcess as $tableInfo) {
            $table = $tableInfo['table'];
            $columnCount = $tableInfo['columns'];
            $wideTableCount++;

            $pLandscape = $dom->createElementNS($wNs, 'w:p');
            $pPrLandscape = $dom->createElementNS($wNs, 'w:pPr');
            $sectPrLandscape = $dom->createElementNS($wNs, 'w:sectPr');
            $sectPrLandscape->setAttribute('w:type', 'continuous');

            $pgSzLandscape = $dom->createElementNS($wNs, 'w:pgSz');
            $pgSzLandscape->setAttribute('w:w', '16838');
            $pgSzLandscape->setAttribute('w:h', '11906');
            $pgSzLandscape->setAttribute('w:orient', 'landscape');
            $sectPrLandscape->appendChild($pgSzLandscape);

            $pgMarLandscape = $dom->createElementNS($wNs, 'w:pgMar');
            $pgMarLandscape->setAttribute('w:top', '1440');
            $pgMarLandscape->setAttribute('w:right', '1440');
            $pgMarLandscape->setAttribute('w:bottom', '1440');
            $pgMarLandscape->setAttribute('w:left', '1440');
            $pgMarLandscape->setAttribute('w:header', '708');
            $pgMarLandscape->setAttribute('w:footer', '708');
            $pgMarLandscape->setAttribute('w:gutter', '0');
            $sectPrLandscape->appendChild($pgMarLandscape);

            $colsLandscape = $dom->createElementNS($wNs, 'w:cols');
            $colsLandscape->setAttribute('w:space', '720');
            $sectPrLandscape->appendChild($colsLandscape);

            $pPrLandscape->appendChild($sectPrLandscape);
            $pLandscape->appendChild($pPrLandscape);
            $rLandscape = $dom->createElementNS($wNs, 'w:r');
            $tLandscape = $dom->createElementNS($wNs, 'w:t');
            $tLandscape->appendChild($dom->createTextNode(' '));
            $rLandscape->appendChild($tLandscape);
            $pLandscape->appendChild($rLandscape);

            $parent = $table->parentNode;

            $prevParagraph = $this->findPreviousParagraph($table);
            if ($prevParagraph) {
                $this->addPortraitSectPrToParagraph($prevParagraph, $dom, $wNs);
            } else {
                $pPortraitBefore = $this->buildPortraitSectPrParagraph($dom, $wNs);
                $parent->insertBefore($pPortraitBefore, $table);
            }

            $nextSibling = $table->nextSibling;
            $parent->insertBefore($pLandscape, $nextSibling);

            $this->adjustTableWidthForLandscape($table, $xpath);

            Log::info('Set wide table to landscape', [
                'columns' => $columnCount,
                'threshold' => $COLUMN_THRESHOLD
            ]);
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('Set wide tables to landscape in docx', [
            'wide_tables_count' => $wideTableCount,
            'path' => $docxPath
        ]);
    }

    /**
     * 找到表格之前的最后一个 w:p 段落节点。
     */
    private function findPreviousParagraph(\DOMElement $table): ?\DOMElement
    {
        $prev = $table->previousSibling;
        while ($prev) {
            if ($prev->nodeType === XML_ELEMENT_NODE && $prev->nodeName === 'w:p') {
                $prevPPr = $prev->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'pPr');
                if ($prevPPr->length > 0) {
                    $sectPrs = $prevPPr->item(0)->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'sectPr');
                    if ($sectPrs->length === 0) {
                        return $prev;
                    }
                } else {
                    return $prev;
                }
            }
            $prev = $prev->previousSibling;
        }
        return null;
    }

    /**
     * 向已有段落添加竖置 sectPr。
     */
    private function addPortraitSectPrToParagraph(\DOMElement $paragraph, \DOMDocument $dom, string $wNs): void
    {
        $pPr = $paragraph->getElementsByTagNameNS($wNs, 'pPr')->item(0);
        if (!$pPr) {
            $pPr = $dom->createElementNS($wNs, 'w:pPr');
            $paragraph->insertBefore($pPr, $paragraph->firstChild);
        }

        $sectPr = $dom->createElementNS($wNs, 'w:sectPr');
        $sectPr->setAttribute('w:type', 'continuous');

        $pgSz = $dom->createElementNS($wNs, 'w:pgSz');
        $pgSz->setAttribute('w:w', '11906');
        $pgSz->setAttribute('w:h', '16838');
        $pgSz->setAttribute('w:orient', 'portrait');
        $sectPr->appendChild($pgSz);

        $pgMar = $dom->createElementNS($wNs, 'w:pgMar');
        $pgMar->setAttribute('w:top', '1440');
        $pgMar->setAttribute('w:right', '1440');
        $pgMar->setAttribute('w:bottom', '1440');
        $pgMar->setAttribute('w:left', '1440');
        $pgMar->setAttribute('w:header', '708');
        $pgMar->setAttribute('w:footer', '708');
        $pgMar->setAttribute('w:gutter', '0');
        $sectPr->appendChild($pgMar);

        $cols = $dom->createElementNS($wNs, 'w:cols');
        $cols->setAttribute('w:space', '720');
        $sectPr->appendChild($cols);

        $pPr->appendChild($sectPr);
    }

    /**
     * 构建一个包含竖置 sectPr 的最小段落。
     */
    private function buildPortraitSectPrParagraph(\DOMDocument $dom, string $wNs): \DOMElement
    {
        $p = $dom->createElementNS($wNs, 'w:p');
        $pPr = $dom->createElementNS($wNs, 'w:pPr');
        $sectPr = $dom->createElementNS($wNs, 'w:sectPr');
        $sectPr->setAttribute('w:type', 'continuous');

        $pgSz = $dom->createElementNS($wNs, 'w:pgSz');
        $pgSz->setAttribute('w:w', '11906');
        $pgSz->setAttribute('w:h', '16838');
        $pgSz->setAttribute('w:orient', 'portrait');
        $sectPr->appendChild($pgSz);

        $pgMar = $dom->createElementNS($wNs, 'w:pgMar');
        $pgMar->setAttribute('w:top', '1440');
        $pgMar->setAttribute('w:right', '1440');
        $pgMar->setAttribute('w:bottom', '1440');
        $pgMar->setAttribute('w:left', '1440');
        $pgMar->setAttribute('w:header', '708');
        $pgMar->setAttribute('w:footer', '708');
        $pgMar->setAttribute('w:gutter', '0');
        $sectPr->appendChild($pgMar);

        $cols = $dom->createElementNS($wNs, 'w:cols');
        $cols->setAttribute('w:space', '720');
        $sectPr->appendChild($cols);

        $pPr->appendChild($sectPr);
        $p->appendChild($pPr);
        $r = $dom->createElementNS($wNs, 'w:r');
        $t = $dom->createElementNS($wNs, 'w:t');
        $t->appendChild($dom->createTextNode(' '));
        $r->appendChild($t);
        $p->appendChild($r);

        return $p;
    }

    /**
     * 获取表格的列数（通过检查第一行的最大列数）
     */
    private function getTableColumnCount(\DOMElement $table, \DOMXPath $xpath): int
    {
        $rows = $xpath->query('w:tr', $table);
        if ($rows->length === 0) {
            return 0;
        }

        $maxCols = 0;
        foreach ($rows as $row) {
            $colCount = 0;
            $cells = $xpath->query('w:tc', $row);
            foreach ($cells as $cell) {
                $colspan = $xpath->query('w:tcPr/w:gridSpan', $cell)->item(0);
                if ($colspan) {
                    $colCount += intval($colspan->getAttribute('w:val')) ?: 1;
                } else {
                    $colCount += 1;
                }
            }
            $maxCols = max($maxCols, $colCount);
            if ($maxCols > 10) {
                break;
            }
        }

        return $maxCols;
    }

    /**
     * 调整横置表格的宽度，使其适配A4横向页面。
     * A4横向页面可用宽度约为13958 twips (29.7cm - 左右边距各2.54cm)
     * 根据每列的内容长度按比例分配列宽，中文字符按2倍宽度计算。
     */
    private function adjustTableWidthForLandscape(\DOMElement $table, \DOMXPath $xpath): void
    {
        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $columnCount = $this->getTableColumnCount($table, $xpath);

        $usablePageWidth = 13958;
        $minCellWidth = 400;
        $maxCellWidth = 6000;

        $colContentWidths = $this->calculateColumnContentWidths($table, $xpath, $columnCount);

        $totalContentWidth = array_sum($colContentWidths);
        if ($totalContentWidth <= 0) {
            $totalContentWidth = $columnCount;
            $colContentWidths = array_fill(0, $columnCount, 1);
        }

        $colWidths = [];
        foreach ($colContentWidths as $i => $cw) {
            $proportional = intval(($cw / $totalContentWidth) * $usablePageWidth);
            $colWidths[$i] = max($minCellWidth, min($maxCellWidth, $proportional));
        }

        $totalAssigned = array_sum($colWidths);
        if ($totalAssigned > 0 && $totalAssigned !== $usablePageWidth) {
            $scale = $usablePageWidth / $totalAssigned;
            foreach ($colWidths as $i => $w) {
                $colWidths[$i] = max($minCellWidth, intval($w * $scale));
            }
        }

        $tblPr = $xpath->query('w:tblPr', $table)->item(0);
        if (!$tblPr) {
            $tblPr = $table->ownerDocument->createElementNS($wNs, 'w:tblPr');
            $table->insertBefore($tblPr, $table->firstChild);
        }

        $tblW = $xpath->query('w:tblW', $tblPr)->item(0);
        if (!$tblW) {
            $tblW = $table->ownerDocument->createElementNS($wNs, 'w:tblW');
            $tblPr->appendChild($tblW);
        }
        $tblW->setAttribute('w:w', strval($usablePageWidth));
        $tblW->setAttribute('w:type', 'dxa');

        $tblGrid = $xpath->query('w:tblGrid', $table)->item(0);
        if (!$tblGrid) {
            $tblGrid = $table->ownerDocument->createElementNS($wNs, 'w:tblGrid');
            $table->insertBefore($tblGrid, $table->firstChild);
        } else {
            $existingGrids = $xpath->query('w:gridCol', $tblGrid);
            foreach ($existingGrids as $gridCol) {
                $tblGrid->removeChild($gridCol);
            }
        }

        for ($i = 0; $i < $columnCount; $i++) {
            $gridCol = $table->ownerDocument->createElementNS($wNs, 'w:gridCol');
            $gridCol->setAttribute('w:w', strval($colWidths[$i]));
            $tblGrid->appendChild($gridCol);
        }

        $rows = $xpath->query('w:tr', $table);
        foreach ($rows as $row) {
            $cells = $xpath->query('w:tc', $row);
            $colIndex = 0;
            foreach ($cells as $cell) {
                $colspan = 1;
                $tcPr = $xpath->query('w:tcPr', $cell)->item(0);
                if ($tcPr) {
                    $gridSpan = $xpath->query('w:gridSpan', $tcPr)->item(0);
                    if ($gridSpan) {
                        $colspan = intval($gridSpan->getAttribute('w:val')) ?: 1;
                    }
                }

                $combinedWidth = 0;
                for ($j = 0; $j < $colspan && ($colIndex + $j) < $columnCount; $j++) {
                    $combinedWidth += $colWidths[$colIndex + $j];
                }

                $tcW = $xpath->query('w:tcW', $cell)->item(0);
                if (!$tcW) {
                    $tcW = $table->ownerDocument->createElementNS($wNs, 'w:tcW');
                    $cell->insertBefore($tcW, $cell->firstChild);
                }
                $tcW->setAttribute('w:w', strval($combinedWidth));
                $tcW->setAttribute('w:type', 'dxa');

                $colIndex += $colspan;
            }
        }
    }

    /**
     * 计算每列的内容宽度。
     * 扫描所有行，取每列中内容最宽的单元格作为该列的内容宽度。
     * 中文字符按2个单位宽度计算，ASCII字符按1个单位计算。
     */
    private function calculateColumnContentWidths(\DOMElement $table, \DOMXPath $xpath, int $columnCount): array
    {
        $colWidths = array_fill(0, $columnCount, 0);
        $rows = $xpath->query('w:tr', $table);

        foreach ($rows as $row) {
            $cells = $xpath->query('w:tc', $row);
            $colIndex = 0;
            foreach ($cells as $cell) {
                if ($colIndex >= $columnCount) {
                    break;
                }

                $colspan = 1;
                $tcPr = $xpath->query('w:tcPr', $cell)->item(0);
                if ($tcPr) {
                    $gridSpan = $xpath->query('w:gridSpan', $tcPr)->item(0);
                    if ($gridSpan) {
                        $colspan = intval($gridSpan->getAttribute('w:val')) ?: 1;
                    }
                }

                $textContent = '';
                $textNodes = $xpath->query('.//w:t', $cell);
                foreach ($textNodes as $t) {
                    $textContent .= $t->textContent;
                }

                $charWidth = 0;
                $chars = mb_str_split($textContent ?: '');
                foreach ($chars as $ch) {
                    $ord = mb_ord($ch);
                    if ($ord >= 0x4E00 && $ord <= 0x9FFF || $ord >= 0x3000 && $ord <= 0x303F || $ord >= 0xFF00 && $ord <= 0xFFEF) {
                        $charWidth += 2;
                    } else {
                        $charWidth += 1;
                    }
                }

                $charWidth = max(1, $charWidth);

                if ($colspan <= 1) {
                    $colWidths[$colIndex] = max($colWidths[$colIndex], $charWidth);
                } else {
                    $perColWidth = intval($charWidth / $colspan);
                    for ($j = 0; $j < $colspan && ($colIndex + $j) < $columnCount; $j++) {
                        $colWidths[$colIndex + $j] = max($colWidths[$colIndex + $j], $perColWidth);
                    }
                }

                $colIndex += $colspan;
            }
        }

        return $colWidths;
    }

    /**
     * 设置Word文档中所有标题居中对齐。
     * 不依赖pandoc的居中设置，直接为所有标题样式设置居中。
     */
    private function centerHeadingsInDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot center headings');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for centering headings', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $centeredCount = 0;

        $allParagraphs = $xpath->query('//w:p');
        Log::info('centerHeadingsInDocx: found paragraphs', ['count' => $allParagraphs->length]);

        foreach ($allParagraphs as $index => $paragraph) {
            $pPr = $xpath->query('w:pPr', $paragraph)->item(0);
            if (!$pPr) {
                $pPr = $dom->createElementNS($wNs, 'w:pPr');
                $paragraph->insertBefore($pPr, $paragraph->firstChild);
            }

            $pStyle = $xpath->query('w:pStyle', $pPr)->item(0);
            $styleVal = $pStyle ? $pStyle->getAttribute('w:val') : '';

            $textContent = '';
            $textNodes = $xpath->query('.//w:t', $paragraph);
            foreach ($textNodes as $t) {
                $textContent .= $t->textContent;
            }

            $isHeadingStyle = strpos($styleVal, 'Heading') === 0 || $styleVal === 'Title' || $styleVal === 'Subtitle';

            if ($isHeadingStyle) {
                $jc = $xpath->query('w:jc', $pPr)->item(0);
                if (!$jc) {
                    $jc = $dom->createElementNS($wNs, 'w:jc');
                    $pPr->appendChild($jc);
                }
                $jc->setAttribute('w:val', 'center');
                $centeredCount++;
                Log::info('centerHeadingsInDocx: centered heading', [
                    'index' => $index,
                    'style' => $styleVal,
                    'text' => mb_substr($textContent, 0, 50)
                ]);
            }
        }

        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('centerHeadingsInDocx: finished', ['centered_count' => $centeredCount, 'path' => $docxPath]);
    }

    /**
     * 为单个表格应用边框样式。
     * 为表格添加 <w:tblPr><w:tblBorders>，为每个单元格添加 <w:tcPr><w:tcBorders>。
     */
    private function applyBorderToTable(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $table): void
    {
        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        $tblPr = $xpath->query('w:tblPr', $table)->item(0);
        if (!$tblPr) {
            $tblPr = $dom->createElementNS($wNs, 'w:tblPr');
            $table->insertBefore($tblPr, $table->firstChild);
        }

        // 禁用表格跨页时重复表头 - 删除 w:tblHeader 元素
        $tblHeader = $xpath->query('w:tblHeader', $tblPr)->item(0);
        if ($tblHeader) {
            $tblPr->removeChild($tblHeader);
        }

        $tblBorders = $xpath->query('w:tblBorders', $tblPr)->item(0);
        if (!$tblBorders) {
            $tblBorders = $dom->createElementNS($wNs, 'w:tblBorders');
            $tblPr->appendChild($tblBorders);
        }

        $borderType = 'single';
        $borderSize = '8';
        $borderColor = '000000';

        $borderPositions = ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'];
        foreach ($borderPositions as $pos) {
            $border = $xpath->query('w:' . $pos, $tblBorders)->item(0);
            if (!$border) {
                $border = $dom->createElementNS($wNs, 'w:' . $pos);
                $tblBorders->appendChild($border);
            }
            $border->setAttribute('w:val', $borderType);
            $border->setAttribute('w:sz', $borderSize);
            $border->setAttribute('w:space', '0');
            $border->setAttribute('w:color', $borderColor);
        }

        $rows = $xpath->query('.//w:tr', $table);
        foreach ($rows as $row) {
            $cells = $xpath->query('.//w:tc', $row);
            foreach ($cells as $cell) {
                $tcPr = $xpath->query('w:tcPr', $cell)->item(0);
                if (!$tcPr) {
                    $tcPr = $dom->createElementNS($wNs, 'w:tcPr');
                    $cell->insertBefore($tcPr, $cell->firstChild);
                }

                $tcBorders = $xpath->query('w:tcBorders', $tcPr)->item(0);
                if (!$tcBorders) {
                    $tcBorders = $dom->createElementNS($wNs, 'w:tcBorders');
                    $tcPr->appendChild($tcBorders);
                }

                foreach ($borderPositions as $pos) {
                    $border = $xpath->query('w:' . $pos, $tcBorders)->item(0);
                    if (!$border) {
                        $border = $dom->createElementNS($wNs, 'w:' . $pos);
                        $tcBorders->appendChild($border);
                    }
                    $border->setAttribute('w:val', $borderType);
                    $border->setAttribute('w:sz', $borderSize);
                    $border->setAttribute('w:space', '0');
                    $border->setAttribute('w:color', $borderColor);
                }
            }
        }
    }

    /**
     * Convert HTML to DOCX using pandoc, then apply post-processing:
     * - Remove all soft breaks (<w:br/>)
     * - Force first paragraph style (centered, SimSun, 12pt, bold)
     */
    private function convertHtmlToDocx(string $inputHtml, string $outputDocx): void
    {
        // ----- 1. 准备 pandoc 命令 -----
        $command = [
            'pandoc',
            $inputHtml,
            '-o', $outputDocx,
            '--resource-path=' . dirname($inputHtml),
            '--self-contained',
            '--wrap=preserve',
            '--standalone',
        ];

        // 添加自定义模板（如果存在）
        $templatePath = config('app.word_export_template', '/app/medical_device_template.docx');
        if (file_exists($templatePath)) {
            $command[] = '--reference-doc=' . $templatePath;
        } else {
            Log::warning('Word export template not found, using pandoc default', ['path' => $templatePath]);
        }

        // ----- 2. 执行 pandoc 转换 -----
        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        // 清理临时 HTML 文件
        if (file_exists($inputHtml)) {
            @unlink($inputHtml);
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // ----- 3. 后处理：删除所有软回车 -----
        $this->removeSoftBreaksFromDocx($outputDocx);

        // ----- 4. 后处理：设置所有标题居中 -----
        $this->centerHeadingsInDocx($outputDocx);

        // ----- 5. 后处理：强制设置第一个段落样式（居中、宋体、12pt、加粗）-----
        $this->applyFirstParagraphStyleToDocx($outputDocx);

        // ----- 6. 后处理：为所有表格添加边框 -----
        $this->addTableBordersToDocx($outputDocx);

        // ----- 6.1 后处理：防止表格跨页分割 -----
        $this->preventTableBreakAcrossPages($outputDocx);

        // ----- 6.2 后处理：审批表格后添加分页 -----
        $this->addPageBreakAfterApprovalTableInDocx($outputDocx);

        // ----- 7. 后处理：设置所有表格居中 -----
        $this->centerTablesInDocx($outputDocx);

        // ----- 8. 后处理：将列数超过10列的表格设置为横向布局 -----
        $this->setWideTablesToLandscape($outputDocx);

        // ----- 9. 验证输出文件 -----
        if (!file_exists($outputDocx) || filesize($outputDocx) === 0) {
            throw new \Exception('Generated Word document is empty or does not exist');
        }
    }

    /**
     * Send DOCX file download.
     */
    private function sendDocxDownload(string $filePath, $page)
    {
        $filename = $this->generateFilename($page);
        
        return response()->download($filePath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Length' => filesize($filePath),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Generate download filename.
     */
    private function generateFilename($page): string
    {
        // 获取页面标题
        $title = $page->name;
        
        // Windows/Linux文件名中不允许的字符：\/:*?"<>|
        // 将这些字符替换为下划线
        $illegalChars = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
        $safeName = str_replace($illegalChars, '_', $title);
        
        // 如果过滤后为空，使用默认文件名
        if (empty(trim($safeName))) {
            $safeName = 'document_' . $page->id;
        }
        
        // 去除首尾空格和下划线
        $safeName = trim($safeName, " _");
        
        // 限制长度
        if (mb_strlen($safeName, 'UTF-8') > 100) {
            $safeName = mb_substr($safeName, 0, 100, 'UTF-8');
        }
        
        return "{$safeName}.docx";
    }
    
    /**
     * 为 HTML 片段中的第一个非空块级元素应用样式（居中、宋体、12pt、加粗）。
     * 此方法专用于在 removeFirstH1() 之后调用，将紧随其后的首个内容块样式化。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML 片段
     */
    private function styleFirstElement(string $html): string
    {
        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $firstElement = null;
        $skippedCount = 0;
        
        // 定义需要处理的块级元素标签（可根据需要增删）
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'pre', 'blockquote', 'table', 'ul', 'ol'];
        
        foreach ($dom->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (in_array($child->tagName, $blockTags)) {
                // 检查元素是否为空（无文本且非表格）
                $textContent = trim($child->textContent);
                if ($textContent === '' && $child->tagName !== 'table') {
                    $skippedCount++;
                    continue;
                }
                $firstElement = $child;
                break;
            }
            $skippedCount++;
        }

        if ($firstElement) {
            // 目标样式：居中、宋体/SimSun、12pt、加粗
            $targetStyle = 'text-align: center; font-family: SimSun, 宋体; font-size: 12pt; font-weight: bold;';
            
            $existingStyle = $firstElement->getAttribute('style');
            if ($existingStyle) {
                // 移除可能与目标冲突的属性
                $existingStyle = preg_replace('/text-align\s*:[^;]+;?/', '', $existingStyle);
                $existingStyle = preg_replace('/font-family\s*:[^;]+;?/', '', $existingStyle);
                $existingStyle = preg_replace('/font-size\s*:[^;]+;?/', '', $existingStyle);
                $existingStyle = preg_replace('/font-weight\s*:[^;]+;?/', '', $existingStyle);
                $newStyle = trim($existingStyle . ' ' . $targetStyle);
            } else {
                $newStyle = $targetStyle;
            }
            
            $firstElement->setAttribute('style', $newStyle);
            
            // 添加类名 first-element（便于调试）
            $class = $firstElement->getAttribute('class');
            $class = trim(preg_replace('/\bfirst-element\b/', '', $class));
            $class .= ' first-element';
            $firstElement->setAttribute('class', trim($class));
            
            // ----- 关键日志：输出命中的元素信息 -----
            // \Illuminate\Support\Facades\Log::info('styleFirstElement() - 已应用样式到首元素', [
            //     'tag' => $firstElement->tagName,
            //     'id' => $firstElement->getAttribute('id'),
            //     'class' => $firstElement->getAttribute('class'),
            //     'content' => mb_substr(trim($firstElement->textContent), 0, 50),
            //     'skipped_count' => $skippedCount
            // ]);
        } else {
            \Illuminate\Support\Facades\Log::warning('styleFirstElement() - 未找到合适的块级元素', [
                'html_sample' => mb_substr($html, 0, 200)
            ]);
        }

        // 重新拼接 HTML 片段（保持无 <body> 包装）
        $innerHtml = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $innerHtml .= $dom->saveHTML($child);
        }
        
        return $innerHtml;
    }

    /**
     * 将 HTML 片段中的第一个非空块级元素转换为居中的 H1 标题。
     * 此方法用于在 removeFirstH1() 之后调用，将紧随其后的首个内容块作为文档标题展示。
     * 会跳过所有空的块级元素，直到找到有实际内容的元素。
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML 片段
     */
    private function styleFirstElementAsHeading(string $html): string
    {
        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'pre', 'blockquote', 'ul', 'ol'];
        $firstElement = null;
        $skippedEmptyCount = 0;

        $xpath = new \DOMXPath($dom);

        foreach ($blockTags as $tag) {
            $elements = $xpath->query('//' . $tag);
            foreach ($elements as $element) {
                if ($element->parentNode->nodeName === 'body' || $xpath->query('ancestor::body', $element)->length > 0) {
                    $rawText = $element->textContent;
                    $textContent = preg_replace('/\s+/', ' ', $rawText);
                    $textContent = html_entity_decode($textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $textContent = preg_replace('/[\x{00A0}\x{202F}\x{2000}-\x{200A}\x{200B}]/u', '', $textContent);
                    $textContent = trim($textContent);

                    if ($textContent === '') {
                        $skippedEmptyCount++;
                        continue;
                    }

                    $firstElement = $element;
                    break 2;
                }
            }
        }

        if ($firstElement) {
            $newElement = $dom->createElement('h1');
            $newElement->setAttribute('style', 'text-align: center;');

            foreach ($firstElement->attributes as $attr) {
                if ($attr->name !== 'style' && $attr->name !== 'class') {
                    $newElement->setAttribute($attr->name, $attr->value);
                }
            }

            while ($firstElement->firstChild) {
                $newElement->appendChild($firstElement->firstChild);
            }

            $firstElement->parentNode->replaceChild($newElement, $firstElement);

            Log::info('styleFirstElementAsHeading: converted to centered H1', [
                'tag' => $newElement->tagName,
                'content' => mb_substr(trim($newElement->textContent), 0, 50),
                'skipped_empty_count' => $skippedEmptyCount
            ]);
        } else {
            Log::warning('styleFirstElementAsHeading: 未找到有内容的块级元素', [
                'html_sample' => mb_substr($html, 0, 300)
            ]);
        }

        $innerHtml = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $innerHtml .= $dom->saveHTML($child);
        }

        return $innerHtml;
    }


    /**
     * 强制设置 docx 中第一个段落为：居中、宋体、18pt（小二）、加粗。
     * 同时覆盖段落属性和所有运行属性，确保不被 Pandoc 内联样式覆盖。
     */
    private function applyFirstParagraphStyleToDocx(string $docxPath): void
    {
        if (!class_exists('ZipArchive')) {
            Log::warning('ZipArchive not available, cannot apply first paragraph style');
            return;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath, ZipArchive::CREATE) !== true) {
            Log::error('Failed to open docx file for applying first paragraph style', ['path' => $docxPath]);
            return;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            Log::error('word/document.xml not found in docx');
            return;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($documentXml);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // ----- 1. 找到第一个段落 <w:p> -----
        $paragraphs = $xpath->query('//w:p');
        if ($paragraphs->length === 0) {
            $zip->close();
            Log::warning('No paragraph found in docx, cannot apply first paragraph style');
            return;
        }
        $firstP = $paragraphs->item(0);

        // ----- 2. 检查第一个段落是否是标题样式 -----
        $pPr = $xpath->query('w:pPr', $firstP)->item(0);
        $pStyle = $pPr ? $xpath->query('w:pStyle', $pPr)->item(0) : null;
        $styleVal = $pStyle ? $pStyle->getAttribute('w:val') : '';
        $isHeadingStyle = strpos($styleVal, 'Heading') === 0 || $styleVal === 'Title' || $styleVal === 'Subtitle';

        // 如果是标题样式，已由 centerHeadingsInDocx 处理，只设置居中即可
        // 如果不是标题样式，则应用首行样式

        // ----- 3. 确保 <w:pPr> 存在并设置居中对齐 -----
        if (!$pPr) {
            $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
            $firstP->insertBefore($pPr, $firstP->firstChild);
        }

        // 设置居中对齐
        $jc = $xpath->query('w:jc', $pPr)->item(0);
        if (!$jc) {
            $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
            $pPr->appendChild($jc);
        }
        $jc->setAttribute('w:val', 'center');

        // ----- 4. 只有非标题段落才应用字体样式 -----
        if (!$isHeadingStyle) {
            // 段落默认字符属性（用于没有直接格式的文本）
            $pRPr = $xpath->query('w:rPr', $pPr)->item(0);
            if (!$pRPr) {
                $pRPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
                $pPr->appendChild($pRPr);
            }
            // 设置段落默认字体、字号、加粗（18pt = w:sz 36）
            $this->setRunProperties($pRPr, '36', 'SimSun', '宋体', true);

            // 遍历所有 <w:r> 运行，强制设置相同的属性（覆盖 Pandoc 内联样式）
            $runs = $xpath->query('.//w:r', $firstP);
            foreach ($runs as $run) {
                $rPr = $xpath->query('w:rPr', $run)->item(0);
                if (!$rPr) {
                    $rPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rPr');
                    $run->insertBefore($rPr, $run->firstChild);
                }
                $this->setRunProperties($rPr, '36', 'SimSun', '宋体', true);
            }
        }

        // ----- 5. 保存修改后的 XML -----
        $newXml = $dom->saveXML();
        $zip->addFromString('word/document.xml', $newXml);
        $zip->close();

        Log::info('Applied first paragraph style to docx', ['is_heading' => $isHeadingStyle, 'path' => $docxPath]);
    }

    /**
     * 辅助方法：设置 <w:rPr> 的字体、字号、加粗
     */
    private function setRunProperties(\DOMElement $rPr, string $szVal, string $asciiFont, string $eastAsiaFont, bool $bold): void
    {
        $dom = $rPr->ownerDocument;
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // 设置字体
        $rFonts = $xpath->query('w:rFonts', $rPr)->item(0);
        if (!$rFonts) {
            $rFonts = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:rFonts');
            $rPr->appendChild($rFonts);
        }
        $rFonts->setAttribute('w:ascii', $asciiFont);
        $rFonts->setAttribute('w:hAnsi', $asciiFont);
        $rFonts->setAttribute('w:eastAsia', $eastAsiaFont);
        $rFonts->setAttribute('w:cs', $asciiFont);

        // 设置字号（18pt = 36）
        $sz = $xpath->query('w:sz', $rPr)->item(0);
        if (!$sz) {
            $sz = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:sz');
            $rPr->appendChild($sz);
        }
        $sz->setAttribute('w:val', $szVal);

        $szCs = $xpath->query('w:szCs', $rPr)->item(0);
        if (!$szCs) {
            $szCs = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:szCs');
            $rPr->appendChild($szCs);
        }
        $szCs->setAttribute('w:val', $szVal);

        // 设置加粗
        $b = $xpath->query('w:b', $rPr)->item(0);
        if (!$b) {
            $b = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:b');
            $rPr->appendChild($b);
        }
        $b->setAttribute('w:val', $bold ? 'true' : 'false');

        $bCs = $xpath->query('w:bCs', $rPr)->item(0);
        if (!$bCs) {
            $bCs = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:bCs');
            $rPr->appendChild($bCs);
        }
        $bCs->setAttribute('w:val', $bold ? 'true' : 'false');
    }


    /**
     * 为所有段落(p标签)添加首行缩进两个字符。
     * - 如果段落已有 text-indent 样式，插入全角空格占位符并移除原样式
     * - 如果段落没有首行缩进，插入两个全角空格实现缩进
     * - 标题标签(h1-h6)不进行缩进处理
     *
     * @param string $html HTML 片段
     * @return string 处理后的 HTML
     */
    private function insertIndentPlaceholders(string $html): string
    {
        if (!extension_loaded('dom')) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $headingTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $paragraphTags = ['p'];

        $paragraphs = $xpath->query('//p');
        $modifiedCount = 0;

        foreach ($paragraphs as $node) {
            $style = $node->getAttribute('style');
            $hasTextIndent = preg_match('/text-indent:\s*([^;]+);/', $style, $matches);

            if ($hasTextIndent) {
                $indentValue = trim($matches[1]);
                preg_match('/([\d.]+)/', $indentValue, $numMatches);
                $indentNum = floatval($numMatches[0] ?? 0);

                $shouldIndent = false;
                if (strpos($indentValue, 'pt') !== false && $indentNum > 5) {
                    $shouldIndent = true;
                }
                if (strpos($indentValue, 'em') !== false && $indentNum > 0.5) {
                    $shouldIndent = true;
                }

                if ($shouldIndent) {
                    $fullwidthSpace = '　';
                    $spaceNode = $dom->createTextNode($fullwidthSpace . $fullwidthSpace);

                    if ($node->hasChildNodes()) {
                        $node->insertBefore($spaceNode, $node->firstChild);
                    } else {
                        $node->appendChild($spaceNode);
                    }

                    $style = preg_replace('/text-indent:\s*[^;]+;?/', '', $style);
                    if (trim($style) === '') {
                        $node->removeAttribute('style');
                    } else {
                        $node->setAttribute('style', $style);
                    }

                    $modifiedCount++;
                }
            } else {
                $textContent = trim($node->textContent);
                if (!empty($textContent)) {
                    $fullwidthSpace = '　';
                    $spaceNode = $dom->createTextNode($fullwidthSpace . $fullwidthSpace);

                    if ($node->hasChildNodes()) {
                        $node->insertBefore($spaceNode, $node->firstChild);
                    } else {
                        $node->appendChild($spaceNode);
                    }

                    $modifiedCount++;
                }
            }
        }

        $innerHtml = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $innerHtml .= $dom->saveHTML($child);
        }

        Log::info('insertIndentPlaceholders: processed paragraphs', [
            'modified_count' => $modifiedCount
        ]);

        return $innerHtml;
    }

    /**
     * 样式
     */
    private function getExportStyles(): string
    {
        $baseStyles = <<<CSS
    body {
        font-family: 'Microsoft YaHei', 'SimSun', serif;
        font-size: 12pt;
        line-height: 1.5;
        color: #000000;
        margin: 2cm;
    }

    /* 页面内容区域样式 */
    .page-content h1 {
        font-size: 20pt;
        color: #000080;
        text-align: center;
        margin-bottom: 30px;
        border-bottom: 2px solid #000080;
        padding-bottom: 10px;
    }
    .page-content h2 {
        font-size: 16pt;
        color: #000080;
        margin-top: 24px;
        margin-bottom: 12px;
    }
    .page-content h3 {
        font-size: 14pt;
        color: #000080;
        margin-top: 18px;
        margin-bottom: 9px;
    }
    .page-content img {
        max-width: 100%;
        height: auto;
        display: block;
        margin: 15px auto;
    }
    .page-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 15px 0;
        border: 1px solid #000000;
    }
    .page-content th,
    .page-content td {
        border: 1px solid #000000;
        padding: 8px 12px;
    }
    .page-content th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .page-content pre,
    .page-content code {
        background-color: #f8f8f8;
        font-family: 'Courier New', monospace;
    }
    .page-content pre {
        padding: 12px;
        overflow-x: auto;
        border-left: 3px solid #000080;
        margin: 15px 0;
    }
    .page-content blockquote {
        border-left: 4px solid #000080;
        padding-left: 20px;
        margin-left: 0;
        color: #444444;
        font-style: italic;
    }
    .page-content ul,
    .page-content ol {
        margin: 10px 0 10px 30px;
    }
    .page-content li {
        margin-bottom: 5px;
    }

    .page-content table,
    .page-content td,
    .page-content th {
        border-style: solid !important;
    }

    /* 表格边框统一 */
    table, td, th {
        border-collapse: collapse;
        border: 1px solid black;
    }
    td, th {
        padding: 4px 8px;
    }

    /* 预格式文本保留空白 */
    pre {
        white-space: pre-wrap;
    }

    /* 表格居中处理 - 支持 align="center" 属性 */
    div[align="center"] table {
        margin-left: auto;
        margin-right: auto;
    }

    /* 单元格文字居中处理 - 支持 align="center" 属性 */
    td[align="center"], th[align="center"] {
        text-align: center !important;
    }

    /* 表格整体居中 */
    table[align="center"] {
        margin-left: auto !important;
        margin-right: auto !important;
    }
CSS;

        // ----- 终极强制表格边框实线（类优先级 + !important）-----
        $ultimates = <<<CSS

    /* 终极强制：所有带 export-solid-border 的表格及其单元格边框为实线 */
    .export-solid-border,
    .export-solid-border td,
    .export-solid-border th,
    .export-solid-border tr,
    .export-solid-border tbody,
    .export-solid-border thead,
    .export-solid-border tfoot {
        border-style: solid !important;
        border-collapse: collapse !important;
    }

    .export-solid-border {
        border: 1px solid #000000 !important;
    }
    .export-solid-border td,
    .export-solid-border th {
        border: 1px solid #000000 !important;
        padding: 4px 8px !important;
    }
    CSS;

        // 合并两个样式块并返回
        return $baseStyles . "\n\n" . $ultimates;
    }

    
    /**
     * Get page from an ajax request.
     *
     * @throws NotFoundException
     */
    public function getPageAjax(int $pageId)
    {
        $page = $this->queries->findVisibleByIdOrFail($pageId);
        $page->setHidden(array_diff($page->getHidden(), ['html', 'markdown']));
        $page->makeHidden(['book']);

        return response()->json($page);
    }

    /**
     * Show the form for editing the specified page.
     *
     * @throws NotFoundException
     */
    public function edit(Request $request, string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageUpdate, $page, $page->getUrl());

        $editorData = new PageEditorData($page, $this->entityQueries, $request->query('editor', ''));
        if ($editorData->getWarnings()) {
            $this->showWarningNotification(implode("\n", $editorData->getWarnings()));
        }

        $this->setPageTitle(trans('entities.pages_editing_named', ['pageName' => $page->getShortName()]));

        return view('pages.edit', $editorData->getViewData());
    }

    /**
     * Update the specified page in storage.
     *
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function update(Request $request, string $bookSlug, string $pageSlug)
    {
        $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
        ]);
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageUpdate, $page);

        $this->pageRepo->update($page, $request->all());

        return redirect($page->getUrl());
    }

    /**
     * Save a draft update as a revision.
     *
     * @throws NotFoundException
     */
    public function saveDraft(Request $request, int $pageId)
    {
        $page = $this->queries->findVisibleByIdOrFail($pageId);
        $this->checkOwnablePermission(Permission::PageUpdate, $page);

        if (!$this->isSignedIn()) {
            return $this->jsonError(trans('errors.guests_cannot_save_drafts'), 500);
        }

        $draft = $this->pageRepo->updatePageDraft($page, $request->only(['name', 'html', 'markdown']));
        $warnings = (new PageEditActivity($page))->getWarningMessagesForDraft($draft);

        return response()->json([
            'status'    => 'success',
            'message'   => trans('entities.pages_edit_draft_save_at'),
            'warning'   => implode("\n", $warnings),
            'timestamp' => $draft->updated_at->timestamp,
        ]);
    }

    /**
     * Redirect from a special link url which uses the page id rather than the name.
     *
     * @throws NotFoundException
     */
    public function redirectFromLink(int $pageId)
    {
        $page = $this->queries->findVisibleByIdOrFail($pageId);

        return redirect($page->getUrl());
    }

    /**
     * Show the deletion page for the specified page.
     *
     * @throws NotFoundException
     */
    public function showDelete(string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageDelete, $page);
        $this->setPageTitle(trans('entities.pages_delete_named', ['pageName' => $page->getShortName()]));
        $usedAsTemplate =
            $this->entityQueries->books->start()->where('default_template_id', '=', $page->id)->count() > 0 ||
            $this->entityQueries->chapters->start()->where('default_template_id', '=', $page->id)->count() > 0;

        return view('pages.delete', [
            'book'    => $page->book,
            'page'    => $page,
            'current' => $page,
            'usedAsTemplate' => $usedAsTemplate,
        ]);
    }

    /**
     * Show the deletion page for the specified page.
     *
     * @throws NotFoundException
     */
    public function showDeleteDraft(string $bookSlug, int $pageId)
    {
        $page = $this->queries->findVisibleByIdOrFail($pageId);
        $this->checkOwnablePermission(Permission::PageUpdate, $page);
        $this->setPageTitle(trans('entities.pages_delete_draft_named', ['pageName' => $page->getShortName()]));
        $usedAsTemplate =
            $this->entityQueries->books->start()->where('default_template_id', '=', $page->id)->count() > 0 ||
            $this->entityQueries->chapters->start()->where('default_template_id', '=', $page->id)->count() > 0;

        return view('pages.delete', [
            'book'    => $page->book,
            'page'    => $page,
            'current' => $page,
            'usedAsTemplate' => $usedAsTemplate,
        ]);
    }

    /**
     * Remove the specified page from storage.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function destroy(string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageDelete, $page);
        $parent = $page->getParent();

        $this->pageRepo->destroy($page);

        return redirect($parent->getUrl());
    }

    /**
     * Remove the specified draft page from storage.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function destroyDraft(string $bookSlug, int $pageId)
    {
        $page = $this->queries->findVisibleByIdOrFail($pageId);
        $book = $page->book;
        $chapter = $page->chapter;
        $this->checkOwnablePermission(Permission::PageUpdate, $page);

        $this->pageRepo->destroy($page);

        $this->showSuccessNotification(trans('entities.pages_delete_draft_success'));

        if ($chapter && userCan(Permission::ChapterView, $chapter)) {
            return redirect($chapter->getUrl());
        }

        return redirect($book->getUrl());
    }

    /**
     * Show a listing of recently created pages.
     */
    public function showRecentlyUpdated()
    {
        $visibleBelongsScope = function (BelongsTo $query) {
            $query->scopes('visible');
        };

        $pages = $this->queries->visibleForList()
            ->addSelect('updated_by')
            ->with(['updatedBy', 'book' => $visibleBelongsScope, 'chapter' => $visibleBelongsScope])
            ->orderBy('updated_at', 'desc')
            ->paginate(20)
            ->setPath(url('/pages/recently-updated'));

        $this->setPageTitle(trans('entities.recently_updated_pages'));

        return view('common.detailed-listing-paginated', [
            'title'         => trans('entities.recently_updated_pages'),
            'entities'      => $pages,
            'showUpdatedBy' => true,
            'showPath'      => true,
        ]);
    }

    /**
     * Show the view to choose a new parent to move a page into.
     *
     * @throws NotFoundException
     */
    public function showMove(string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageUpdate, $page);
        $this->checkOwnablePermission(Permission::PageDelete, $page);

        return view('pages.move', [
            'book' => $page->book,
            'page' => $page,
        ]);
    }

    /**
     * Does the action of moving the location of a page.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function move(Request $request, string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageUpdate, $page);
        $this->checkOwnablePermission(Permission::PageDelete, $page);

        $entitySelection = $request->get('entity_selection', null);
        if ($entitySelection === null || $entitySelection === '') {
            return redirect($page->getUrl());
        }

        try {
            $this->pageRepo->move($page, $entitySelection);
        } catch (PermissionsException $exception) {
            $this->showPermissionError();
        } catch (Exception $exception) {
            $this->showErrorNotification(trans('errors.selected_book_chapter_not_found'));

            return redirect($page->getUrl('/move'));
        }

        return redirect($page->getUrl());
    }

    /**
     * Show the view to copy a page.
     *
     * @throws NotFoundException
     */
    public function showCopy(string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        session()->flashInput(['name' => $page->name]);

        return view('pages.copy', [
            'book' => $page->book,
            'page' => $page,
        ]);
    }

    /**
     * Create a copy of a page within the requested target destination.
     *
     * @throws NotFoundException
     * @throws Throwable
     */
    public function copy(Request $request, Cloner $cloner, string $bookSlug, string $pageSlug)
    {
        $page = $this->queries->findVisibleBySlugsOrFail($bookSlug, $pageSlug);
        $this->checkOwnablePermission(Permission::PageView, $page);

        $entitySelection = $request->get('entity_selection') ?: null;
        $newParent = $entitySelection ? $this->entityQueries->findVisibleByStringIdentifier($entitySelection) : $page->getParent();

        if (!$newParent instanceof Book && !$newParent instanceof Chapter) {
            $this->showErrorNotification(trans('errors.selected_book_chapter_not_found'));

            return redirect($page->getUrl('/copy'));
        }

        $this->checkOwnablePermission(Permission::PageCreate, $newParent);

        $newName = $request->get('name') ?: $page->name;
        $pageCopy = $cloner->clonePage($page, $newParent, $newName);
        $this->showSuccessNotification(trans('entities.pages_copy_success'));

        return redirect($pageCopy->getUrl());
    }
}
