<?php
/**
 * Compiles a .po file to a binary .mo file.
 * Usage: php compile-mo.php form-forge-de_DE.po
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php compile-mo.php <file.po>\n");
    exit(1);
}

$poFile = $argv[1];
if (!file_exists($poFile)) {
    fwrite(STDERR, "File not found: $poFile\n");
    exit(1);
}

$moFile = preg_replace('/\.po$/', '.mo', $poFile);

// Parse .po file into msgid => msgstr pairs.
$strings = [];
$lines   = file($poFile, FILE_IGNORE_NEW_LINES);
$msgid   = null;
$msgstr  = null;
$inId    = false;
$inStr   = false;

$unescape = static function (string $s): string {
    return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $s);
};

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        if ($msgid !== null && $msgstr !== null && $msgid !== '') {
            $strings[$msgid] = $msgstr;
        }
        $msgid  = null;
        $msgstr = null;
        $inId   = false;
        $inStr  = false;
        continue;
    }
    if (str_starts_with($line, 'msgid "')) {
        $msgid = $unescape(substr($line, 7, -1));
        $inId  = true;
        $inStr = false;
    } elseif (str_starts_with($line, 'msgstr "')) {
        $msgstr = $unescape(substr($line, 8, -1));
        $inId   = false;
        $inStr  = true;
    } elseif (str_starts_with($line, '"') && str_ends_with($line, '"')) {
        $chunk = $unescape(substr($line, 1, -1));
        if ($inId) {
            $msgid .= $chunk;
        } elseif ($inStr) {
            $msgstr .= $chunk;
        }
    }
}
// Flush last entry.
if ($msgid !== null && $msgstr !== null && $msgid !== '') {
    $strings[$msgid] = $msgstr;
}

// Build .mo binary (little-endian).
$magic    = 0x950412de;
$revision = 0;
$count    = count($strings);
$ofsOrig  = 28;            // offset of original strings table
$ofsTrans = $ofsOrig + $count * 8;
$ofsHash  = $ofsTrans + $count * 8;
$hashSize = 0;

$origTable  = '';
$transTable = '';
$origData   = '';
$transData  = '';
$origOffset  = $ofsHash + $hashSize * 4;
$transOffset = $origOffset;

// We need two passes: first collect all byte offsets.
$origOffsets  = [];
$transOffsets = [];
$origPos  = 0;
$transPos = 0;

$keys   = array_keys($strings);
$values = array_values($strings);

foreach ($keys as $i => $key) {
    $origOffsets[$i]  = $origPos;
    $origPos         += strlen($key) + 1;
}
foreach ($values as $i => $val) {
    $transOffsets[$i]  = $transPos;
    $transPos         += strlen($val) + 1;
}

$origDataOffset  = $ofsHash + $hashSize * 4;
$transDataOffset = $origDataOffset + $origPos;

// Build the tables.
$origTable  = '';
$transTable = '';
foreach ($keys as $i => $key) {
    $origTable  .= pack('VV', strlen($key), $origDataOffset + $origOffsets[$i]);
}
foreach ($values as $i => $val) {
    $transTable .= pack('VV', strlen($val), $transDataOffset + $transOffsets[$i]);
}

$origData  = implode("\0", $keys) . "\0";
$transData = implode("\0", $values) . "\0";

$header = pack('VVVVVVV',
    $magic,
    $revision,
    $count,
    $ofsOrig,
    $ofsTrans,
    $hashSize,
    $ofsHash
);

file_put_contents($moFile, $header . $origTable . $transTable . $origData . $transData);
echo "Compiled $count strings → $moFile\n";
