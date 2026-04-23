<?php
include __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../config/config.php';

wingmate_start_secure_session();

// Check for session idle timeout (30 minutes)
if (!wingmate_check_session_idle_timeout(1800)) {
    header('Location: /features/auth/login.php?reason=session_expired');
    exit;
}

// Gets current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Redirects to login if not logged in
if (!$current_user_id) {
    header('Location: /features/auth/login.php');
    exit;
}

// Initialize message variables
$voteSuccess = '';
$voteError   = '';

// Retrieve any stored messages from session
if (isset($_SESSION['vote_success'])) {
    $voteSuccess = (string) $_SESSION['vote_success'];
    unset($_SESSION['vote_success']);
}

if (isset($_SESSION['vote_error'])) {
    $voteError = (string) $_SESSION['vote_error'];
    unset($_SESSION['vote_error']);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token before processing POST requests
    if (!wingmate_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $_SESSION['vote_error'] = 'Session validation failed. Please refresh and try again.';
        header('Location: /features/vote/vote.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'cast_vote' && !empty($_POST['request_id'])) {
        $request_id = (int) $_POST['request_id'];
        $vote_type  = ($_POST['vote_type'] ?? '') === 'approve' ? 1 : 0;

        try {
            $stmt = $conn->prepare("INSERT IGNORE INTO Friend_Votes (request_id, friend_voter_id, vote_type) VALUES (?, ?, ?)");
            $stmt->bind_param('iii', $request_id, $current_user_id, $vote_type);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "SELECT mr.match_owner_id, mr.matched_user_id,
                        COUNT(fv.friend_voter_id) AS total_votes,
                        COALESCE(SUM(fv.vote_type), 0) AS approved
                 FROM Match_Requests mr
                 LEFT JOIN Friend_Votes fv ON fv.request_id = mr.request_id
                 WHERE mr.request_id = ?
                 GROUP BY mr.request_id"
            );
            $stmt->bind_param('i', $request_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $ownerId   = (int)$r['match_owner_id'];
            $matchedId = (int)$r['matched_user_id'];
            $total     = (int)$r['total_votes'];
            $approved  = (int)$r['approved'];

            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM Friendship WHERE status='accepted' AND (user_id = ? OR friend_id = ?)");
            $stmt->bind_param('ii', $ownerId, $ownerId);
            $stmt->execute();
            $friendCount = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            $pct      = $friendCount > 0 ? (int) round(($approved / $friendCount) * 100) : 0;
            $is_match = $pct >= 40 ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO Decision (match_request_id, total_votes, approval_votes, approval_percentage, is_match)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE total_votes = ?, approval_votes = ?, approval_percentage = ?, is_match = ?");
            $stmt->bind_param('iiidiiiid', $request_id, $total, $approved, $pct, $is_match, $total, $approved, $pct, $is_match);
            $stmt->execute();
            $stmt->close();

            if ($is_match === 1) {
                $stmt = $conn->prepare(
                    "SELECT mr.request_id, d.is_match
                     FROM Match_Requests mr
                     LEFT JOIN Decision d ON d.match_request_id = mr.request_id
                     WHERE mr.match_owner_id = ? AND mr.matched_user_id = ?"
                );
                $stmt->bind_param('ii', $matchedId, $ownerId);
                $stmt->execute();
                $reciprocal = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($reciprocal && (int)$reciprocal['is_match'] === 1) {
                    $user1 = min($ownerId, $matchedId);
                    $user2 = max($ownerId, $matchedId);

                    $stmt = $conn->prepare("SELECT match_id, status FROM Matches WHERE user1_id = ? AND user2_id = ?");
                    $stmt->bind_param('ii', $user1, $user2);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($existing && $existing['status'] !== 'active') {
                        $matchId = (int)$existing['match_id'];

                        $stmt = $conn->prepare("UPDATE Matches SET status = 'active', matched_at = NOW() WHERE match_id = ?");
                        $stmt->bind_param('i', $matchId);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("UPDATE Match_Requests SET status = 'approved', closed_at = NOW() WHERE request_id IN (?, ?)");
                        $stmt->bind_param('ii', $request_id, $reciprocal['request_id']);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("INSERT INTO Notifications (recipient_id, notification_type, reference_type, reference_id, created_at) VALUES (?, 'match_approved', 'match', ?, NOW())");
                        $stmt->bind_param('ii', $user1, $matchId);
                        $stmt->execute();
                        $stmt->bind_param('ii', $user2, $matchId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $_SESSION['vote_success'] = $vote_type === 1 ? 'You approved this match!' : 'You disliked this match.';
        } catch (Exception $e) {
            $_SESSION['vote_error'] = 'An error occurred while casting your vote. Please try again.';
        }
    }

    header('Location: /features/vote/vote.php');
    exit;
}

include __DIR__ . '/../../includes/nav-header.php';

// Fetch my pending match votes (matches I own still being voted on)
$myVotes = [];
try {
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
} catch (Exception $e) {
    $voteError = 'An error occurred while loading your match votes.';
}

// Fetch vote requests sent to me that I haven't voted on yet
$friendVotes = [];
try {
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
} catch (Exception $e) {
    $voteError = 'An error occurred while loading vote requests.';
}
?>

<link rel="stylesheet" href="./vote.css">

<div class="container-fluid">
    <?php if (!empty($voteSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($voteSuccess, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($voteError)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($voteError, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 vote-page">

        <!-- Left Panel: My Match Votes -->
        <div class="col-md-6">
            <div class="vote-panel vote-panel--pink">
                <div class="vote-panel__inner">
                    <h2 class="vote-panel__title">My Match Votes</h2>

                    <?php if (empty($myVotes)): ?>
                        <p class="empty-state-message">You have no pending match votes right now.</p>
                    <?php endif; ?>

                    <?php foreach ($myVotes as $v):
                        $pct      = (int) $v['approval_percentage'];
                        $hasVotes = (int) $v['total_votes'] > 0;
                    ?>
                    <div class="vote-card" id="match-<?php echo (int) $v['request_id']; ?>">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($v['photo_url']): ?>
                                <img src="/Uploads/<?php echo htmlspecialchars($v['photo_url']); ?>" class="vote-avatar" alt="">
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
                        <small class="text-muted"><?php echo (int) $v['total_votes']; ?> friend<?php echo (int) $v['total_votes'] !== 1 ? 's have' : ' has'; ?> voted</small>

                        <?php if ($hasVotes): ?>
                            <div class="vote-progress mt-1 mb-1">
                                <div class="vote-progress__fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <small class="text-muted d-block"><?php echo $pct; ?>% approval &middot; need 40% to match</small>
                        <?php else: ?>
                            <small class="text-muted d-block mt-1">Waiting on your friends!</small>
                        <?php endif; ?>
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
                        <p class="empty-state-message">No pending vote requests right now.</p>
                    <?php endif; ?>

                    <?php foreach ($friendVotes as $v): ?>
                    <div class="vote-card">
                        <!-- Requester -->
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($v['req_photo']): ?>
                                <img src="/Uploads/<?php echo htmlspecialchars($v['req_photo']); ?>" class="vote-avatar" alt="">
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
                                <img src="/Uploads/<?php echo htmlspecialchars($v['cand_photo']); ?>" class="vote-avatar" alt="">
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
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(wingmate_get_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="cast_vote">
                            <input type="hidden" name="request_id" value="<?php echo (int) $v['request_id']; ?>">
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
