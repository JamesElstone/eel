<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

$roots = [
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts',
];
$minorWords = array_fill_keys([
    'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into',
    'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'with',
], true);
$intentionalLowercase = array_fill_keys(['iXBRL', 's455'], true);
$violations = [];

foreach ($roots as $root) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = (string)file_get_contents($file->getPathname());
        preg_match_all(
            '~<(?<tag>a|button)\b(?<attrs>(?:[^>"\']+|"[^"]*"|\'[^\']*\')*)>(?<text>.*?)</\k<tag>>~si',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        foreach ($matches as $match) {
            $attributes = (string)$match['attrs'][0];
            if (preg_match('~\bclass\s*=\s*(["\'])[^"\']*\bbutton\b[^"\']*\1~i', $attributes) !== 1) {
                continue;
            }
            $rawText = trim((string)$match['text'][0]);
            if ($rawText === '' || str_contains($rawText, '$') || str_contains($rawText, "' .")) {
                continue;
            }
            $label = trim(html_entity_decode(strip_tags(str_replace("\\'", "'", $rawText)), ENT_QUOTES | ENT_HTML5));
            preg_match_all("~[A-Za-z][A-Za-z0-9]*(?:['’-][A-Za-z0-9]+)*~u", $label, $wordMatches);
            $words = (array)($wordMatches[0] ?? []);
            foreach ($words as $index => $word) {
                if (preg_match('/^[a-z]/', $word) !== 1 || isset($intentionalLowercase[$word])) {
                    continue;
                }
                $internalMinorWord = $index > 0
                    && $index < count($words) - 1
                    && isset($minorWords[strtolower($word)]);
                if ($internalMinorWord) {
                    continue;
                }
                $line = 1 + substr_count(substr($source, 0, (int)$match[0][1]), "\n");
                $violations[] = $file->getPathname() . ':' . $line . ' — ' . $label;
                break;
            }
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException("Button labels must use sensible Title Case:\n" . implode("\n", $violations));
}
