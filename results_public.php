<?php

require_once __DIR__ . '/includes/functions.php';

$pollId = (int)($_GET['poll_id'] ?? 0);

$poll = get_poll_by_id($pollId);

if (!$poll) {
    redirect('poll.php');
}

$settings = get_poll_settings($pollId);

if (empty($settings['show_results'])) {
    http_response_code(403);
    exit('Hasil polling belum dibuka untuk publik.');
}

$isQuestionnaire = ($poll['poll_type'] ?? '') === 'questionnaire';

$rows = $isQuestionnaire
    ? []
    : get_public_poll_result_rows($pollId);

$qRows = $isQuestionnaire
    ? get_questionnaire_result_rows($pollId)
    : [];

$totalVotes = 0;

foreach ($rows as $r) {
    $totalVotes += (int)$r['vote_count'];
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Hasil - <?= e($poll['title']) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <script src="assets/js/theme.js"></script>

</head>


<body>

<div class="wrap wide">

    <div class="topbar">

        <h1>
            Hasil Polling
        </h1>

        <p>
            <?= e($poll['title']) ?>
        </p>

    </div>


    <?php if ($isQuestionnaire): ?>

        <?php if (!$qRows): ?>

            <div class="card">
                <p class="muted">
                    Belum ada hasil.
                </p>
            </div>

        <?php endif; ?>


        <?php foreach ($qRows as $i => $q): ?>

            <?php

            $questionTotal = 0;

            foreach ($q['options'] as $o) {
                $questionTotal += (int)$o['vote_count'];
            }

            ?>


            <div class="card">

                <h3 style="margin-top:0">

                    <?= $i + 1 ?>.
                    <?= e($q['question']) ?>

                </h3>


                <?php foreach ($q['options'] as $o): ?>

                    <?php

                    $voteCount = (int)$o['vote_count'];

                    $percentage = $questionTotal > 0
                        ? round(
                            ($voteCount / $questionTotal) * 100,
                            1
                        )
                        : 0;

                    ?>


                    <div class="chart-row">

                        <div class="chart-label">
                            <?= e($o['option_text']) ?>
                        </div>


                        <div class="chart-track">

                            <div
                                class="chart-fill"
                                style="width: <?= max(
                                    $percentage,
                                    $voteCount > 0 ? 4 : 0
                                ) ?>%;"
                            >
                                <?= $percentage ?>%
                            </div>

                        </div>


                        <div class="chart-count">
                            <?= $voteCount ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endforeach; ?>


    <?php else: ?>

        <!-- =========================
             HASIL POLLING FOTO
        ========================== -->

        <div class="card photo-result-public">

            <div class="public-result-header">

                <div>

                    <div class="public-result-kicker">
                        HASIL POLLING
                    </div>

                    <h2>
                        <?= e($poll['title']) ?>
                    </h2>

                </div>


                <div class="public-result-total">

                    <span>
                        Total Vote
                    </span>

                    <strong>
                        <?= $totalVotes ?>
                    </strong>

                </div>

            </div>


            <?php if (!$rows): ?>

                <p class="muted">
                    Belum ada hasil.
                </p>

            <?php else: ?>

                <div class="public-photo-results">

                    <?php

                    $position = 0;
                    $rank = 0;
                    $lastVoteCount = null;

                    ?>


                    <?php foreach ($rows as $r): ?>

                        <?php

                        $position++;

                        $voteCount = (int)$r['vote_count'];

                        if (
                            $lastVoteCount === null ||
                            $voteCount < $lastVoteCount
                        ) {
                            $rank = $position;
                        }

                        $lastVoteCount = $voteCount;

                        $percentage = $totalVotes > 0
                            ? round(
                                ($voteCount / $totalVotes) * 100,
                                1
                            )
                            : 0;

                        $imageUrl = trim(
                            (string)($r['image_url'] ?? '')
                        );

                        $isWinner =
                            $rank === 1 &&
                            $voteCount > 0;

                        ?>


                        <div
                            class="public-photo-result <?= $isWinner
                                ? 'public-photo-result-winner'
                                : '' ?>"
                        >

                            <div class="public-photo-rank">

                                <?php if ($rank === 1): ?>

                                    🥇

                                <?php elseif ($rank === 2): ?>

                                    🥈

                                <?php elseif ($rank === 3): ?>

                                    🥉

                                <?php else: ?>

                                    #<?= $rank ?>

                                <?php endif; ?>

                            </div>


                            <div class="public-photo-image">

                                <?php if ($imageUrl !== ''): ?>

                                    <img
                                        src="<?= e($imageUrl) ?>"
                                        alt="<?= e($r['name']) ?>"
                                        loading="lazy"
                                    >

                                <?php else: ?>

                                    <div class="public-photo-placeholder">
                                        📷
                                    </div>

                                <?php endif; ?>

                            </div>


                            <div class="public-photo-info">

                                <div class="public-photo-name">

                                    <div>

                                        <strong>
                                            <?= e($r['name']) ?>
                                        </strong>


                                        <?php if ($isWinner): ?>

                                            <span class="public-winner-badge">
                                                SUARA TERBANYAK
                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <div class="public-photo-count">

                                        <strong>
                                            <?= $voteCount ?>
                                        </strong>

                                        <span>
                                            vote
                                        </span>

                                    </div>

                                </div>


                                <div class="public-photo-percent">

                                    <?= $percentage ?>%

                                </div>


                                <div class="public-photo-track">

                                    <div
                                        class="public-photo-fill"
                                        style="width: <?= max(
                                            $percentage,
                                            $voteCount > 0 ? 3 : 0
                                        ) ?>%;"
                                    ></div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <div class="card center">

        <a
            href="poll.php"
            class="btn secondary"
        >
            Kembali
        </a>

    </div>

</div>

</body>

</html>
