<?php

// Module 0.2.1: Search Book (Reads from D2 Book Store for public shelf)
function searchBooks(PDO $pdo, string $keyword = ''): array {
    $keyword = trim($keyword);

    if ($keyword === '') {
        $sql = "SELECT * FROM books WHERE is_deleted = FALSE ORDER BY created_at DESC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll();
    }

    $sql = "SELECT * FROM books 
            WHERE is_deleted = FALSE 
              AND (title ILIKE :q OR author ILIKE :q OR isbn ILIKE :q OR genre_category ILIKE :q)
            ORDER BY title ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q' => '%' . $keyword . '%']);
    return $stmt->fetchAll();
}

// Fetch all non-deleted books for the Cataloger/Admin table
function getAllBooksAdmin(PDO $pdo): array {
    $sql = "SELECT * FROM books WHERE is_deleted = FALSE ORDER BY book_id DESC";
    return $pdo->query($sql)->fetchAll();
}

// Module 0.2.2: Add Book (Writes to D2 Book Store)
function addBook(PDO $pdo, array $data): array {
    $isbn = trim($data['isbn'] ?? '');
    $title = trim($data['title'] ?? '');
    $author = trim($data['author'] ?? '');
    $copies = (int)($data['total_copies'] ?? 1);

    if ($isbn === '' || $title === '' || $author === '' || $copies < 1) {
        return ['success' => false, 'message' => 'ISBN, Title, Author, and at least 1 copy are required.'];
    }

    try {
        // 0.2.2.3 Save Book Record
        $sql = "INSERT INTO books (isbn, title, author, publisher, genre_category, publication_year, rack_location, total_copies, available_copies)
                VALUES (:isbn, :title, :author, :publisher, :genre, :year, :rack, :copies, :copies)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':isbn'      => $isbn,
            ':title'     => $title,
            ':author'    => $author,
            ':publisher' => trim($data['publisher'] ?? ''),
            ':genre'     => trim($data['genre_category'] ?? 'General'),
            ':year'      => !empty($data['publication_year']) ? (int)$data['publication_year'] : null,
            ':rack'      => trim($data['rack_location'] ?? 'GEN-01'),
            ':copies'    => $copies
        ]);

        return ['success' => true, 'message' => "Book '{$title}' added to the Open Library catalog!"];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Could not add book (ISBN may already exist): ' . $e->getMessage()];
    }
}

// Module 0.2.3: Flag Book Deleted (Soft-Delete in D2 Book Store)
function softDeleteBook(PDO $pdo, int $bookId): array {
    // Safety check: Don't hide a book if copies are currently checked out
    $checkStmt = $pdo->prepare("SELECT title, total_copies, available_copies FROM books WHERE book_id = :bid");
    $checkStmt->execute([':bid' => $bookId]);
    $book = $checkStmt->fetch();

    if (!$book) {
        return ['success' => false, 'message' => 'Book not found.'];
    }

    if ((int)$book['available_copies'] < (int)$book['total_copies']) {
        return ['success' => false, 'message' => "Cannot remove '{$book['title']}' while copies are still borrowed by members."];
    }

    // 0.2.3.2 Flag Book Deleted & 0.2.3.3 Save Deletion Flag
    $stmt = $pdo->prepare("UPDATE books SET is_deleted = TRUE WHERE book_id = :bid");
    $stmt->execute([':bid' => $bookId]);

    return ['success' => true, 'message' => "Book '{$book['title']}' flagged as deleted (historical loans preserved)."];
}
?>