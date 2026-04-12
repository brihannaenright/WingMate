<?php
include __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not logged in
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Cast a vote (1 = approve, 0 = dislike)
    if ($action === 'cast_vote' && !empty($_POST['request_id'])) {
        $request_id = (int) $_POST['request_id'];
        $vote_type  = $_POST['vote_type'] === 'approve' ? 1 : 0;

        $stmt = $conn->prepare("INSERT IGNORE INTO Friend_Votes (request_id, friend_voter_id, vote_type) VALUES (?, ?, ?)");
        $stmt->bind_param('iii', $request_id, $current_user_id, $vote_type);
        $stmt->execute();
        $stmt->close();

        // Recalculate Decision totals
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(vote_type) AS approved FROM Friend_Votes WHERE request_id = ?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $d = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $total    = (int) $d['total'];
        $approved = (int) $d['approved'];
        $pct      = $total > 0 ? (int) round(($approved / $total) * 100) : 0;
        $is_match = $pct >= 50 ? 1 : 0;

        $stmt = $conn->prepare("INSERT INTO Decision (match_request_id, total_votes, approval_votes, approval_percentage, is_match)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE total_votes = ?, approval_votes = ?, approval_percentage = ?, is_match = ?");
        $stmt->bind_param('iiidiiiid', $request_id, $total, $approved, $pct, $is_match, $total, $approved, $pct, $is_match);
        $stmt->execute();
        $stmt->close();
    }

    // Skip match — mark as approved directly (Premium)
    if ($action === 'skip_match' && !empty($_POST['request_id'])) {
        $request_id = (int) $_POST['request_id'];
        $stmt = $conn->prepare("UPDATE Match_Requests SET status = 'approved', closed_at = NOW() WHERE request_id = ? AND match_owner_id = ?");
        $stmt->bind_param('ii', $request_id, $current_user_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: /features/vote/vote.php');
    exit;
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch my pending match votes (matches I own that are still being voted on)
$stmt = $conn->prepare("
    SELECT mr.request_id, mr.matched_user_id,
           up.first_name, up.last_name, p.photo_url,
           COALESCE(d.total_votes, 0) AS total_votes,
           COALESCE(d.approval_percentage, 0) AS approval_percentage
    FROM Match_Requests mr
    JOIN User_Profile up ON up.user_id = mr.matched_user_id
    LEFT JOIN User_Pictures p ON p.user_id = mr.matched_user_id AND p.is_primary = 1 AND p.is_removed = 0
    LEFT JOIN Decision d ON d.match_request_id = mr.request_id
    WHERE mr.match_owner_id = ? AND mr.status = 'pending'
    ORDER BY mr.created_at DESC
");
$stmt->bind_param('i', $current_user_id);
$stmt->execute();
$myVotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch vote requests sent to me that I haven't voted on yet
$stmt = $conn->prepare("
    SELECT n.reference_id AS request_id,
           mr.match_owner_id,
           req.first_name AS req_first, req.last_name AS req_last, req_p.photo_url AS req_photo,
           cand.first_name AS cand_first, cand.last_name AS cand_last, cand_p.photo_url AS cand_photo
    FROM Notifications n
    JOIN Match_Requests mr ON mr.request_id = n.reference_id
    JOIN User_Profile req ON req.user_id = mr.match_owner_id
    LEFT JOIN User_Pictures req_p ON req_p.user_id = mr.match_owner_id AND req_p.is_primary = 1 AND req_p.is_removed = 0
    JOIN User_Profile cand ON cand.user_id = mr.matched_user_id
    LEFT JOIN User_Pictures cand_p ON cand_p.user_id = mr.matched_user_id AND cand_p.is_primary = 1 AND cand_p.is_removed = 0
    LEFT JOIN Friend_Votes fv ON fv.request_id = n.reference_id AND fv.friend_voter_id = ?
    WHERE n.recipient_id = ? AND n.notification_type = 'vote_request'
      AND n.reference_type = 'match_request' AND mr.status = 'pending'
      AND fv.friend_voter_id IS NULL
    ORDER BY n.created_at DESC
");
$stmt->bind_param('ii', $current_user_id, $current_user_id);
$stmt->execute();
$friendVotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<link rel="stylesheet" href="./vote.css">

<div class="container-fluid">
    <div class="row g-4 vote-page">

        <!-- Left Panel: My Match Votes -->
        <div class="col-md-6">
            <div class="vote-panel vote-panel--pink">
                <div class="vote-panel__inner">
                    <h2 class="vote-panel__title">My Match Votes</h2>

                    <?php if (empty($myVotes)): ?>
                        <p class="vote-empty">You have no pending match votes right now.</p>
                    <?php endif; ?>

                    <?php foreach ($myVotes as $v):
                        $pct     = (int) $v['approval_percentage'];
                        $hasVotes = (int) $v['total_votes'] > 0;
                    ?>
                    <div class="vote-card" id="match-<?php echo $v['request_id']; ?>">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($v['photo_url']): ?>
                                <img src="/assets/images/<?php echo htmlspecialchars($v['photo_url']); ?>" class="vote-avatar" alt="">
                            <?php else: ?>
                                <div class="vote-avatar vote-avatar--pink"><?php echo htmlspecialchars(strtoupper($v['first_name'][0])); ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="vote-name"><?php echo htmlspecialchars($v['first_name'] . ' ' . $v['last_name']); ?></p>
                                <small class="text-primary">View Profile &middot; See Comments</small>
                            </div>
                        </div>

                        <hr class="my-2">
                        <p class="vote-stats-label">What Friends Think So Far:</p>
                        <small class="text-muted"><?php echo $v['total_votes']; ?> friend<?php echo $v['total_votes'] != 1 ? 's have' : ' has'; ?> voted</small>

                        <?php if ($hasVotes): ?>
                            <div class="vote-progress mt-1 mb-1">
                                <div class="vote-progress__fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <small class="text-muted d-block"><?php echo $pct; ?>% approve &middot; <?php echo 100 - $pct; ?>% dislike</small>
                        <?php else: ?>
                            <small class="text-muted d-block mt-1">Waiting on your friends!</small>
                        <?php endif; ?>

                        <form method="POST" class="mt-2" onsubmit="animateOut(<?php echo $v['request_id']; ?>)">
                            <input type="hidden" name="action" value="skip_match">
                            <input type="hidden" name="request_id" value="<?php echo $v['request_id']; ?>">
                            <button type="submit" class="button-secondary w-100">⚡ Skip votes and match anyway with Premium!</button>
                        </form>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>

        <!-- Right Panel: Vote for Friends -->
        <div class="col-md-6">
            <div class="vote-panel vote-panel--orange">
                <div class="vote-panel__inner">
                    <h2 class="vote-panel__title">Vote for Friends</h2>

                    <?php if (empty($friendVotes)): ?>
                        <p class="vote-empty">No pending vote requests right now.</p>
                    <?php endif; ?>

                    <?php foreach ($friendVotes as $v): ?>
                    <div class="vote-card">
                        <!-- Requester -->
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($v['req_photo']): ?>
                                <img src="/assets/images/<?php echo htmlspecialchars($v['req_photo']); ?>" class="vote-avatar" alt="">
                            <?php else: ?>
                                <div class="vote-avatar vote-avatar--orange"><?php echo htmlspecialchars(strtoupper($v['req_first'][0])); ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="vote-name"><?php echo htmlspecialchars($v['req_first'] . ' ' . $v['req_last']); ?></p>
                                <small class="text-muted"><?php echo htmlspecialchars($v['req_first']); ?> has requested your vote on a match!</small>
                            </div>
                        </div>

                        <!-- Candidate -->
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($v['cand_photo']): ?>
                                <img src="/assets/images/<?php echo htmlspecialchars($v['cand_photo']); ?>" class="vote-avatar" alt="">
                            <?php else: ?>
                                <div class="vote-avatar vote-avatar--pink"><?php echo htmlspecialchars(strtoupper($v['cand_first'][0])); ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="vote-name"><?php echo htmlspecialchars($v['cand_first'] . ' ' . $v['cand_last']); ?></p>
                                <small class="text-primary">View Profile</small>
                            </div>
                        </div>

                        <!-- Vote buttons -->
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="action" value="cast_vote">
                            <input type="hidden" name="request_id" value="<?php echo $v['request_id']; ?>">
                            <button type="submit" name="vote_type" value="approve" class="button-primary w-50">✓ Approve</button>
                            <button type="submit" name="vote_type" value="dislike" class="button-secondary w-50">✕ Dislike</button>
                        </form>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
function animateOut(id) {
    const card = document.getElementById('match-' + id);
    if (card) { card.style.opacity = '0'; card.style.transform = 'translateX(30px)'; }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
