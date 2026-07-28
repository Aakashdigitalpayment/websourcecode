<?php
/**
 * Team org-chart helpers — photo row on chart (auto + manual).
 * chart_row: 0 = auto from title/flags, 1–5 = manual row on public chart.
 */
if (!function_exists('team_resolve_chart_row')) {
    function team_resolve_chart_row(array $member): int
    {
        $stored = (int) ($member['chart_row'] ?? 0);
        if ($stored >= 1 && $stored <= 5) {
            return $stored;
        }

        if (!empty($member['is_chairman']) || !empty($member['is_ceo'])) {
            return 1;
        }

        $pos = strtolower(implode(' ', array_filter([
            (string) ($member['position'] ?? ''),
            (string) ($member['position_np'] ?? ''),
            (string) ($member['position_en'] ?? ''),
        ])));

        if (preg_match('/chair(man|person)?|अध्यक्ष|ceo|chief\s*executive|कार्यकारी\s*अधिकृत|प्रमुख\s*कार्यकारी/i', $pos)) {
            return 1;
        }
        if (preg_match('/vice[\s-]*chair|उप[\s-]*अध्यक्ष|उपाध्यक्ष/i', $pos)) {
            return 2;
        }
        if (preg_match('/director|manager|सञ्चालक|निर्देशक|प्रबन्धक/i', $pos)) {
            return 2;
        }
        if (preg_match('/member|सदस्य|secretary|सचिव|treasurer|कोषाध्यक्ष|representative/i', $pos)) {
            return 3;
        }

        return 4;
    }
}

if (!function_exists('team_group_by_chart_rows')) {
    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    function team_group_by_chart_rows(array $members): array
    {
        $rows = [];
        foreach ($members as $member) {
            if (empty($member['is_active']) && array_key_exists('is_active', $member)) {
                continue;
            }
            $row = team_resolve_chart_row($member);
            if (!isset($rows[$row])) {
                $rows[$row] = [];
            }
            $rows[$row][] = $member;
        }
        ksort($rows);
        foreach ($rows as &$group) {
            usort($group, static function (array $a, array $b): int {
                $oa = (int) ($a['display_order'] ?? 0);
                $ob = (int) ($b['display_order'] ?? 0);
                if ($oa !== $ob) {
                    return $oa <=> $ob;
                }
                return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
        }
        unset($group);
        return $rows;
    }
}

if (!function_exists('team_member_photo_src')) {
    function team_member_photo_src(array $member): string
    {
        $photo = (string) ($member['photo'] ?? '');
        if ($photo === '') {
            return '';
        }
        if (function_exists('safe_media_src')) {
            return safe_media_src($photo);
        }
        if (preg_match('#^https?://#i', $photo)) {
            return function_exists('safe_http_url') ? safe_http_url($photo) : $photo;
        }
        return function_exists('getAssetUrl') ? getAssetUrl($photo) : $photo;
    }
}

if (!function_exists('team_render_org_chart')) {
    /**
     * Render hierarchical org chart for board / committee members.
     *
     * @param array<int, array<string, mixed>> $members
     * @param array<string, mixed> $opts english(bool), aos(bool)
     */
    function team_render_org_chart(array $members, array $opts = []): string
    {
        if ($members === []) {
            return '';
        }

        $english = array_key_exists('english', $opts)
            ? (bool) $opts['english']
            : (function_exists('isEnglish') && isEnglish());
        $useAos = !array_key_exists('aos', $opts) || (bool) $opts['aos'];
        $label = $english ? 'Organization chart' : 'संगठन चार्ट';
        $rows = team_group_by_chart_rows($members);

        ob_start();
        ?>
        <div class="team-org-chart" role="group" aria-label="<?php echo e($label); ?>">
            <?php foreach ($rows as $rowNum => $rowMembers):
                $rowNum = (int) $rowNum;
                $count = count($rowMembers);
                $rowClass = 'team-org-row team-org-row-' . $rowNum . ' team-org-count-' . min($count, 6);
                ?>
            <div class="<?php echo $rowClass; ?>" role="list">
                <?php if ($rowNum > 1): ?>
                <div class="team-org-connector" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="team-org-row-inner">
                    <?php foreach ($rowMembers as $index => $member):
                        $featured = ($rowNum === 1 && $index === 0);
                        $small = $rowNum >= 3 || ($rowNum === 2 && $count > 4);
                        $photo = team_member_photo_src($member);
                        $name = (string) ($member['name'] ?? '');
                        $nameEn = (string) ($member['name_en'] ?? '');
                        $position = (string) (($member['position_np'] ?? '') ?: ($member['position'] ?? ''));
                        $delay = ($index % 6) * 50;
                        ?>
                    <div class="team-org-cell" role="listitem"<?php if ($useAos): ?> data-aos="fade-up" data-aos-delay="<?php echo (int) $delay; ?>"<?php endif; ?>>
                        <div class="team-card-circular<?php echo $featured ? ' featured' : ''; ?><?php echo $small ? ' small' : ''; ?>">
                            <div class="team-photo-circular<?php echo $small ? ' small' : ''; ?>">
                                <?php if ($photo !== ''): ?>
                                <img src="<?php echo e($photo); ?>" loading="lazy" alt="<?php echo e($name); ?>">
                                <?php else: ?>
                                <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="team-info-circular">
                                <?php if ($small): ?>
                                <h6><?php echo e($name); ?></h6>
                                <?php else: ?>
                                <h5><?php echo e($name); ?></h5>
                                <?php endif; ?>
                                <?php if ($nameEn !== ''): ?>
                                <p class="team-name-en"><?php echo e($nameEn); ?></p>
                                <?php endif; ?>
                                <?php if ($position !== ''): ?>
                                <span class="team-position-badge"><?php echo e($position); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($member['phone']) || !empty($member['email'])): ?>
                                <div class="team-contact-circular">
                                    <?php if (!empty($member['phone'])): ?>
                                    <a href="tel:<?php echo e($member['phone']); ?>" title="<?php echo e($member['phone']); ?>"><i class="fas fa-phone"></i></a>
                                    <?php endif; ?>
                                    <?php if (!empty($member['email'])): ?>
                                    <a href="mailto:<?php echo e($member['email']); ?>" title="<?php echo e($member['email']); ?>"><i class="fas fa-envelope"></i></a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
