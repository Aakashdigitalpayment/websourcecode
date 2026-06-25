<?php
/**
 * admin/includes/admin-request-view.php
 * ══════════════════════════════════════════════════════════════
 * Unified Admin Request Detail View — single visual language
 * across job / account / kyc / loan / grievance / welfare /
 * appointment / vendor / message / feedback / etc.
 *
 * Renders:
 *   ┌──────────────────────────────────────────────┐
 *   │  Name + status badge          [← फिर्ता]      │
 *   ├──────────────────────────────────────────────┤
 *   │  Tabs: अवलोकन | कागजात | डेटा | गतिविधि लग    │
 *   ├──────────────────────────────────┬───────────┤
 *   │  Active tab content              │  Sidebar  │
 *   │                                  │  (status  │
 *   │                                  │  update + │
 *   │                                  │  notes)   │
 *   └──────────────────────────────────┴───────────┘
 *
 * Usage:
 *   require_once __DIR__ . '/admin-request-view.php';
 *   echo renderAdminRequestView([
 *     'title'      => $app['full_name'],
 *     'subtitle'   => 'पद: '.$app['job_title'],
 *     'status'     => $app['status'],
 *     'statusMap'  => [
 *        'pending'=>['warning','पेन्डिङ'],
 *        'selected'=>['success','चयन'],
 *        ...
 *     ],
 *     'backUrl'    => 'job-applications.php',
 *     'tabs'       => [
 *        ['id'=>'overview','label'=>'अवलोकन','icon'=>'fa-circle-info','html'=>...],
 *        ['id'=>'docs',    'label'=>'कागजात','icon'=>'fa-folder',     'html'=>...],
 *        ['id'=>'data',    'label'=>'आवेदक डेटा','icon'=>'fa-table',  'html'=>...],
 *        ['id'=>'log',     'label'=>'गतिविधि लग','icon'=>'fa-clock-rotate-left','html'=>...],
 *     ],
 *     'sidebar'    => $sidebarHtml,
 *   ]);
 * ══════════════════════════════════════════════════════════════
 */
if (!defined('IS_ADMIN_PAGE')) { http_response_code(403); exit('Access denied.'); }

