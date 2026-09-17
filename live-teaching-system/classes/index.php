<?php
require_once '../config/database.php';
require_once '../includes/header.php';

$connection = getDbConnection();

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$level = isset($_GET['level']) ? trim($_GET['level']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$price_range = isset($_GET['price_range']) ? trim($_GET['price_range']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 9;
$offset = ($page - 1) * $limit;

// Build query
$whereConditions = ["c.status != 'cancelled'"];
$params = [];
$types = "";

if (!empty($search)) {
    $whereConditions[] = "(c.title LIKE ? OR c.description LIKE ? OR c.short_description LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($level)) {
    $whereConditions[] = "c.level = ?";
    $params[] = $level;
    $types .= "s";
}

if (!empty($category)) {
    $whereConditions[] = "c.category = ?";
    $params[] = $category;
    $types .= "s";
}

if (!empty($price_range)) {
    if ($price_range == 'free') {
        $whereConditions[] = "c.price = 0";
    } elseif ($price_range == 'under_500') {
        $whereConditions[] = "c.price > 0 AND c.price < 500";
    } elseif ($price_range == '500_1000') {
        $whereConditions[] = "c.price >= 500 AND c.price <= 1000";
    } elseif ($price_range == 'above_1000') {
        $whereConditions[] = "c.price > 1000";
    }
}

$whereClause = implode(" AND ", $whereConditions);

// Get total count
$countSql = "SELECT COUNT(*) as total FROM live_classes c WHERE $whereClause";
$countStmt = $connection->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalClasses = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalClasses / $limit);

// Get classes
$sql = "SELECT c.*, u.fullname as teacher_name,
        (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id AND payment_status = 'paid') as enrolled_count
        FROM live_classes c 
        JOIN users u ON c.teacher_id = u.id 
        WHERE $whereClause 
        ORDER BY 
            CASE 
                WHEN c.status = 'ongoing' THEN 1
                WHEN c.status = 'upcoming' THEN 2
                ELSE 3
            END,
            c.start_date ASC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $connection->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unique categories for filter
$categories = [];
$catResult = $connection->query("SELECT DISTINCT category FROM live_classes WHERE category IS NOT NULL AND category != '' AND status != 'cancelled'");
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row['category'];
}

// Get enrolled class IDs for logged-in students
$enrolledIds = [];
if (isset($_SESSION['user']) && $_SESSION['user']['role'] == 'student') {
    $enrollResult = $connection->query("SELECT class_id FROM enrollments WHERE student_id = {$_SESSION['user']['id']} AND payment_status = 'paid'");
    while ($row = $enrollResult->fetch_assoc()) {
        $enrolledIds[] = $row['class_id'];
    }
}
?>

<style>
/* ===== LiveTeach classes revamp — scoped to .lt-page, matches homepage style ===== */
.lt-page {
    --lt-ink: #100D0A;
    --lt-charcoal: #1B1712;
    --lt-charcoal-raised: #241F18;
    --lt-flame: #C8341E;
    --lt-flame-dark: #8A2213;
    --lt-flame-bright: #E44E2E;
    --lt-gold: #D9A441;
    --lt-parchment: #F1E7D6;
    --lt-parchment-dim: #C9BEAC;
    --lt-line: rgba(241, 231, 214, 0.12);
    background: var(--lt-ink);
    color: var(--lt-parchment);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.lt-page .lt-serif,
.lt-page h1, .lt-page h2, .lt-page h3, .lt-page h4 {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-weight: 500;
}

.lt-page a { text-decoration: none; color: inherit; }

.lt-container {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ---------- Page header ---------- */
.lt-header {
    padding: 72px 0 56px;
    border-bottom: 1px solid var(--lt-line);
    position: relative;
    overflow: hidden;
}

.lt-header::before {
    content: "";
    position: absolute;
    top: -200px;
    right: -160px;
    width: 520px;
    height: 520px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(200, 52, 30, 0.28), transparent 70%);
    pointer-events: none;
}

.lt-header-inner {
    position: relative;
    z-index: 1;
}

.lt-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    font-size: 0.8rem;
    letter-spacing: 0.03em;
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
    padding: 7px 14px 7px 12px;
    border-radius: 999px;
    margin-bottom: 24px;
}

