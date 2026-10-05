<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/includes/content.php';
$pdo=get_db();
$pageTitle='Hasil Polling NEXADIPTA 21';
$activePage='results';
$polls=$pdo->query('SELECT id,title,poll_type FROM polls ORDER BY id DESC')->fetchAll();
$results=[];
foreach($polls as $p){
    $settings=get_poll_settings((int)$p['id']);
    if(empty($settings['show_results'])) continue;
    $results[]=[
        'poll'=>$p,
        'rows'=>$p['poll_type']==='questionnaire'
            ? get_questionnaire_result_rows((int)$p['id'])
            : get_public_poll_result_rows((int)$p['id'])
    ];
}
include __DIR__.'/includes/header.php';
?>
<section class="page-hero">
    <div class="container">
        <span class="section-kicker">HASIL POLLING</span>
        <h1>Hasil dapat dilihat tanpa login.</h1>
        <p>Hanya polling yang sudah dibuka untuk publik oleh panitia yang ditampilkan.</p>
    </div>
</section>
<section class="section">
    <div class="container results-list">
        <?php if(!$results): ?>
            <div class="empty-card">Belum ada hasil polling yang dibuka untuk publik.</div>
        <?php endif; ?>
        <?php foreach($results as $item):
            $p=$item['poll'];
            $rows=$item['rows'];
        ?>
            <?php if($p['poll_type']==='questionnaire'): ?>
                <article class="result-card questionnaire-result-card">
                    <span class="poll-status">QUESTIONNAIRE</span>
                    <h3><?=e($p['title'])?></h3>
                    <?php foreach($rows as $q):
                        $questionTotal=0;
                        foreach($q['options'] as $o) $questionTotal+=(int)$o['vote_count'];
                        $position=0;
                        $lastVoteCount=null;
                        $rank=0;
                    ?>
                        <div class="mini-question">
                            <h4><?=e($q['question'])?></h4>
                            <div class="question-result-list">
                                <?php foreach($q['options'] as $o):
                                    $position++;
                                    $voteCount=(int)$o['vote_count'];
                                    if($lastVoteCount===null||$voteCount<$lastVoteCount) $rank=$position;
                                    $lastVoteCount=$voteCount;
                                    $percentage=$questionTotal>0?round(($voteCount/$questionTotal)*100,1):0;
                                    $imageUrl=trim((string)($o['image_url']??''));
                                    $isWinner=$rank===1&&$voteCount>0;
                                ?>
                                    <div class="question-option-result">
                                        <div class="question-option-rank">
                                            <?php if($rank===1): ?>🥇<?php elseif($rank===2): ?>🥈<?php elseif($rank===3): ?>🥉<?php else: ?>#<?=$rank?><?php endif; ?>
                                        </div>
                                        <div class="question-option-photo">
                                            <?php if($imageUrl!==''): ?>
                                                <img src="<?=e($imageUrl)?>" alt="<?=e($o['option_text'])?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                                <div class="question-option-no-photo" style="display:none">📷</div>
                                            <?php else: ?>
                                                <div class="question-option-no-photo">📷</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="question-option-info">
                                            <div class="question-option-heading">
                                                <strong><?=e($o['option_text'])?></strong>
                                                <?php if($isWinner): ?><span class="question-winner">TERBANYAK</span><?php endif; ?>
                                            </div>
                                            <div class="question-option-votes">
                                                <strong><?=$voteCount?></strong>
                                                <span>vote</span>
                                                <span>•</span>
                                                <span><?=$percentage?>%</span>
                                            </div>
                                            <div class="chart-track">
                                                <div class="chart-fill" style="width:<?=max($percentage,$voteCount>0?4:0)?>%"><?=$percentage?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </article>
            <?php else:
                $total=0;
                foreach($rows as $r) $total+=(int)$r['vote_count'];
                $position=0;
                $lastVoteCount=null;
                $rank=0;
            ?>
                <article class="result-card photo-result-card">
                    <div class="result-card-heading">
                        <div>
                            <span class="poll-status">HASIL POLLING</span>
                            <h3><?=e($p['title'])?></h3>
                        </div>
                        <div class="result-total-badge">Total Vote <strong><?=$total?></strong></div>
                    </div>
                    <?php if(!$rows): ?>
                        <div class="empty-card">Belum ada kandidat pada polling ini.</div>
                    <?php else: ?>
                        <div class="photo-results-list">
                            <?php foreach($rows as $r):
                                $position++;
                                $voteCount=(int)$r['vote_count'];
                                if($lastVoteCount===null||$voteCount<$lastVoteCount) $rank=$position;
                                $lastVoteCount=$voteCount;
                                $percentage=$total>0?round(($voteCount/$total)*100,1):0;
                                $imageUrl=trim((string)($r['image_url']??''));
                                $isFirst=$rank===1&&$voteCount>0;
                            ?>
                                <div class="photo-result-row <?=$isFirst?'photo-result-winner':''?>">
                                    <div class="photo-result-rank">
                                        <?php if($rank===1): ?>🥇<?php elseif($rank===2): ?>🥈<?php elseif($rank===3): ?>🥉<?php else: ?>#<?=$rank?><?php endif; ?>
                                    </div>
                                    <div class="photo-result-image">
                                        <?php if($imageUrl!==''): ?>
                                            <img src="<?=e($imageUrl)?>" alt="<?=e($r['name'])?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <div class="photo-result-placeholder" style="display:none">📷</div>
                                        <?php else: ?>
                                            <div class="photo-result-placeholder">📷</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="photo-result-content">
                                        <div class="photo-result-title">
                                            <div>
                                                <h4><?=e($r['name'])?></h4>
                                                <?php if($isFirst): ?><span class="winner-badge">TERBANYAK</span><?php endif; ?>
                                            </div>
                                            <div class="photo-result-votes">
                                                <strong><?=$voteCount?></strong>
                                                <span>vote</span>
                                            </div>
                                        </div>
                                        <div class="photo-result-percentage"><span><?=$percentage?>%</span></div>
                                        <div class="photo-result-track">
                                            <div class="photo-result-fill" style="width:<?=max($percentage,$voteCount>0?3:0)?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
