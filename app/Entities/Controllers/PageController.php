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
                $tempMarkdown = $this->createTempMarkdownFile($page, $tempDir);
                $this->convertMarkdownToDocx($tempMarkdown, $tempDocx);
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
     * Create temporary Markdown file for export.
     */
    private function createTempMarkdownFile($page, string $tempDir): string
    {
        $markdownContent = $this->buildExportMarkdown($page);

        $tempFile = tempnam($tempDir, 'bookstack_md_') . '.md';
        file_put_contents($tempFile, $markdownContent);

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

        $markdown = $this->convertHtmlTablesToMarkdown($markdown);

        if (strpos($markdown, '<table') !== false) {
            Log::warning('HTML table still present after conversion attempt');
        }

        $markdown = $this->removeFirstH1FromMarkdown($markdown);
        $markdown = $this->normalizeMarkdownContent($markdown);
        $markdown = $this->replaceNonBreakingSpacesInMarkdown($markdown);
        $markdown = $this->convertCheckboxesInMarkdown($markdown);

        return "# " . $page->name . "\n\n" . $markdown;
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
            
            // 查找所有行（包括thead和tbody中的行）
            $allRows = [];
            $thead = $table->getElementsByTagName('thead')->item(0);
            $tbody = $table->getElementsByTagName('tbody')->item(0);
            
            if ($thead) {
                foreach ($thead->getElementsByTagName('tr') as $tr) {
                    $allRows[] = $tr;
                }
            }
            if ($tbody) {
                foreach ($tbody->getElementsByTagName('tr') as $tr) {
                    $allRows[] = $tr;
                }
            }
            
            // 如果没有找到thead/tbody，直接查找tr
            if (empty($allRows)) {
                foreach ($table->getElementsByTagName('tr') as $tr) {
                    $allRows[] = $tr;
                }
            }

            if (empty($allRows)) {
                return $htmlTable;
            }

            // 计算最大列数
            $maxCols = 0;
            foreach ($allRows as $row) {
                $cols = 0;
                $cells = $row->getElementsByTagName('th');
                if ($cells->length === 0) {
                    $cells = $row->getElementsByTagName('td');
                }
                foreach ($cells as $cell) {
                    $colspan = intval($cell->getAttribute('colspan')) ?: 1;
                    $cols += $colspan;
                }
                $maxCols = max($maxCols, $cols);
            }

            // 处理 rowspan - 跟踪需要在下一行填充的单元格
            $pendingRowspans = [];
            $markdownRows = [];
            $headerProcessed = false;

            foreach ($allRows as $rowIndex => $row) {
                $cells = $row->getElementsByTagName('th');
                if ($cells->length === 0) {
                    $cells = $row->getElementsByTagName('td');
                }

                // 将 DOMNodeList 转为数组以便索引访问
                $cellsArray = [];
                foreach ($cells as $cell) {
                    $cellsArray[] = $cell;
                }

                $cellContents = [];
                $cellIndex = 0;
                $col = 0;

                // 逐列构建行内容，正确处理 rowspan 占位列
                while ($col < $maxCols) {
                    // 检查当前列是否被上一行的 rowspan 占据
                    if (isset($pendingRowspans[$col]) && $pendingRowspans[$col] > 0) {
                        $cellContents[] = '';
                        $pendingRowspans[$col]--;
                        $col++;
                        continue;
                    }

                    // 处理当前行的实际单元格
                    if ($cellIndex < count($cellsArray)) {
                        $cell = $cellsArray[$cellIndex];
                        $cellText = trim($this->getElementTextWithBreaks($cell));
                        $cellText = str_replace(['|', '\n', '\r'], ['\\|', ' ', ' '], $cellText);
                        $cellContents[] = $cellText;

                        $colspan = intval($cell->getAttribute('colspan')) ?: 1;
                        $rowspan = intval($cell->getAttribute('rowspan')) ?: 1;

                        // 处理 colspan - 为额外列添加空单元格
                        for ($i = 1; $i < $colspan; $i++) {
                            $col++;
                            $cellContents[] = '';
                        }

                        // 处理 rowspan - 记录到 pendingRowspans 供后续行使用
                        if ($rowspan > 1) {
                            $pendingRowspans[$col] = $rowspan - 1;
                        }

                        $cellIndex++;
                        $col++;
                    } else {
                        // 当前行没有更多单元格，剩余列填充空值
                        $cellContents[] = '';
                        $col++;
                    }
                }

                $markdownRows[] = '| ' . implode(' | ', $cellContents) . ' |';

                // 添加表头分隔行
                if (!$headerProcessed && $row->getElementsByTagName('th')->length > 0) {
                    $separatorCells = array_fill(0, $maxCols, '---');
                    $markdownRows[] = '| ' . implode(' | ', $separatorCells) . ' |';
                    $headerProcessed = true;
                }
            }

            return "\n" . implode("\n", $markdownRows) . "\n";
        } catch (\Exception $e) {
            Log::warning('Failed to convert HTML table to Markdown: ' . $e->getMessage());
            return $htmlTable;
        }
    }

    /**
     * Get text content from a DOM element, including nested elements.
     * Converts <br> tags to spaces.
     */
    private function getElementTextWithBreaks(\DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->nodeValue;
            } elseif ($child instanceof \DOMElement) {
                if (strtolower($child->tagName) === 'br') {
                    $text .= ' ';
                } else {
                    $text .= $this->getElementTextWithBreaks($child);
                }
            }
        }
        return $text;
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

        $markdown = $this->fixMarkdownStructure($markdown);

        $markdown = $this->preserveCodeBlocks($markdown);

        return $markdown;
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

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#{1,6}\s+.*$/', $trimmed)) {
                $fixedLines[] = $trimmed;
            } elseif (preg_match('/^[\*\-\+]\s+.*$/', $trimmed) || preg_match('/^\d+\.\s+.*$/', $trimmed)) {
                $fixedLines[] = $trimmed;
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
        $lines = explode("\n", $markdown);
        $result = [];

        foreach ($lines as $line) {
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
        $this->centerHeadingsInDocx($outputDocx);
        $this->applyFirstParagraphStyleToDocx($outputDocx);

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

    // ----- 🔧 调试：保存中间 HTML 文件 -----
    // $debugPath = storage_path('logs/export_debug_' . time() . '_' . uniqid() . '.html');
    // file_put_contents($debugPath, $htmlString);
    // \Illuminate\Support\Facades\Log::info('Word export - intermediate HTML saved', ['path' => $debugPath]);
    // ---------------------------------------

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
        }

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

        foreach ($paragraphs as $index => $p) {
            $text = trim($p->textContent);
            if (preg_match('/^([a-z])\)\s*/i', $text, $matches)) {
                if ($listStartIndex === -1) {
                    $listStartIndex = $index;
                }
                $content = preg_replace('/^[a-z]\)\s*/i', '', $text);
                $listItems[] = [
                    'index' => $index,
                    'content' => $content,
                    'letter' => strtolower($matches[1])
                ];
            } else {
                if (count($listItems) >= 2) {
                    break;
                }
                $listItems = [];
                $listStartIndex = -1;
            }
        }

        if (count($listItems) < 2) {
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

        $firstItem = $listItems[0]['index'];
        $p = $paragraphs->item($firstItem);
        $p->parentNode->insertBefore($ol, $p);

        foreach ($listItems as $item) {
            $p = $paragraphs->item($item['index']);
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

        // ----- 7. 后处理：设置所有表格居中 -----
        $this->centerTablesInDocx($outputDocx);

        // ----- 8. 验证输出文件 -----
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

        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'pre', 'blockquote', 'table', 'ul', 'ol'];
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

                    if ($textContent === '' && $tag !== 'table') {
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
     * 为所有带 text-indent 的块级元素插入全角空格占位符，模拟首行缩进。
     * 同时移除原有的 text-indent 样式，避免 Pandoc 干扰。
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
        // 只处理块级元素（可根据需要增删）
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'];
        $nodes = $xpath->query('//*[@style]');

        $modifiedCount = 0;
        foreach ($nodes as $node) {
            // 只处理块级标签
            if (!in_array($node->tagName, $blockTags)) {
                continue;
            }

            $style = $node->getAttribute('style');
            if (!preg_match('/text-indent:\s*([^;]+);/', $style, $matches)) {
                continue;
            }

            $indentValue = trim($matches[1]);
            // 提取数值，忽略单位
            preg_match('/([\d.]+)/', $indentValue, $numMatches);
            $indentNum = floatval($numMatches[0] ?? 0);

            // 缩进阈值：> 5pt 或 > 0.5em 认为需要缩进
            $shouldIndent = false;
            if (strpos($indentValue, 'pt') !== false && $indentNum > 5) {
                $shouldIndent = true;
            }
            if (strpos($indentValue, 'em') !== false && $indentNum > 0.5) {
                $shouldIndent = true;
            }
            // 其他单位可酌情添加

            if (!$shouldIndent) {
                continue;
            }

            // ----- 在元素内容最前面插入两个全角空格 -----
            $fullwidthSpace = '　'; // UTF-8 全角空格，直接按字面量
            $spaceNode = $dom->createTextNode($fullwidthSpace . $fullwidthSpace);
            
            // 如果元素有子节点，插入到第一个子节点之前
            if ($node->hasChildNodes()) {
                $node->insertBefore($spaceNode, $node->firstChild);
            } else {
                // 空元素，直接追加文本节点
                $node->appendChild($spaceNode);
            }

            // ----- 移除原有的 text-indent 样式，避免干扰 -----
            $style = preg_replace('/text-indent:\s*[^;]+;?/', '', $style);
            if (trim($style) === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $style);
            }

            $modifiedCount++;
        }

        // ----- 重新拼接 HTML 片段 -----
        $innerHtml = '';
        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $innerHtml .= $dom->saveHTML($child);
        }

        // 调试日志（可保留或删除）
        // \Illuminate\Support\Facades\Log::info('insertIndentPlaceholders() - processed', [
        //     'modified_count' => $modifiedCount,
        //     'html_length' => strlen($innerHtml)
        // ]);

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
