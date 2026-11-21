<?php
// services/CatalogueService.php
// Modules 0.2.1 (Search), 0.2.2 (Add Book + Auto Book Index + PDF Upload), 0.2.3 (Soft-Delete/Restore)
// Supports both OOP (CatalogueService) and procedural function calls used across index.php & admin_dashboard.php

class CatalogueService
{
    private PDO $pdo;
    private string $uploadDir;

    /**
     * Genre-to-Prefix map for automatic Book Index generation (e.g., SF-01, CS-02)
     */
    public const GENRE_PREFIXES = [
        'Computer Science'   => 'CS',
        'Science Fiction'    => 'SF',
        'Fantasy'            => 'FN',
        'Philosophy'         => 'PH',
        'Literature'         => 'LT',
        'Biography'          => 'BG',
        'Psychology'         => 'PS',
        'Mystery & Thriller' => 'MY',
        'Kids Story-books'   => 'KS',
        'Science & Nature'   => 'SN',
        'Economics'          => 'EC',
        'Mythology'          => 'MT',
        'Poetry & Drama'     => 'PD',
        'Sports'             => 'SP',
        'Romance'            => 'RM'
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdfs';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Module 0.2.1: Multi-word catalog search
     */
    public function searchBooks(string $query = '', bool $includeDeleted = false): array
    {
        $sql = "SELECT * FROM books WHERE 1=1";
        $params = [];

        if (!$includeDeleted) {
            $sql .= " AND is_deleted = FALSE";
        }

        $trimmed = trim($query);
        if ($trimmed !== '') {
            $words = preg_split('/\s+/', $trimmed);
            foreach ($words as $idx => $word) {
                $paramKey = ":w{$idx}";
                $sql .= " AND (
                    title ILIKE {$paramKey} OR 
                    author ILIKE {$paramKey} OR 
                    isbn ILIKE {$paramKey} OR 
                    book_index ILIKE {$paramKey} OR 
                    genre_category ILIKE {$paramKey}
                )";
                $params[$paramKey] = '%' . $word . '%';
            }
        }

        $sql .= " ORDER BY is_deleted ASC, book_index ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generates next sequential immutable book_index (e.g., CS-01, CS-02)
     */
    public function generateNextBookIndex(string $genreCategory): string
    {
        $prefix = self::GENRE_PREFIXES[$genreCategory] ?? 'GN';
        $prefixWithDash = $prefix . '-';

        $stmt = $this->pdo->prepare("
            SELECT book_index 
            FROM books 
            WHERE book_index LIKE :prefix_pattern
        ");
        $stmt->execute([':prefix_pattern' => $prefixWithDash . '%']);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $maxNum = 0;
        $prefixLen = strlen($prefixWithDash);

        foreach ($existing as $code) {
            if (strpos($code, $prefixWithDash) === 0) {
                $suffix = substr($code, $prefixLen);
                if (ctype_digit($suffix)) {
                    $num = (int) $suffix;
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }
        }

        $nextNum = $maxNum + 1;
        return sprintf('%s-%02d', $prefix, $nextNum);
    }

    /**
     * Module 0.2.2: Add new book to D2 (books) with optional PDF upload
     */
    public function addBook(array $data, ?array $pdfFile = null): array
    {
        $isbn            = trim($data['isbn'] ?? '');
        $title           = trim($data['title'] ?? '');
        $author          = trim($data['author'] ?? '');
        $publisher       = trim($data['publisher'] ?? '');
        $genreCategory   = trim($data['genre_category'] ?? 'General');
        $publicationYear = !empty($data['publication_year']) ? (int) $data['publication_year'] : null;
        $totalCopies     = max(1, (int) ($data['total_copies'] ?? 1));

        if ($isbn === '' || $title === '' || $author === '') {
            throw new InvalidArgumentException('ISBN, Title, and Author are required.');
        }

        $bookIndex = $this->generateNextBookIndex($genreCategory);
        $savedPdfFileName = null;

        try {
            if ($pdfFile && isset($pdfFile['error']) && $pdfFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $savedPdfFileName = $this->processPdfUpload($pdfFile, $bookIndex);
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO books (
                    isbn, title, author, publisher, genre_category,
                    publication_year, book_index, total_copies, available_copies,
                    is_deleted, pdf_path
                ) VALUES (
                    :isbn, :title, :author, :publisher, :genre_category,
                    :publication_year, :book_index, :total_copies, :available_copies,
                    FALSE, :pdf_path
                )
                RETURNING book_id, book_index
            ");

            $stmt->execute([
                ':isbn'             => $isbn,
                ':title'            => $title,
                ':author'           => $author,
                ':publisher'        => $publisher !== '' ? $publisher : null,
                ':genre_category'   => $genreCategory,
                ':publication_year' => $publicationYear,
                ':book_index'       => $bookIndex,
                ':total_copies'     => $totalCopies,
                ':available_copies' => $totalCopies,
                ':pdf_path'         => $savedPdfFileName,
            ]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            if ($savedPdfFileName !== null) {
                $this->deleteLocalPdf($savedPdfFileName);
            }
            throw $e;
        }
    }

    /**
     * Upload or replace PDF for an existing book in D2 (books)
     */
    public function attachOrReplacePdf(int $bookId, array $pdfFile): string
    {
        $stmt = $this->pdo->prepare("
            SELECT book_id, book_index, pdf_path 
            FROM books 
            WHERE book_id = :book_id 
            LIMIT 1
        ");
        $stmt->execute([':book_id' => $bookId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$book) {
            throw new RuntimeException('Book record not found.');
        }

        if (!isset($pdfFile['error']) || $pdfFile['error'] === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Please choose a PDF file to upload.');
        }

        $newFileName = $this->processPdfUpload($pdfFile, $book['book_index']);

        $updateStmt = $this->pdo->prepare("
            UPDATE books 
            SET pdf_path = :pdf_path 
            WHERE book_id = :book_id
        ");
        $updateStmt->execute([
            ':pdf_path' => $newFileName,
            ':book_id'  => $bookId,
        ]);

        if (!empty($book['pdf_path']) && $book['pdf_path'] !== $newFileName) {
            $this->deleteLocalPdf((string) $book['pdf_path']);
        }

        return $newFileName;
    }

    /**
     * Remove linked PDF from an existing book
     */
    public function removePdf(int $bookId): void
    {
        $stmt = $this->pdo->prepare("SELECT pdf_path FROM books WHERE book_id = :book_id LIMIT 1");
        $stmt->execute([':book_id' => $bookId]);
        $pdfPath = $stmt->fetchColumn();

        $updateStmt = $this->pdo->prepare("UPDATE books SET pdf_path = NULL WHERE book_id = :book_id");
        $updateStmt->execute([':book_id' => $bookId]);

        if (!empty($pdfPath)) {
            $this->deleteLocalPdf((string) $pdfPath);
        }
    }

    /**
     * Module 0.2.3: Soft-delete or restore a book
     */
    public function setDeletedStatus(int $bookId, bool $isDeleted): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE books 
            SET is_deleted = :is_deleted 
            WHERE book_id = :book_id
        ");
        $stmt->bindValue(':is_deleted', $isDeleted, PDO::PARAM_BOOL);
        $stmt->bindValue(':book_id', $bookId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Validates MIME type & moves uploaded PDF to uploads/pdfs/
     */
    private function processPdfUpload(array $file, string $bookIndex): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            throw new RuntimeException($messages[$file['error']] ?? 'PDF upload failed.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if ($mimeType !== 'application/pdf') {
            throw new InvalidArgumentException('Invalid file type. Only valid PDF documents are allowed.');
        }

        $safePrefix = preg_replace('/[^A-Za-z0-9\-]/', '', $bookIndex);
        $uniqueSuffix = time() . '_' . bin2hex(random_bytes(3));
        $fileName = "{$safePrefix}_{$uniqueSuffix}.pdf";
        $destination = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to move uploaded PDF to uploads/pdfs/. Check folder permissions.');
        }

        return $fileName;
    }

    /**
     * Deletes a local PDF file from uploads/pdfs/ (skips external URLs safely)
     */
    private function deleteLocalPdf(string $pdfPath): void
    {
        if (preg_match('/^https?:\/\//i', $pdfPath)) {
            return;
        }

        $localPath = $this->uploadDir . DIRECTORY_SEPARATOR . basename($pdfPath);
        if (is_file($localPath)) {
            @unlink($localPath);
        }
    }
}

// ============================================================================
// PROCEDURAL WRAPPERS (Backward compatibility for index.php & existing scripts)
// ============================================================================

if (!function_exists('searchBooks')) {
    function searchBooks(PDO $pdo, string $query = '', bool $includeDeleted = false): array
    {
        $service = new CatalogueService($pdo);
        return $service->searchBooks($query, $includeDeleted);
    }
}

if (!function_exists('addBook')) {
    function addBook(PDO $pdo, array $data, ?array $pdfFile = null): array
    {
        $service = new CatalogueService($pdo);
        return $service->addBook($data, $pdfFile);
    }
}

if (!function_exists('softDeleteBook')) {
    function softDeleteBook(PDO $pdo, int $bookId): void
    {
        $service = new CatalogueService($pdo);
        $service->setDeletedStatus($bookId, true);
    }
}

if (!function_exists('restoreBook')) {
    function restoreBook(PDO $pdo, int $bookId): void
    {
        $service = new CatalogueService($pdo);
        $service->setDeletedStatus($bookId, false);
    }
}

if (!function_exists('attachOrReplacePdf')) {
    function attachOrReplacePdf(PDO $pdo, int $bookId, array $pdfFile): string
    {
        $service = new CatalogueService($pdo);
        return $service->attachOrReplacePdf($bookId, $pdfFile);
    }
}