if (!function_exists('arvStatusBadge')) {
    /**
     * Status badge with consistent color palette across all request pages.
     * @param string $status   Raw status key (e.g. 'pending', 'approved')
     * @param array  $map      Optional ['key' => ['bootstrap-color', 'Display Label']]
     */
    function arvStatusBadge(string $status, array $map = []): string {
        $defaultMap = [
            'pending'     => ['warning', 'पेन्डिङ'],
            'shortlisted' => ['info',    'छनोट'],
            'interviewed' => ['secondary','अन्तर्वार्ता'],
            'selected'    => ['success', 'चयन'],
            'approved'    => ['success', 'स्वीकृत'],
            'rejected'    => ['danger',  'अस्वीकृत'],
            'processing'  => ['info',    'प्रक्रियामा'],
            'in_review'   => ['info',    'समीक्षामा'],
            'review'      => ['info',    'समीक्षामा'],
            'forwarded'   => ['info',    'फरवार्ड'],
            'resolved'    => ['success', 'समाधान'],
            'closed'      => ['secondary','बन्द'],
            'unread'      => ['warning', 'नयाँ'],
            'read'        => ['secondary','पढिएको'],
            'replied'     => ['success', 'जवाफ दिइयो'],
            'cancelled'   => ['danger',  'रद्द'],
            'paid'        => ['success', 'भुक्तानी'],
            'unpaid'      => ['warning', 'अधुरो'],
        ];
        $merged = $map + $defaultMap;
        $key    = strtolower(trim($status));
        $entry  = $merged[$key] ?? ['secondary', $status !== '' ? ucfirst($status) : '—'];
        [$color, $label] = $entry;
        return '<span class="arv-status-badge arv-status-' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '">'
             . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('arvAssetsOnce')) {
    function arvAssetsOnce(): string {
        static $emitted = false;
        if ($emitted) return '';
        $emitted = true;
        return <<<'HTML'
<script>
(function(){
    function bindOne(card){
        if (card.dataset.arvBound==='1') return;
        card.dataset.arvBound='1';
        var tabs=card.querySelectorAll('.arv-tab');
        var panes=card.querySelectorAll('.arv-pane');
        tabs.forEach(function(t){
            t.addEventListener('click',function(){
                var id=t.getAttribute('data-tab');
                tabs.forEach(function(x){x.classList.toggle('is-active',x===t);});
                panes.forEach(function(p){p.classList.toggle('is-active',p.getAttribute('data-pane')===id);});
                try{ var u=new URL(location.href); u.searchParams.set('tab',id); history.replaceState(null,'',u.toString()); }catch(e){}
            });
        });
        try{
            var u=new URL(location.href); var want=u.searchParams.get('tab');
            if(want){ var tgt=card.querySelector('.arv-tab[data-tab="'+want+'"]'); if(tgt) tgt.click(); }
        }catch(e){}
    }
    function init(){ document.querySelectorAll('.arv-card').forEach(bindOne); }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>
HTML;
    }
}

if (!function_exists('renderAdminRequestView')) {
    /**
     * @param array $cfg {
     *   @var string  $title       Person/applicant name (heading)
     *   @var string  $subtitle    Optional muted line below title (HTML allowed)
     *   @var string  $status      Status key
     *   @var array   $statusMap   Per-page overrides for arvStatusBadge
     *   @var string  $backUrl     URL to list page
     *   @var string  $backLabel   Button label (default 'फिर्ता')
     *   @var string  $avatarIcon  FA icon class without 'fa-' prefix (default 'user')
     *   @var array   $tabs        list<['id','label','icon','html']>
     *   @var string  $sidebar     HTML for right column (status update form etc.)
     * }
     */
    function renderAdminRequestView(array $cfg): string {
        $title      = (string)($cfg['title']      ?? '—');
        $subtitle   = (string)($cfg['subtitle']   ?? '');
        $status     = (string)($cfg['status']     ?? '');
        $statusMap  = (array) ($cfg['statusMap']  ?? []);
        $backUrl    = (string)($cfg['backUrl']    ?? '#');
        $backLabel  = (string)($cfg['backLabel']  ?? 'फिर्ता');
        $avatarIcon = (string)($cfg['avatarIcon'] ?? 'user');
        $tabs       = (array) ($cfg['tabs']       ?? []);
        $sidebar    = (string)($cfg['sidebar']    ?? '');

        /* drop tabs whose html is empty/null so we never show a blank "Document" tab */
        $tabs = array_values(array_filter($tabs, static function ($t) {
            return is_array($t) && trim((string)($t['html'] ?? '')) !== '';
        }));
        if (!$tabs) {
            $tabs[] = ['id'=>'overview','label'=>'अवलोकन','icon'=>'fa-circle-info','html'=>'<p class="text-muted">कुनै विवरण उपलब्ध छैन।</p>'];
        }

        $assets = arvAssetsOnce();

        $tabBtns = '';
        $tabPanes = '';
        foreach ($tabs as $i => $t) {
            $id    = (string)($t['id']    ?? ('tab' . $i));
            $label = (string)($t['label'] ?? '—');
            $icon  = (string)($t['icon']  ?? 'fa-circle');
            $html  = (string)($t['html']  ?? '');
            $active = $i === 0 ? ' is-active' : '';
            $tabBtns  .= '<button type="button" class="arv-tab' . $active . '" data-tab="'
                       . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                       . '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i> '
                       . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
            $tabPanes .= '<div class="arv-pane' . $active . '" data-pane="'
                       . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . $html . '</div>';
        }

        $statusBadge = $status !== '' ? arvStatusBadge($status, $statusMap) : '';
        $sidebarHtml = trim($sidebar) !== ''
            ? '<aside class="arv-sidebar">' . $sidebar . '</aside>'
            : '';
        $bodyClass   = $sidebarHtml === '' ? 'arv-body no-sidebar' : 'arv-body';

        $subtitleHtml = trim($subtitle) !== ''
            ? '<div class="arv-subtitle">' . $subtitle . '</div>'
            : '';

        $back = '<a href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '" class="arv-back-btn">'
              . '<i class="lucide-icon" aria-hidden="true" data-lucide="arrow-left"></i> '
              . htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') . '</a>';

        $card = '<div class="arv-card">'
              . '<header class="arv-header">'
              . '<div class="arv-header-main">'
              . '<div class="arv-avatar"><i class="fas fa-' . htmlspecialchars($avatarIcon, ENT_QUOTES, 'UTF-8') . '"></i></div>'
              . '<div>'
              . '<h2 class="arv-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' ' . $statusBadge . '</h2>'
              . $subtitleHtml
              . '</div>'
              . '</div>'
              . '<div class="arv-header-actions">' . $back . '</div>'
              . '</header>'
              . '<nav class="arv-tabs" role="tablist">' . $tabBtns . '</nav>'
              . '<div class="' . $bodyClass . '">'
              . '<div class="arv-main">' . $tabPanes . '</div>'
              . $sidebarHtml
              . '</div>'
              . '</div>';

        return $assets . $card;
    }
}

/* ──────────────────────────────────────────────────────────────
   arvKvTable — quick key/value table for Overview tab.
   Pass associative array; null/'' values are auto-suppressed
   ────────────────────────────────────────────────────────────── */
if (!function_exists('arvKvTable')) {
    function arvKvTable(array $rows, bool $skipEmpty = true): string {
        $out = '<table class="arv-kv">';
        foreach ($rows as $label => $value) {
            if ($skipEmpty && (trim((string)$value) === '' || $value === null)) continue;
            $out .= '<tr><td>' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</td><td>' . $value . '</td></tr>';
        }
        $out .= '</table>';
        return $out;
    }
}

/* ──────────────────────────────────────────────────────────────
   arvDocsGrid — given list of [label, url, icon] document links
   ────────────────────────────────────────────────────────────── */
if (!function_exists('arvDocsGrid')) {
    function arvDocsGrid(array $docs): string {
        $docs = array_values(array_filter($docs, static function ($d) {
            return is_array($d) && trim((string)($d['url'] ?? '')) !== '';
        }));
        if (!$docs) {
            return '<div class="arv-doc-empty">कुनै कागजात attach गरिएको छैन।</div>';
        }
        $out = '<div class="arv-doc-grid">';
        foreach ($docs as $d) {
            $url   = (string)($d['url']   ?? '#');
            $label = (string)($d['label'] ?? 'Document');
            $icon  = (string)($d['icon']  ?? 'fa-file');
            $out  .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="arv-doc" target="_blank" rel="noopener">'
                   . '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i> '
                   . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $out .= '</div>';
        return $out;
    }
}

/* ──────────────────────────────────────────────────────────────
   arvLogList — render request_status_history rows in a unified style
   Expects each row as ['old_status','new_status','admin_comment','actor_name','created_at','notify_sent']
   ────────────────────────────────────────────────────────────── */
if (!function_exists('arvLogList')) {
    function arvLogList(array $rows): string {
        if (!$rows) {
            return '<div class="arv-log-empty">अहिलेसम्म कुनै गतिविधि लग छैन।</div>';
        }
        /* channel-status → display chip class + label + tooltip */
        $chip = static function (string $channel, string $status, string $reason = '', string $to = ''): string {
            $statusMap = [
                'sent'          => ['ok',   'पठाइयो'],
                'failed'        => ['err',  'असफल'],
                'skipped'       => ['skip', 'पठाइएन'],
                'not_attempted' => ['none', 'प्रयास भएन'],
            ];
            $info = $statusMap[$status] ?? ['none', '—'];
            [$cls, $label] = $info;
            $icon = $channel === 'email' ? 'fa-envelope' : 'fa-mobile-screen';
            $chLbl = $channel === 'email' ? 'Email' : 'SMS';
            $title = $chLbl . ': ' . $label;
            if ($to !== '')     $title .= ' → ' . $to;
            if ($reason !== '') $title .= ' (' . $reason . ')';
            return '<span class="arv-chip arv-chip--' . $cls . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
                 . '<i class="fas ' . $icon . '"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        };

        $out = arvAssetsOnce() . '<div class="arv-log-list">';
        foreach ($rows as $h) {
            $from   = htmlspecialchars((string)($h['old_status']    ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
            $to     = htmlspecialchars((string)($h['new_status']    ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
            $cmt    = (string)($h['admin_comment'] ?? '');
            $actor  = htmlspecialchars((string)($h['actor_name']    ?? 'Admin'), ENT_QUOTES, 'UTF-8');
            $when   = (string)($h['created_at']    ?? '');
            $whenH  = function_exists('formatNepaliDate') ? formatNepaliDate($when, true) : htmlspecialchars($when, ENT_QUOTES, 'UTF-8');

            /* Per-channel audit (v2 schema) — fall back to legacy notify_sent */
            $hasV2 = isset($h['notify_email_status']) || isset($h['notify_sms_status']);
            if ($hasV2) {
                $emailChip = $chip('email',
                    (string)($h['notify_email_status'] ?? 'not_attempted'),
                    (string)($h['notify_email_reason'] ?? ''),
                    (string)($h['notify_email_to']     ?? ''));
                $smsChip   = $chip('sms',
                    (string)($h['notify_sms_status'] ?? 'not_attempted'),
                    (string)($h['notify_sms_reason'] ?? ''),
                    (string)($h['notify_sms_to']     ?? ''));
                $intent = !empty($h['admin_chose_to_notify'])
                    ? '<span class="arv-chip arv-chip--intent" title="Admin ले notify पठाउने तय गरेका थिए"><i class="fas fa-paper-plane"></i> Notify</span>'
                    : '<span class="arv-chip arv-chip--intent-off" title="Admin ले notify नचुन्ने तय गरे"><i class="fas fa-bell-slash"></i> No-notify</span>';
                $notifyHtml = $intent . ' ' . $emailChip . ' ' . $smsChip;
            } else {
                $sent = !empty($h['notify_sent']);
                $notifyHtml = $sent
                    ? '<span class="arv-chip arv-chip--ok"><i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i> Sent</span>'
                    : '<span class="arv-chip arv-chip--none"><i class="fas fa-bell-slash"></i> Not sent</span>';
            }

            $out .= '<div class="arv-log-item">'
                  . '<div class="arv-log-arrow"><span class="arv-log-from">' . $from . '</span>'
                  . '<span class="arv-sep">→</span>' . $to . '</div>';
            if (trim($cmt) !== '') {
                $out .= '<div class="arv-log-comment">' . nl2br(htmlspecialchars($cmt, ENT_QUOTES, 'UTF-8')) . '</div>';
            }
            $out .= '<div class="arv-log-meta">'
                  . '<span><i class="fas fa-user-shield"></i> ' . $actor . '</span>'
                  . '<span class="dot">·</span><span><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i> ' . $whenH . '</span>'
                  . '</div>'
                  . '<div class="arv-log-notify">' . $notifyHtml . '</div>'
                  . '</div>';
        }
        $out .= '</div>';
        return $out;
    }
}
