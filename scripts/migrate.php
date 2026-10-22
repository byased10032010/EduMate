<?php
/**
 * EduMate - Database Migration
 * Adds class, board, streak_count, last_active_date, bio to users
 * Adds class and board to courses
 * Creates boards, course_subjects, dpps, and notices tables
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = db();

try {
    echo "Running migrations...\n";

    // 1. Update users table
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('class', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN class INT NULL");
        echo "Added 'class' column to users.\n";
    }
    if (!in_array('board', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN board VARCHAR(50) NULL");
        echo "Added 'board' column to users.\n";
    }
    if (!in_array('streak_count', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN streak_count INT UNSIGNED DEFAULT 0");
        echo "Added 'streak_count' column to users.\n";
    }
    if (!in_array('last_active_date', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_active_date DATE NULL");
        echo "Added 'last_active_date' column to users.\n";
    }
    if (!in_array('bio', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN bio VARCHAR(500) NULL");
        echo "Added 'bio' column to users.\n";
    }

    // 2. Update courses table
    $columnsCourses = $pdo->query("SHOW COLUMNS FROM courses")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('class', $columnsCourses, true)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN class INT NULL");
        echo "Added 'class' column to courses.\n";
    }
    if (!in_array('board', $columnsCourses, true)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN board VARCHAR(50) NULL");
        echo "Added 'board' column to courses.\n";
    }

    // 3. Create boards table
    $pdo->exec("CREATE TABLE IF NOT EXISTS boards (
        name VARCHAR(50) PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Ensured 'boards' table exists.\n";

    // 4. Create course_subjects table
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_subjects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        board VARCHAR(50) NOT NULL,
        class INT NOT NULL,
        UNIQUE KEY uniq_sub_board_class (name, board, class)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Ensured 'course_subjects' table exists.\n";

    // 5. Create dpps table
    $pdo->exec("CREATE TABLE IF NOT EXISTS dpps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Ensured 'dpps' table exists.\n";

    // 6. Create notices table
    $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Ensured 'notices' table exists.\n";

    // Seed default boards and subjects if empty
    $boardCount = (int)$pdo->query("SELECT COUNT(*) FROM boards")->fetchColumn();
    if ($boardCount === 0) {
        $pdo->exec("INSERT INTO boards (name) VALUES ('CBSE'), ('ICSE'), ('State Board')");
        echo "Seeded default boards.\n";
    }

    $subjectCount = (int)$pdo->query("SELECT COUNT(*) FROM course_subjects")->fetchColumn();
    if ($subjectCount === 0) {
        $subjectsToSeed = [
            // Class 8 CBSE
            ['Mathematics', 'CBSE', 8],
            ['Science', 'CBSE', 8],
            ['English', 'CBSE', 8],
            ['Social Science', 'CBSE', 8],
            // Class 9 CBSE
            ['Mathematics', 'CBSE', 9],
            ['Science', 'CBSE', 9],
            ['English', 'CBSE', 9],
            // Class 10 ICSE
            ['Mathematics', 'ICSE', 10],
            ['Physics', 'ICSE', 10],
            ['Chemistry', 'ICSE', 10],
            ['Biology', 'ICSE', 10],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO course_subjects (name, board, class) VALUES (?, ?, ?)");
        foreach ($subjectsToSeed as $s) {
            $stmt->execute($s);
        }
        echo "Seeded default course subjects.\n";
    }

    echo "✅ Migrations complete.\n";
} catch (Throwable $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
