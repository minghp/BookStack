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
            // Get page HTML content
            $pageContent = (new PageContent($page));
            $page->html = $pageContent->render();
            
            // Create temporary files
            $tempDir = sys_get_temp_dir();
            $tempHtml = $this->createTempHtmlFile($page, $tempDir);
            $tempDocx = tempnam($tempDir, 'bookstack_word_') . '.docx';
            
            // Convert HTML to DOCX using pandoc
            $this->convertHtmlToDocx($tempHtml, $tempDocx);
            
            // Return file download
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
        
        // Process images in HTML
        $baseUrl = url('/');
        $htmlContent = preg_replace('/src="\/(uploads\/[^"]+)"/', 'src="' . $baseUrl . '/$1"', $page->html);
        $htmlContent = preg_replace('/src="\/(storage\/[^"]+)"/', 'src="' . $baseUrl . '/$1"', $htmlContent);
        
        // 移除第一个h1标签（包括其内容）
        $htmlContent = $this->removeFirstH1($htmlContent);
        
        // Build simple HTML document with only the content
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    {$htmlContent}
</body>
</html>
HTML;
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
            // 如果处理失败，返回原内容
            Log::warning('Failed to remove first h1 tag: ' . $e->getMessage());
        }
        
        return $html;
    }

    /**
     * Convert HTML to DOCX using pandoc.
     */
    private function convertHtmlToDocx(string $inputHtml, string $outputDocx): void
    {
        // Get template path
        $templatePath = config('app.word_export_template', '/app/medical_device_template.docx');
        
        if (!file_exists($templatePath)) {
            throw new \Exception("Word template file does not exist: " . $templatePath);
        }
        
        // Build pandoc command
        $command = [
            'pandoc',
            $inputHtml,
            '-o', $outputDocx,
            '--reference-doc=' . $templatePath,
            '--resource-path=' . dirname($inputHtml),
            '--self-contained',
            '--wrap=none',
            '--standalone',
        ];
        
        // Execute conversion
        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout
        $process->run();
        
        // Clean up temporary HTML file
        if (file_exists($inputHtml)) {
            @unlink($inputHtml);
        }
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        
        // Check output file
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
     * Get export styles.
     */
    private function getExportStyles(): string
    {
        return <<<CSS
body {
    font-family: 'Microsoft YaHei', 'SimSun', serif;
    font-size: 12pt;
    line-height: 1.5;
    color: #000000;
    margin: 2cm;
}

h1 {
    font-size: 20pt;
    color: #000080;
    text-align: center;
    margin-bottom: 30px;
    border-bottom: 2px solid #000080;
    padding-bottom: 10px;
}

h2 {
    font-size: 16pt;
    color: #000080;
    margin-top: 24px;
    margin-bottom: 12px;
}

h3 {
    font-size: 14pt;
    color: #000080;
    margin-top: 18px;
    margin-bottom: 9px;
}

.export-header {
    text-align: center;
    margin-bottom: 40px;
}

.page-metadata {
    font-size: 10pt;
    color: #666666;
    margin: 20px 0;
    text-align: left;
    border-top: 1px solid #cccccc;
    padding-top: 10px;
}

.export-content {
    text-align: justify;
}

.export-content img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 15px auto;
}

.export-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 15px 0;
}

.export-content table, 
.export-content th, 
.export-content td {
    border: 1px solid #000000;
}

.export-content th, 
.export-content td {
    padding: 8px 12px;
    text-align: left;
}

.export-content th {
    background-color: #f2f2f2;
    font-weight: bold;
}

.export-content pre, 
.export-content code {
    background-color: #f8f8f8;
    font-family: 'Courier New', monospace;
}

.export-content pre {
    padding: 12px;
    overflow-x: auto;
    border-left: 3px solid #000080;
    margin: 15px 0;
}

.export-content blockquote {
    border-left: 4px solid #000080;
    padding-left: 20px;
    margin-left: 0;
    color: #444444;
    font-style: italic;
}

.export-content ul, 
.export-content ol {
    margin: 10px 0 10px 30px;
}

.export-content li {
    margin-bottom: 5px;
}

.page-break {
    page-break-before: always;
}
CSS;
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
