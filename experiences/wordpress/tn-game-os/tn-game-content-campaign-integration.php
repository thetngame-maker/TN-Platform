<?php
/**
 * TN Game Content Campaign Integration
 * Connects reusable campaign records to Idea Generator, Post Builder, and Calendar.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Campaign_Integration {
    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    private static function campaigns(): array {
        if (!class_exists('TNG_Content_Campaigns')) return [];
        $rows = [];
        foreach (TNG_Content_Campaigns::campaigns() as $campaign) {
            $status = (string) get_post_meta($campaign->ID, '_tng_campaign_status', true);
            if (!in_array($status, ['active','draft','complete'], true)) continue;
            $stats = TNG_Content_Campaigns::campaign_stats($campaign);
            $rows[] = [
                'id' => (int) $campaign->ID,
                'name' => $campaign->post_title,
                'status' => $status ?: 'active',
                'start' => (string) get_post_meta($campaign->ID, '_tng_campaign_start', true),
                'end' => (string) get_post_meta($campaign->ID, '_tng_campaign_end', true),
                'target' => (int) $stats['target'],
                'created' => (int) $stats['items'],
                'planned' => (int) $stats['planned'],
                'published' => (int) $stats['published'],
                'percent' => (int) $stats['percent'],
                'goal' => (string) get_post_meta($campaign->ID, '_tng_campaign_goal', true),
                'focus' => (string) get_post_meta($campaign->ID, '_tng_campaign_focus', true),
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            if ($a['status'] === $b['status']) return strcasecmp($a['name'], $b['name']);
            return $a['status'] === 'active' ? -1 : 1;
        });
        return $rows;
    }

    private static function page(): string {
        return sanitize_key(wp_unslash($_GET['page'] ?? ''));
    }

    public static function footer(): void {
        if (!current_user_can('edit_posts')) return;
        $page = self::page();
        if (!in_array($page, ['tng-content-idea-generator','tng-content-post-builder','tng-content-calendar'], true)) return;
        $campaigns = self::campaigns();
        $json = wp_json_encode($campaigns);
        $calendar_url = admin_url('admin.php?page=tng-content-campaigns');
        ?>
        <script id="tng-campaign-integration">
        (()=>{
            const campaigns=<?php echo $json ?: '[]'; ?>;
            const active=campaigns.filter(c=>c.status==='active');
            const campaignUrl=<?php echo wp_json_encode($calendar_url); ?>;
            const qs=new URLSearchParams(location.search);
            const requested=qs.get('campaign')||'';

            const replaceCampaignField=(selector)=>{
                document.querySelectorAll(selector).forEach(input=>{
                    if(input.tagName==='SELECT'||input.dataset.tngCampaignSelect)return;
                    const current=(input.value||requested||'').trim();
                    const select=document.createElement('select');
                    [...input.attributes].forEach(a=>{if(a.name!=='type'&&a.name!=='value'&&a.name!=='placeholder')select.setAttribute(a.name,a.value)});
                    select.dataset.tngCampaignSelect='1';
                    const blank=document.createElement('option');blank.value='';blank.textContent='No campaign';select.append(blank);
                    campaigns.forEach(c=>{
                        const opt=document.createElement('option');opt.value=c.name;opt.textContent=c.name+(c.status==='active'?' · Active':' · '+c.status.charAt(0).toUpperCase()+c.status.slice(1));
                        if(c.name===current)opt.selected=true;
                        select.append(opt);
                    });
                    if(current&&!campaigns.some(c=>c.name===current)){
                        const old=document.createElement('option');old.value=current;old.textContent=current+' · Legacy';old.selected=true;select.append(old);
                    }
                    input.replaceWith(select);
                });
            };

            if(<?php echo wp_json_encode($page); ?>==='tng-content-idea-generator'){
                replaceCampaignField('input[name="idea_campaign"]');
                if(active.length){
                    document.querySelectorAll('select[name="idea_campaign"]').forEach(s=>{if(!s.value)s.value=requested||active[0].name});
                    const hero=document.querySelector('.tng-cig-hero');
                    if(hero&&!document.querySelector('.tng-campaign-context')){
                        const c=active.find(x=>x.name===(requested||active[0].name))||active[0];
                        const note=document.createElement('div');note.className='tng-campaign-context';
                        note.innerHTML=`<strong>Active campaign: ${escapeHtml(c.name)}</strong><span>${c.created}/${c.target} ideas created · ${c.planned} planned</span><a href="${campaignUrl}">View campaign →</a>`;
                        hero.insertAdjacentElement('afterend',note);
                    }
                }
            }

            if(<?php echo wp_json_encode($page); ?>==='tng-content-post-builder'){
                replaceCampaignField('input[name="campaign"]');
            }

            if(<?php echo wp_json_encode($page); ?>==='tng-content-calendar'){
                const toolbar=document.querySelector('.tng-cal-toolbar');
                if(toolbar&&active.length&&!document.querySelector('.tng-calendar-campaigns')){
                    const weekStart=(qs.get('week')||'');
                    let start=weekStart?new Date(weekStart+'T12:00:00'):new Date();
                    const day=(start.getDay()+6)%7;start.setDate(start.getDate()-day);
                    const end=new Date(start);end.setDate(end.getDate()+6);
                    const iso=d=>d.toISOString().slice(0,10);
                    const visible=active.filter(c=>(!c.start||c.start<=iso(end))&&(!c.end||c.end>=iso(start)));
                    if(visible.length){
                        const panel=document.createElement('section');panel.className='tng-calendar-campaigns';
                        panel.innerHTML=`<div class="tng-campaign-week-head"><div><p class="eyebrow">ACTIVE CAMPAIGNS</p><h2>Campaign progress</h2></div><a class="button" href="${campaignUrl}">Manage campaigns</a></div><div class="tng-campaign-week-grid"></div>`;
                        const grid=panel.querySelector('.tng-campaign-week-grid');
                        visible.forEach(c=>{
                            const remaining=Math.max(0,c.target-c.created);
                            const card=document.createElement('article');
                            card.innerHTML=`<div class="campaign-name"><strong>${escapeHtml(c.name)}</strong><span>${escapeHtml(c.focus||c.goal||'Active content campaign')}</span></div><div class="campaign-progress"><div><span style="width:${Math.min(100,c.percent)}%"></span></div><small>${c.created}/${c.target} created · ${c.planned} planned${c.published?' · '+c.published+' published':''}</small></div><div class="campaign-next"><strong>${remaining}</strong><span>still needed</span></div><a href="${ideaUrl(c.name)}">Generate idea →</a>`;
                            grid.append(card);
                        });
                        toolbar.insertAdjacentElement('afterend',panel);
                    }
                }
            }

            function ideaUrl(name){return <?php echo wp_json_encode(admin_url('admin.php?page=tng-content-idea-generator')); ?>+'&campaign='+encodeURIComponent(name)}
            function escapeHtml(v){const d=document.createElement('div');d.textContent=v||'';return d.innerHTML}
        })();
        </script>
        <?php
    }

    public static function assets(): void {
        $page = self::page();
        if (!in_array($page, ['tng-content-idea-generator','tng-content-post-builder','tng-content-calendar'], true)) return;
        wp_register_style('tng-campaign-integration', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null);
        wp_enqueue_style('tng-campaign-integration');
        wp_add_inline_style('tng-campaign-integration', '
            .tng-campaign-context{background:#fff;border:1px solid #d9e4dd;border-radius:16px;padding:14px 18px;margin:-4px 0 18px;display:flex;align-items:center;gap:18px;color:#173b2c}.tng-campaign-context strong{font-size:14px}.tng-campaign-context span{color:#6b7a72}.tng-campaign-context a{margin-left:auto;color:#df531f;font-weight:700;text-decoration:none}.tng-cig-card select[name="idea_campaign"],.tng-cpb-column select[name="campaign"]{width:100%;box-sizing:border-box;border:1px solid #d8e0db;border-radius:9px;padding:10px;margin:5px 0 14px;background:#fff}.tng-calendar-campaigns{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;margin:16px 0}.tng-campaign-week-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.tng-campaign-week-head h2{margin:3px 0;color:#173a2c}.tng-campaign-week-grid{display:grid;gap:10px;margin-top:14px}.tng-campaign-week-grid article{display:grid;grid-template-columns:minmax(180px,1.2fr) minmax(220px,2fr) 90px auto;gap:18px;align-items:center;border:1px solid #e0e7e2;border-radius:14px;padding:14px}.campaign-name strong,.campaign-name span,.campaign-next strong,.campaign-next span{display:block}.campaign-name strong{color:#173a2c;font-size:15px}.campaign-name span{color:#718078;font-size:11px;margin-top:3px}.campaign-progress>div{height:8px;border-radius:999px;background:#edf2ee;overflow:hidden}.campaign-progress>div span{height:100%;display:block;background:#f05b25;border-radius:999px}.campaign-progress small{display:block;color:#6f7d75;margin-top:5px}.campaign-next{text-align:center}.campaign-next strong{font-size:22px;color:#173a2c}.campaign-next span{font-size:9px;text-transform:uppercase;letter-spacing:.08em;color:#738078}.tng-campaign-week-grid article>a{color:#df531f;font-weight:700;text-decoration:none;white-space:nowrap}@media(max-width:900px){.tng-campaign-context{display:block}.tng-campaign-context>*{display:block;margin:5px 0}.tng-campaign-context a{margin-left:0}.tng-campaign-week-grid article{grid-template-columns:1fr}.campaign-next{text-align:left}}
        ');
    }
}
TNG_Content_Campaign_Integration::boot();
