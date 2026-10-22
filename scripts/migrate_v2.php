<?php
/**
 * EduMate - Database Migration v2
 * Updates videos table with teacher_id, class, and board columns
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$pdo = db();

try {
    echo "Running migration v2...\n";

    // Update videos table
    $columns = $pdo->query("SHOW COLUMNS FROM videos")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('teacher_id', $columns, true)) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN teacher_id INT UNSIGNED NULL");
        $pdo->exec("ALTER TABLE videos ADD FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL");
        echo "Added 'teacher_id' column to videos.\n";
    }
    if (!in_array('class', $columns, true)) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN class INT NULL");
        echo "Added 'class' column to videos.\n";
    }
    if (!in_array('board', $columns, true)) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN board VARCHAR(50) NULL");
        echo "Added 'board' column to videos.\n";
    }

    // Set default teacher IDs for existing videos
    // Math teacher id is usually 11, Science is 12 (based on seeder)
    $mathTeacherId = (int)$pdo->query("SELECT id FROM users WHERE email = 'ananya.rao@edumate.local'")->fetchColumn();
    $scienceTeacherId = (int)$pdo->query("SELECT id FROM users WHERE email = 'vikram.iyer@edumate.local'")->fetchColumn();

    if ($mathTeacherId > 0) {
        $pdo->prepare("UPDATE videos SET teacher_id = :tid WHERE subject IN ('Mathematics', 'Computer Science')")
            ->execute([':tid' => $mathTeacherId]);
    }
    if ($scienceTeacherId > 0) {
        $pdo->prepare("UPDATE videos SET teacher_id = :tid WHERE subject IN ('Physics', 'Chemistry', 'Biology', 'Science')")
            ->execute([':tid' => $scienceTeacherId]);
    }

    echo "✅ Migration v2 complete.\n";
} catch (Throwable $e) {
    echo "❌ Migration v2 failed: " . $e->getMessage() . "\n";
    exit(1);
}
