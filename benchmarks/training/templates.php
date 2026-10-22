<?php
// PGO training: HTML-ish rendering (interpolation, escaping, sprintf, regex).
$rows = [];
for ($i = 0; $i < 20000; $i++) {
    $rows[] = ['id' => $i, 'title' => "Post <$i> & \"friends\"", 'price' => $i * 1.25, 'tags' => ['a', 'b', "t$i"]];
}
$out = '';
for ($r = 0; $r < 5; $r++) {
    $html = [];
    foreach ($rows as $row) {
        $tags = implode(' ', array_map(function ($t) { return '<span>' . htmlspecialchars($t) . '</span>'; }, $row['tags']));
        $html[] = sprintf('<tr data-id="%d"><td>%s</td><td>%.2f</td><td>%s</td></tr>',
            $row['id'], htmlspecialchars($row['title'], ENT_QUOTES), $row['price'], $tags);
    }
    $out = implode("\n", $html);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(substr($out, 0, 5000)));
    preg_match_all('/data-id="(\d+)"/', $out, $m);
}
echo strlen($out), ' ', count($m[1]), ' ', strlen($slug), "\n";