.lt-eyebrow-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.8s ease-in-out infinite;
}

@keyframes lt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(228, 78, 46, 0.55); }
    50% { box-shadow: 0 0 0 6px rgba(228, 78, 46, 0); }
}

.lt-header h1 {
    font-size: clamp(2.2rem, 4.5vw, 3.2rem);
    line-height: 1.1;
    max-width: 16ch;
    margin: 0 0 18px;
    color: var(--lt-parchment);
}

.lt-header p {
    max-width: 52ch;
    font-size: 1.05rem;
    line-height: 1.6;
    color: var(--lt-parchment-dim);
    margin: 0;
}

/* ---------- Layout ---------- */
.lt-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 40px;
    padding: 48px 0 72px;
    align-items: start;
}

/* ---------- Filter sidebar ---------- */
.lt-filter {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    padding: 26px 22px;
    position: sticky;
    top: 100px;
}

.lt-filter h2 {
    font-size: 1.05rem;
    margin: 0 0 22px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--lt-line);
    color: var(--lt-parchment);
    display: flex;
    align-items: center;
    gap: 8px;
}

.lt-field {
    margin-bottom: 22px;
}

.lt-field label.lt-label {
    display: block;
    font-size: 0.78rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--lt-parchment-dim);
    margin-bottom: 8px;
    font-weight: 600;
}

.lt-field input[type="text"],
.lt-field select {
    width: 100%;
    padding: 11px 12px;
    background: var(--lt-ink);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment);
    font-size: 0.9rem;
    font-family: inherit;
    transition: border-color 0.15s ease;
}

.lt-field input[type="text"]::placeholder {
    color: rgba(201, 190, 172, 0.5);
}

.lt-field input[type="text"]:focus,
.lt-field select:focus {
    outline: none;
    border-color: var(--lt-flame);
}

.lt-radio-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.lt-radio {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 6px 0;
    font-size: 0.9rem;
    color: var(--lt-parchment);
    transition: color 0.15s ease;
}

.lt-radio:hover { color: var(--lt-gold); }

.lt-radio input {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--lt-flame);
    flex-shrink: 0;
}

.lt-clear {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 18px;
    font-size: 0.82rem;
    color: var(--lt-gold);
    transition: color 0.15s ease;
}

.lt-clear:hover { color: var(--lt-flame-bright); }

.lt-filter-tip {
    margin-top: 22px;
    padding-top: 20px;
    border-top: 1px solid var(--lt-line);
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
}

.lt-filter-tip p { margin: 0 0 10px; }
.lt-filter-tip p:last-child { margin-bottom: 0; }
.lt-filter-tip strong { color: var(--lt-parchment); }

/* ---------- Results bar ---------- */
.lt-results-bar {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 28px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--lt-line);
    gap: 16px;
    flex-wrap: wrap;
}

.lt-results-bar .lt-count {
    font-size: 0.95rem;
    color: var(--lt-parchment-dim);
}

.lt-results-bar .lt-count strong {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.2rem;
    color: var(--lt-gold);
    font-weight: 500;
    margin-right: 4px;
}

.lt-results-bar .lt-count em {
    font-style: normal;
    color: var(--lt-parchment);
    font-weight: 500;
}

/* ---------- Class cards grid ---------- */
.lt-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 22px;
}

.lt-class {
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    transition: border-color 0.2s ease, transform 0.2s ease;
}

.lt-class:hover {
    border-color: var(--lt-flame);
    transform: translateY(-3px);
}

.lt-class-top {
    padding: 22px 22px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.lt-class-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}

