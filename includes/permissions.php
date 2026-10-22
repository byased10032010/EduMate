<?php
/**
 * EduMate - Permission & ownership helpers.
 *
 * Hierarchical control:
 *   - Admins override everything.
 *   - A user can edit/delete ONLY content they created (created_by = user.id).
 *   - Course creators and co-teachers can moderate within their course.
 *   - Students can only view + interact (submit, reply, post in community, acknowledge).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * True if the current user is an admin.
 */
function is_admin_user(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/**
 * True if the current user created the given entity.
 * Pass an associative array that contains 'created_by' (or 'creator_id').
 */
function is_owner(array $entity): bool
{
    $u = current_user();
    if (!$u) return false;
    $ownerId = (int)($entity['created_by'] ?? $entity['creator_id'] ?? 0);
    return $ownerId === (int)$u['id'];
}


/**
 * Can the current user edit this entity?
 * Rules:
 *   - Admin: always.
 *   - Otherwise: only the original creator.
 */
function can_edit(array $entity): bool
{
    if (is_admin_user()) return true;
    return is_owner($entity);
}

/**
 * Can the current user delete this entity?
 * Same rules as can_edit.
 */
function can_delete(array $entity): bool
{
    return can_edit($entity);
}

/**
 * Is the current user a co-teacher or creator of the given course?
 * Returns the role string ('owner'|'co_owner'|'teacher') or null.
 */
function course_role(int $courseId): ?string
{
    $u = current_user();
    if (!$u) return null;
    if ($u['role'] === 'admin') return 'owner';

    // Owner / creator
    $pdo = db();
    $own = $pdo->prepare('SELECT teacher_id, creator_id FROM courses WHERE id = :id');
    $own->execute([':id' => $courseId]);
    $row = $own->fetch();
    if (!$row) return null;
    if ((int)$row['teacher_id'] === (int)$u['id'] || (int)$row['creator_id'] === (int)$u['id']) {
        return 'owner';
    }
    return null;
}

/**
 * Can the current user manage (edit/delete) ANY content within this course?
 * True for admins, course owners/co-teachers.
 */
function can_manage_course(int $courseId): bool
{
    if (is_admin_user()) return true;
    return course_role($courseId) !== null;
}

/**
 * Is the current user enrolled in / a member of this course?
 * (Students enrolled via enrollments or board members for board-level courses.)
 */
function is_course_member(int $courseId): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if (course_role($courseId) !== null) return true;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = :u AND course_id = :c');
    $stmt->execute([':u' => $u['id'], ':c' => $courseId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Is the current user a member of a given board (row_id)?
 */
function is_board_member(int $boardRowId): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT 1 FROM board_members WHERE board_id = :b AND user_id = :u');
    $stmt->execute([':b' => $boardRowId, ':u' => $u['id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Is the current user the owner of the given board (created it)?
 */
function is_board_owner(int $boardRowId): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT owner_id FROM boards WHERE row_id = :b');
    $stmt->execute([':b' => $boardRowId]);
    $row = $stmt->fetch();
    return $row && (int)$row['owner_id'] === (int)$u['id'];
}

/**
 * Fetch a friendly ownership badge: 'you' | 'admin' | 'other'.
 */
function owner_badge(array $entity): string
{
    if (is_admin_user() && !is_owner($entity)) return 'admin';
    if (is_owner($entity)) return 'you';
    return 'other';
}

/**
 * Render a small "Owned by ..." label for an entity, with avatar circle.
 * (Used in lists to make ownership visible to all viewers.)
 */
function render_owner_label(array $entity): string
{
    $createdBy = (int)($entity['created_by'] ?? $entity['creator_id'] ?? 0);
    if ($createdBy <= 0) return '';
    static $cache = [];
    if (!isset($cache[$createdBy])) {
        try {
            $stmt = db()->prepare('SELECT id, full_name, role FROM users WHERE id = :id');
            $stmt->execute([':id' => $createdBy]);
            $cache[$createdBy] = $stmt->fetch() ?: ['id' => $createdBy, 'full_name' => 'Unknown', 'role' => ''];
        } catch (Throwable $e) {
            $cache[$createdBy] = ['id' => $createdBy, 'full_name' => 'Unknown', 'role' => ''];
        }
    }
    $u = $cache[$createdBy];
    [$c1, $c2] = user_avatar_gradient((int)$u['id']);
    $initials = user_initials($u['full_name']);
    $name = htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8');
    $role = htmlspecialchars(ucfirst($u['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    $isYou = is_owner($entity) ? ' <span class="tag success" style="margin-left:4px;">you</span>' : '';
    return '<span class="owner-chip" title="Created by ' . $name . '">'
         . '<span class="avatar-mini" style="background:linear-gradient(135deg,' . $c1 . ',' . $c2 . ');">' . htmlspecialchars($initials, ENT_QUOTES) . '</span>'
         . '<span class="owner-name">' . $name . $isYou . '</span>'
         . '</span>';
}

/**
 * Generate a short, human-friendly join code for live sessions.
 */
function generate_join_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}
