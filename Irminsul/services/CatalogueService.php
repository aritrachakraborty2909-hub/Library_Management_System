<?php
// Master Genre -> Index Prefix Dictionary
function getGenreMap(): array {
    return [
        'Science Fiction'     => 'SF',
        'Classic Literature'  => 'CL',
        'Computer Science'    => 'CS',
        'Fantasy'             => 'FN',
        'Mystery & Thriller'  => 'MT',
        'Historical Fiction'  => 'HF',
        'Biography & Memoir'  => 'BM',
        'Philosophy'          => 'PH',
        'Science & Nature'    => 'SN',
        'Mathematics'         => 'MA',
        'Economics & Business'=> 'EB',
        'Poetry & Drama'      => 'PD'
    ];
}

// Generate the next sequential Book Index (e.g., SF-01, SF-02)
function generateNextBookIndex(PDO $pdo, string $genre): string {
    $genreMap = getGenreMap();
    if (!isset($genreMap[$genre])) {
        throw new InvalidArgumentException("Invalid genre selected.");
    }

    $prefix = $genreMap[$genre];

    // Find the highest existing sequence number for this prefix (including soft-deleted books)
    $stmt = $pdo->prepare("
        SELECT COALESCE(
            MAX(CAST(SPLIT_PART(book_index, '-', 2) AS INT)), 
            0
        ) AS max_seq
        FROM books
        WHERE book_index LIKE :prefix_pattern
    ");
    $stmt->execute([':prefix_pattern' => $prefix . '-%']);
    $nextNumber = ((int)$stmt->fetchColumn()) + 1;

    // Format with leading zero for 1-9 (e.g., 1 -> '01', 12 -> '12')
    return sprintf('%s-%02d', $prefix, $nextNumber);
}

// Module 0.2.1: Search Book (Reads from D2 Book Store)
function searchBooks(PDO $pdo, string $keyword = ''): array {
    $keyword = trim($keyword);

    if ($keyword === '') {
        $sql = "SELECT * FROM books WHERE is_deleted = FALSE ORDER BY book_index ASC";
        return $pdo->query($sql)->fetchAll();
    }

    // Search by Title, Author, ISBN, Genre, OR Book Index (e.g., 'SF-01')
    $sql = "SELECT * FROM books 
            WHERE is_deleted = FALSE 
              AND (title ILIKE :q OR author ILIKE :q OR isbn ILIKE :q OR genre_category ILIKE :q OR book_index ILIKE :q)
            ORDER BY book_index ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => '%' . $keyword . '%']);
    return $stmt->fetchAll();
}

// Module 0.2.2: Add Book with Server-Generated Immutable Book Index
function addBook(PDO $pdo, array $data): array {
    $genre = trim($data['genre_category'] ?? '');
    $genreMap = getGenreMap();

    if (!isset($genreMap[$genre])) {
        return ['success' => false, 'message' => 'Please select a valid genre from the dropdown list.'];
    }

    try {
        // Generate the next index on the server (ignores any tampered form input)
        $bookIndex = generateNextBookIndex($pdo, $genre);

        $sql = "INSERT INTO books (isbn, title, author, publisher, genre_category, publication_year, book_index, total_copies, available_copies)
                VALUES (:isbn, :title, :author, :publisher, :genre, :year, :book_index, :copies, :copies)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':isbn'       => trim($data['isbn']),
            ':title'      => trim($data['title']),
            ':author'     => trim($data['author']),
            ':publisher'  => trim($data['publisher']),
            ':genre'      => $genre,
            ':year'       => !empty($data['publication_year']) ? (int)$data['publication_year'] : null,
            ':book_index' => $bookIndex,
            ':copies'     => max(1, (int)$data['total_copies'])
        ]);

        return [
            'success' => true, 
            'message' => "Book added to shelf with permanent Book Index: {$bookIndex}"
        ];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Error adding book (check if ISBN already exists).'];
    }
}
?>