.lt-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    white-space: nowrap;
}

.lt-status-upcoming {
    background: rgba(217, 164, 65, 0.15);
    color: var(--lt-gold);
    border: 1px solid rgba(217, 164, 65, 0.3);
}

.lt-status-ongoing {
    background: rgba(200, 52, 30, 0.18);
    color: var(--lt-flame-bright);
    border: 1px solid rgba(200, 52, 30, 0.4);
}

.lt-status-ongoing .lt-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--lt-flame-bright);
    animation: lt-pulse 1.5s infinite;
}

.lt-status-completed {
    background: rgba(241, 231, 214, 0.06);
    color: var(--lt-parchment-dim);
    border: 1px solid var(--lt-line);
}

.lt-class-body {
    padding: 18px 22px 22px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.lt-class-date {
    font-size: 0.78rem;
    color: var(--lt-gold);
    margin-bottom: 10px;
    letter-spacing: 0.02em;
}

.lt-class h3 {
    font-size: 1.15rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
    line-height: 1.3;
}

.lt-class-desc {
    font-size: 0.88rem;
    color: var(--lt-parchment-dim);
    line-height: 1.55;
    margin: 0 0 14px;
}

.lt-class-teacher {
    font-size: 0.82rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 12px;
}

.lt-class-meta {
    font-size: 0.8rem;
    color: var(--lt-parchment-dim);
    margin: 0 0 6px;
}

.lt-class-meta .lt-warn {
    color: var(--lt-gold);
    margin-left: 4px;
}

.lt-class-foot {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid var(--lt-line);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.lt-price-tag {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.3rem;
    color: var(--lt-parchment);
}

.lt-price-tag small {
    font-size: 0.68rem;
    color: var(--lt-parchment-dim);
    margin-left: 4px;
    font-family: 'Inter', sans-serif;
    font-weight: 400;
}

.lt-free-tag {
    font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif;
    font-size: 1.15rem;
    color: var(--lt-gold);
}

/* ---------- Buttons ---------- */
.lt-btn {
    display: inline-block;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid transparent;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease;
    cursor: pointer;
    white-space: nowrap;
    font-family: inherit;
}

.lt-btn:hover { transform: translateY(-1px); }

.lt-btn-primary {
    background: var(--lt-flame);
    color: var(--lt-parchment);
}
.lt-btn-primary:hover { background: var(--lt-flame-bright); }

.lt-btn-outline {
    border-color: var(--lt-line);
    color: var(--lt-parchment);
    background: transparent;
}
.lt-btn-outline:hover { border-color: var(--lt-gold); color: var(--lt-gold); }

.lt-btn-gold {
    background: var(--lt-gold);
    color: var(--lt-ink);
}
.lt-btn-gold:hover { background: #E8B85A; }

/* ---------- Enrolled ribbon ---------- */
.lt-enrolled {
    position: absolute;
    top: 14px;
    left: 14px;
    background: var(--lt-gold);
    color: var(--lt-ink);
    padding: 4px 11px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    z-index: 2;
}

/* ---------- Empty state ---------- */
.lt-empty-state {
    text-align: center;
    padding: 72px 32px;
    background: var(--lt-charcoal);
    border: 1px dashed var(--lt-line);
    border-radius: 14px;
}

.lt-empty-state .lt-empty-icon {
    font-size: 3rem;
    margin-bottom: 18px;
    opacity: 0.6;
}

.lt-empty-state h3 {
    font-size: 1.35rem;
    margin: 0 0 10px;
    color: var(--lt-parchment);
}

.lt-empty-state p {
    color: var(--lt-parchment-dim);
    margin: 0 0 26px;
    font-size: 0.95rem;
}

/* ---------- Pagination ---------- */
.lt-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 48px;
    flex-wrap: wrap;
}

.lt-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    background: var(--lt-charcoal);
    border: 1px solid var(--lt-line);
    border-radius: 8px;
    color: var(--lt-parchment-dim);
    font-size: 0.88rem;
    font-weight: 500;
    transition: all 0.15s ease;
}

.lt-page-link:hover {
    border-color: var(--lt-gold);
    color: var(--lt-gold);
    transform: translateY(-1px);
}

.lt-page-link.lt-active {
    background: var(--lt-flame);
    border-color: var(--lt-flame);
    color: var(--lt-parchment);
}

.lt-page-link.lt-disabled {
    opacity: 0.4;
    pointer-events: none;
}

.lt-page-ellipsis {
    color: var(--lt-parchment-dim);
    padding: 0 4px;
    font-size: 0.9rem;
}

/* ---------- CTA section ---------- */
.lt-cta {
    background: linear-gradient(135deg, var(--lt-flame-dark), var(--lt-flame));
    border-radius: 14px;
    padding: 56px 40px;
    text-align: center;
    margin-top: 72px;
}

.lt-cta h2 {
    font-size: 1.7rem;
    color: var(--lt-parchment);
    margin: 0 0 10px;
}

.lt-cta p {
    color: rgba(241, 231, 214, 0.85);
    margin: 0 0 26px;
    font-size: 1rem;
}

.lt-cta .lt-btn {
    background: var(--lt-parchment);
    color: var(--lt-flame-dark);
    padding: 13px 30px;
    font-size: 0.95rem;
}
.lt-cta .lt-btn:hover { background: #fff; }

/* ---------- Responsive ---------- */
@media (max-width: 900px) {
    .lt-layout {
        grid-template-columns: 1fr;
        gap: 28px;
    }
    .lt-filter {
        position: static;
    }
    .lt-header {
        padding: 56px 0 44px;
    }
    .lt-grid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    }
}

@media (max-width: 560px) {
    .lt-grid {
        grid-template-columns: 1fr;
    }
    .lt-cta {
        padding: 36px 22px;
    }
    .lt-class-foot {
        flex-direction: column;
        align-items: stretch;
    }
    .lt-class-foot .lt-btn {
        text-align: center;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lt-eyebrow-dot,
    .lt-status-ongoing .lt-status-dot {
        animation: none;
    }
}
</style>

<main class="lt-page">
    <!-- Page Header -->
    <section class="lt-header">
        <div class="lt-container lt-header-inner">
            <span class="lt-eyebrow"><span class="lt-eyebrow-dot"></span> Class catalogue</span>
            <h1>Browse live classes.</h1>
            <p>Interactive sessions taught by expert South African educators. Filter by level, subject, or price to find your fit.</p>
        </div>
    </section>

    <div class="lt-container">
        <div class="lt-layout">
            
            <!-- Filters Sidebar -->
            <aside>
                <div class="lt-filter">
                    <h2>🔍 Filter classes</h2>
                    
                    <form method="get" action="" id="filterForm">
                        <div class="lt-field">
                            <label class="lt-label" for="search">Search</label>
                            <input type="text" id="search" name="search" placeholder="Search courses..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="lt-field">
                            <label class="lt-label" for="level">Level</label>
                            <select id="level" name="level" onchange="this.form.submit()">
                                <option value="">All levels</option>
                                <option value="beginner" <?php echo $level == 'beginner' ? 'selected' : ''; ?>>🌱 Beginner</option>
                                <option value="intermediate" <?php echo $level == 'intermediate' ? 'selected' : ''; ?>>📘 Intermediate</option>
                                <option value="advanced" <?php echo $level == 'advanced' ? 'selected' : ''; ?>>🎓 Advanced</option>
                            </select>
                        </div>
                        
                        <div class="lt-field">
                            <label class="lt-label" for="category">Category</label>
                            <select id="category" name="category" onchange="this.form.submit()">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category == $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="lt-field">
                            <label class="lt-label">Price range</label>
                            <div class="lt-radio-group">
                                <label class="lt-radio">
                                    <input type="radio" name="price_range" value="" onchange="this.form.submit()" <?php echo empty($price_range) ? 'checked' : ''; ?>>
                                    <span>All prices</span>
                                </label>
                                <label class="lt-radio">
                                    <input type="radio" name="price_range" value="free" onchange="this.form.submit()" <?php echo $price_range == 'free' ? 'checked' : ''; ?>>
                                    <span>🎁 Free</span>
                                </label>
                                <label class="lt-radio">
                                    <input type="radio" name="price_range" value="under_500" onchange="this.form.submit()" <?php echo $price_range == 'under_500' ? 'checked' : ''; ?>>
                                    <span>Under R500</span>
                                </label>
                                <label class="lt-radio">
                                    <input type="radio" name="price_range" value="500_1000" onchange="this.form.submit()" <?php echo $price_range == '500_1000' ? 'checked' : ''; ?>>
                                    <span>R500 – R1000</span>
                                </label>
                                <label class="lt-radio">
                                    <input type="radio" name="price_range" value="above_1000" onchange="this.form.submit()" <?php echo $price_range == 'above_1000' ? 'checked' : ''; ?>>
                                    <span>Above R1000</span>
                                </label>
                            </div>
                        </div>
                        
                        <?php if ($search || $level || $category || $price_range): ?>
                            <a href="index.php" class="lt-clear">✖ Clear all filters</a>
                        <?php endif; ?>
                    </form>
                    
                    <div class="lt-filter-tip">
                        <p><strong>💡 Tip:</strong> Look for classes marked "Live now" to join immediately.</p>
                        <p><strong>🎁 Free previews</strong> available on many classes.</p>
                    </div>
                </div>
            </aside>
            
            <!-- Classes Grid -->
            <div>
                <!-- Results Bar -->
                <div class="lt-results-bar">
                    <span class="lt-count">
                        <strong><?php echo $totalClasses; ?></strong>
                        class<?php echo $totalClasses != 1 ? 'es' : ''; ?> found
                        <?php if ($search): ?>
                            for <em>"<?php echo htmlspecialchars($search); ?>"</em>
                        <?php endif; ?>
                    </span>
                </div>
                
                <?php if (empty($classes)): ?>
                    <div class="lt-empty-state">
                        <div class="lt-empty-icon">📚</div>
                        <h3>No classes found</h3>
                        <p>Try adjusting your search or filter criteria.</p>
                        <a href="index.php" class="lt-btn lt-btn-primary">Browse all classes</a>
                    </div>
                <?php else: ?>
                    <div class="lt-grid">
                        <?php foreach ($classes as $class): 
                            $isEnrolled = in_array($class['id'], $enrolledIds);
                            $statusClass = '';
                            $statusText = '';
                            
                            if ($class['status'] == 'upcoming') {
                                $statusClass = 'lt-status-upcoming';
                                $statusText = 'Upcoming';
                            } elseif ($class['status'] == 'ongoing') {
                                $statusClass = 'lt-status-ongoing';
                                $statusText = 'Live now';
                            } else {
                                $statusClass = 'lt-status-completed';
                                $statusText = 'Completed';
                            }
                            
                            $enrolledPercent = ($class['current_students'] / max(1, $class['max_students'])) * 100;
                            $isAlmostFull = $enrolledPercent >= 80;
                            
                            // Category icon
                            $icon = '🎓';
                            if ($class['category'] == 'Mathematics') $icon = '📐';
                            elseif ($class['category'] == 'Languages') $icon = '📖';
                            elseif ($class['category'] == 'Science') $icon = '🔬';
                        ?>
                            <article class="lt-class">
                                <?php if ($isEnrolled): ?>
                                    <div class="lt-enrolled">✓ Enrolled</div>
                                <?php endif; ?>
                                
                                <div class="lt-class-top">
                                    <div class="lt-class-icon"><?php echo $icon; ?></div>
                                    <span class="lt-status <?php echo $statusClass; ?>">
                                        <?php if ($class['status'] == 'ongoing'): ?>
                                            <span class="lt-status-dot"></span>
                                        <?php endif; ?>
                                        <?php echo $statusText; ?>
                                    </span>
                                </div>
                                
                                <div class="lt-class-body">
                                    <div class="lt-class-date">
                                        📅 <?php echo date('M d, Y • H:i', strtotime($class['start_date'])); ?>
                                    </div>
                                    
                                    <h3><?php echo htmlspecialchars($class['title']); ?></h3>
                                    
                                    <p class="lt-class-desc">
                                        <?php echo htmlspecialchars(substr($class['short_description'] ?? $class['description'], 0, 90)); ?>...
                                    </p>
                                    
                                    <p class="lt-class-teacher">
                                        👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?>
                                    </p>
                                    
                                    <p class="lt-class-meta">
                                        👥 <?php echo $class['enrolled_count']; ?>/<?php echo $class['max_students']; ?> enrolled
                                        <?php if ($isAlmostFull && !$isEnrolled && $class['status'] != 'completed'): ?>
                                            <span class="lt-warn">⚠️ Almost full</span>
                                        <?php endif; ?>
                                    </p>
                                    
                                    <div class="lt-class-foot">
                                        <div>
                                            <?php if ($class['price'] > 0): ?>
                                                <span class="lt-price-tag">R <?php echo number_format($class['price'], 2); ?><small>ZAR</small></span>
                                            <?php else: ?>
                                                <span class="lt-free-tag">🎁 Free</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($isEnrolled): ?>
                                            <a href="../dashboard/my-classes.php" class="lt-btn lt-btn-gold">
                                                Continue →
                                            </a>
                                        <?php elseif ($class['status'] == 'ongoing'): ?>
                                            <a href="class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-primary">
                                                Join now →
                                            </a>
                                        <?php else: ?>
                                            <a href="class.php?id=<?php echo $class['id']; ?>" class="lt-btn lt-btn-outline">
                                                View details →
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="lt-pagination">
                            <?php 
                            $queryString = '&search=' . urlencode($search) . '&level=' . urlencode($level) . '&category=' . urlencode($category) . '&price_range=' . urlencode($price_range);
                            ?>
                            
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $queryString; ?>" class="lt-page-link">←</a>
                            <?php else: ?>
                                <span class="lt-page-link lt-disabled">←</span>
                            <?php endif; ?>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                                <a href="?page=1<?php echo $queryString; ?>" class="lt-page-link">1</a>
                                <?php if ($startPage > 2): ?><span class="lt-page-ellipsis">…</span><?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $queryString; ?>" 
                                   class="lt-page-link <?php echo $i == $page ? 'lt-active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><span class="lt-page-ellipsis">…</span><?php endif; ?>
                                <a href="?page=<?php echo $totalPages; ?><?php echo $queryString; ?>" class="lt-page-link">
                                    <?php echo $totalPages; ?>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $queryString; ?>" class="lt-page-link">→</a>
                            <?php else: ?>
                                <span class="lt-page-link lt-disabled">→</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Call to Action for Non-Logged Users -->
        <?php if (!isset($_SESSION['user']) && !empty($classes)): ?>
            <div class="lt-cta">
                <h2>Ready to start learning?</h2>
                <p>Join thousands of South African students learning online with LiveTeach SA.</p>
                <a href="../register.php" class="lt-btn">Sign up free →</a>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
// Auto-submit form when search input has enter key
document.getElementById('search')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('filterForm').submit();
    }
});

// Add loading effect on filter change
document.querySelectorAll('select, input[type="radio"]').forEach(el => {
    el.addEventListener('change', function() {
        if (this.form) {
            this.form.style.opacity = '0.5';
            this.form.submit();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>