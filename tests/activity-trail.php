<?php
// Render the production activity template with fixed chronological segments; no database.
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function ioa_translate($key): string { return $key === 'activity_context_unknown' ? 'Unknown context' : $key; }
function ioa_t($key): string { return h(ioa_translate($key)); }
function formatCountLabel($count, $oneKey, $manyKey, $one, $many): string { return sprintf($count === 1 ? $one : $many, $count); }
function formatActivityRange($first, $last): string { return "$first – $last"; }
$source = file_get_contents(__DIR__ . '/../dashboard.php');
$start = strpos($source, '<div class="visit-journey">');
$end = strpos($source, "\n                                <?php endif; ?>\n                            </", $start);
$template = substr($source, $start, $end - $start);
$segment = static fn($title, $items) => ['title' => $title, 'first_activity' => '2026-10-10 12:00:00', 'latest_activity' => '2026-10-10 12:20:00', 'items' => $items];
$item = static fn($type, $ids) => ['type' => $type, 'photo_ids' => $ids, 'actions' => count($ids)];
$sections = [
    [$segment('Single context', [$item('photo_view', [1])])],
    [$segment('På jobbet', [$item('photo_view', range(1, 110)), $item('basket_add', [1, 2]), $item('basket_remove', [2])]),
     $segment('Utomhus', [$item('basket_add', [3])]),
     $segment('På jobbet', [$item('photo_download', [4]), $item('selection_download', [4, 5])])],
    array_map(static fn($title) => $segment($title, [$item('photo_view', [1])]), array_merge(['Basket', 'Photo page', 'Blog', 'Page', null], array_fill(0, 25, 'Long photographic context name')))
];
$sections[1][0]['items'][0]['actions'] = 120; // Repeated views count as views, without duplicating thumbnails.
$sections[1][2]['items'][0]['actions'] = 2; // Repeated direct download plus a two-photo selection.
$sections[1][2]['items'][1]['actions'] = 1;
$sections[2][29]['items'] = [$item('recorded_activity', [])];
$photoTitles = array_combine(range(1, 110), array_map(static fn($id) => "Photo $id", range(1, 110)));
$visitImages = [];
ob_start();
echo '<section class="photo-tabs"><div class="dashboard-card panel photo-tabs__content"><div class="photo-tabs__panel"><div class="visit-list">';
foreach ($sections as $index => $contextSections) {
    $visit = ['visit_id' => $index + 1, 'context_sections' => $contextSections];
    echo '<details class="visit-item"><summary class="visit-summary" data-disclosure-label="Details"><span class="photo-item__thumbnail"></span><span class="visit-summary__content"><span class="visit-summary__metrics">120 views</span><span class="visit-summary__identity">Today</span><span class="visit-summary__controls"><span class="visit-summary__id">Visit ' . ($index + 1) . '</span><span class="visit-summary__disclosure">Details</span></span></span></summary>';
    eval('?>' . $template);
    echo '</details>';
}
echo '</div></div></div></section>';
$html = ob_get_clean();
$dom = new DOMDocument;
@$dom->loadHTML('<meta charset="UTF-8">' . $html);
$x = new DOMXPath($dom);
$assert = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$assert($x->query('//details[1]//h3[@class="visit-journey__heading"]')->item(0)->textContent === 'Activity', 'Single segment heading');
$assert($x->query('//details[2]//h3[@class="visit-journey__heading"]')->item(0)->textContent === 'Activity trail', 'Multi-segment heading');
$assert($x->query('//details[1]//*[@data-activity-trail]')->length === 0, 'Single segment must not have a trail');
$assert($x->query('//details[2]//*[@data-trail-stop]')->length === 3, 'A-B-A must retain three stops');
$assert($x->query('//details[2]//*[@data-trail-stop][1]/*[@class="activity-trail__name"]')->item(0)->textContent === $x->query('//details[2]//*[@data-trail-stop][3]/*[@class="activity-trail__name"]')->item(0)->textContent, 'Repeated context titles');
$assert($x->query('//details[2]//*[@data-activity-segment][1]//*[contains(@class,"visit-activity-summary__item")]')->length === 3, 'Mixed activity stays within one segment');
$assert($x->query('//details[2]//*[@data-activity-segment][1]//*[@data-gallery-more]')->length === 1, 'Large gallery reveal retained');
$assert($x->query('//*[@data-trail-stop]')->length === 33 && $x->query('//*[@data-activity-segment]')->length === 34, 'All special and chronological contexts retained');
$assert($x->query('//details//details')->length === 0 && !str_contains($html, 'show_activity'), 'Only main Details disclosure remains');
$assert($x->query('//details[2]//*[@data-trail-stop][1]//*[@title="120 views"]')->length === 1, 'Repeated views per segment');
$assert($x->query('//details[2]//*[@data-trail-stop][1]//*[@title="3 basket actions"]')->length === 1, 'Basket adds plus removes');
$assert($x->query('//details[2]//*[@data-trail-stop][2]//*[@class="activity-trail__metric"]')->length === 1, 'Only nonzero categories appear');
$assert($x->query('//details[2]//*[@data-trail-stop][3]//*[@title="4 photos downloaded"]')->length === 1, 'Direct actions plus selection photo count');
$assert($x->query('//details[3]//*[@data-trail-stop][30]//*[@class="activity-trail__metric"]')->length === 0, 'Unknown activity must not invent metrics');
$assert($x->query('//*[@data-trail-scroll]')->length === 4, 'Two independent scroll controls per timeline');
$assert($x->query('//*[@data-trail-scroll and @aria-label and @hidden]')->length === 4, 'Scroll controls are labelled and progressively enabled');
$assert(!str_contains($source, '<div class="visit-highlights">'), 'Individual Visit rankings removed');
$assert(str_contains($source, "ioa_t('most_viewed_images')") && str_contains($source, "ioa_t('most_downloaded_images')"), 'Global rankings remain');
$assert(!str_contains($source, '.visit-context-section + .visit-context-section {'), 'Obsolete segment divider removed');
$ids = [];
foreach ($x->query('//*[@id]') as $node) { $assert(!isset($ids[$node->getAttribute('id')]), 'Duplicate ID'); $ids[$node->getAttribute('id')] = true; }
foreach ($x->query('//*[@data-trail-stop]') as $node) $assert(isset($ids[$node->getAttribute('aria-controls')]), 'Stop must target existing segment');
if (($argv[1] ?? '') === '--preview') {
    $css = explode('</style>', explode('<style>', $source, 2)[1], 2)[0];
    echo '<!doctype html><html><meta name="viewport" content="width=device-width,initial-scale=1"><meta charset="UTF-8"><style>' . $css . '</style><body><div class="dashboard">' . $html . '</div>';
    echo '<script src="file://' . dirname(__DIR__) . '/assets/visit-photo-gallery.js"></script></body></html>';
} else echo "Activity Trail template cases passed: single, A-B-A, mixed activity, special contexts, galleries and IDs\n";
