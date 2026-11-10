<?php
/* ============================================================
   signup.php  -  page + all the sign-up logic that can run on the server
   (PostgreSQL version: needs signupdb.php and schema.sql)

   FLOW:  1) User info  ->  2) Valid ID  ->  3) Personal info (filled in
   automatically from the ID, cannot be edited)  ->  4) Face verification

   When the account is created:
     - the face from the Valid ID is saved for Face Login (face_profiles)
     - the new employee is logged in (session), so the dashboard shows
       the username that was typed at sign-up
   ============================================================ */

require_once __DIR__ . '/signupdb.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const MATCH_THRESHOLD = 0.5;   // lower = stricter (0.6 is face-api default)

// the browser reads the ID several times (different scan settings) and joins the
// texts with this line; it must be the same text as PASS_SEP in the JavaScript below
const PASS_SEP = "\n=====PASS=====\n";

/* ---------- ID TYPE DETECTION ----------
   Keywords are written in plain capitals; spaces and punctuation
   are ignored when matching. Edit these lists if a real ID of
   yours is not being recognized. */
const ID_KEYWORDS = [
    "National ID" => [
        "PHILIPPINE IDENTIFICATION CARD",
        "PHILIPPINE IDENTIFICATION",
        "PAMBANSANG PAGKAKAKILANLAN",
        "PAGKAKAKILANLAN",
        "PHILSYS",
        "PHILIPPINE STATISTICS AUTHORITY",
    ],
    "Passport" => [
        "PASSPORT",
        "PASAPORTE",
        "DEPARTMENT OF FOREIGN AFFAIRS",
    ],
    "Driver's License" => [
        "DRIVERS LICENSE",
        "DRIVER LICENSE",
        "LAND TRANSPORTATION OFFICE",
        "LAND TRANSPORTATION",
        "NON PROFESSIONAL",
        "LICENSE NO",
    ],
    "UMID" => [
        "UNIFIED MULTI PURPOSE",
        "UNIFIED MULTIPURPOSE",
        "UMID",
        "SOCIAL SECURITY SYSTEM",
        "GOVERNMENT SERVICE INSURANCE SYSTEM",
    ],
    "PhilHealth ID" => [
        "PHILHEALTH",
        "PHILIPPINE HEALTH INSURANCE CORPORATION",
        "PHILIPPINE HEALTH INSURANCE",
    ],
];

// words that show up on school IDs (these are not accepted as a government ID)
const STUDENT_KEYWORDS = [
    "STUDENT",
    "UNIVERSITY",
    "COLLEGE",
    "SCHOOL",
    "ACADEMY",
    "INSTITUTE",
    "ENROLLED",
];

// extra words used only for "Other Government ID"
const OTHER_GOV_KEYWORDS = [
    "REPUBLIC OF THE PHILIPPINES",
    "REPUBLIKA NG PILIPINAS",
    "PROFESSIONAL REGULATION COMMISSION",
    "COMMISSION ON ELECTIONS",
    "BUREAU OF INTERNAL REVENUE",
    "NATIONAL BUREAU OF INVESTIGATION",
    "POSTAL IDENTITY",
    "PAG IBIG",
    "PERSON WITH DISABILITY",
    "SENIOR CITIZEN",
];

function normText(string $s): string
{
    return preg_replace('/[^A-Z0-9<]/', '', strtoupper($s));
}

// approximate substring search: allows ~20% OCR mistakes in longer keywords
function fuzzyIncludes(string $text, string $keyword): bool
{
    if (strpos($text, $keyword) !== false)
        return true;
    if (strlen($keyword) < 8)
        return false;

    $m = strlen($keyword);
    $maxErr = (int) floor($m * 0.2);
    $n = strlen($text);
    $prev = range(0, $m);

    for ($i = 1; $i <= $n; $i++) {
        $curr = [0];
        for ($j = 1; $j <= $m; $j++) {
            $cost = $text[$i - 1] === $keyword[$j - 1] ? 0 : 1;
            $curr[$j] = min($prev[$j] + 1, $curr[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        if ($curr[$m] <= $maxErr)
            return true;
        $prev = $curr;
    }
    return false;
}

function countHits(string $text, array $list): int
{
    $found = 0;
    foreach ($list as $k) {
        if (fuzzyIncludes($text, normText($k)))
            $found++;
    }
    return $found;
}

function analyzeIdText(string $raw): array
{
    $text = normText($raw);
    $scores = [];

    foreach (ID_KEYWORDS as $type => $words)
        $scores[$type] = countHits($text, $words);

    // a passport also has a machine-readable line like P<PHL...
    $mrz = strtoupper(preg_replace('/\s/u', '', $raw));
    if (preg_match('/P[<«][A-Z]{3}/u', $mrz))
        $scores["Passport"] += 2;

    return [
        "scores" => $scores,
        "student" => countHits($text, STUDENT_KEYWORDS),
        "otherGov" => countHits($text, OTHER_GOV_KEYWORDS),
    ];
}

/** Returns [ok(bool), message(string)] */
function checkIdType(string $selected, string $idText): array
{
    if ($selected === "")
        return [false, "Select the ID type above."];

    $a = analyzeIdText($idText);
    $scores = $a["scores"];
    $student = $a["student"];
    $otherGov = $a["otherGov"];

    // best matching known type
    $bestType = null;
    $bestScore = 0;
    foreach ($scores as $type => $score) {
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestType = $type;
        }
    }

    if ($selected === "Other Government ID") {
        $anyGov = $bestScore > 0 || $otherGov > 0;
        if ($student > 0 && !$anyGov) {
            return [false, "This looks like a school or student ID, which is not accepted."];
        }
        if ($anyGov)
            return [true, "Government ID detected ✓"];
        return [false, "We could not recognize this as a government ID. Upload a clear, uncropped photo of the front."];
    }

    $own = $scores[$selected] ?? 0;

    if ($own > 0 && $own >= $bestScore)
        return [true, $selected . " detected ✓"];

    if ($bestType && $bestScore > $own) {
        return [
            false,
            "This looks like a " . $bestType . ", not a " . $selected .
            ". Upload the correct ID or change the ID type."
        ];
    }
    if ($student > 0) {
        return [false, "This looks like a school or student ID, not a " . $selected . "."];
    }
    return [
        false,
        "We could not confirm this is a " . $selected .
        ". Upload a clear, well-lit, uncropped photo of the front of the ID."
    ];
}

/* ============================================================
   READ PERSONAL INFORMATION FROM THE ID TEXT (OCR)
   Returns: firstName, middleName, lastName, extension, birthDate (Y-m-d)
   Anything that cannot be read is returned as "" and the user
   simply goes back and uploads a clearer photo. This is best-effort:
   OCR is never perfect.

   The browser scans the ID up to 3 times with different settings and
   sends all the texts (separated by PASS_SEP). Each scan is parsed on its
   own and the best / most complete result wins.
   ============================================================ */

// words that are printed on IDs (labels, headers, addresses) - these are never a person's name
const LABEL_RE = '/\b(?:NAME|NAMES|SURNAME|GIVEN|MIDDLE|FIRST|LAST|BIRTH|DATE|SEX|ADDRESS|APELYIDO|PANGALAN|KAPANGANAKAN|GITNANG|MGA|PETSA|TIRAHAN|KASARIAN|LUGAR|PCN|REPUBLIC|REPUBLIKA|PHILIPPINES|PILIPINAS|PHILIPPINE|LICENSE|NATIONALITY|CARD|IDENTIFICATION|SIGNATURE|AGENCY|EXPIRATION|EXPIRY|BLOOD|TYPE|PASSPORT|PASAPORTE|PLACE|ISSUE|ISSUED|NUMBER|CODE|KODA|HEIGHT|WEIGHT|STATUS|CIVIL|CITIZEN|CITIZENSHIP|DEPARTMENT|FOREIGN|AFFAIRS|TRANSPORTATION|OFFICE|HEALTH|INSURANCE|CORPORATION|SOCIAL|SECURITY|SYSTEM|GOVERNMENT|SERVICE|UNIFIED|MULTI|PURPOSE|PHILHEALTH|PHILSYS|AUTHORITY|STATISTICS|PAMBANSANG|PAGKAKAKILANLAN|UMID|CRN|DRIVER|DRIVERS|PROFESSIONAL|NON|CITY|PROVINCE|BARANGAY|BRGY|STREET|AVENUE|ROAD|PUROK|SITIO|PHASE|BLOCK|SUBDIVISION|VILLAGE|MUNICIPALITY|REGION|MANILA)\b/i';

/** All valid past dates found in a piece of text, oldest first, as Y-m-d. */
function allDates(string $s): array
{
    $found = [];
    $today = date('Y-m-d');
    $year = (int) date('Y');
    $mon = 'JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC';
    $monList = explode('|', $mon);

    // OCR often reads a 0 as the letter O and a 1 as I / l / | when it sits next to digits
    $s = preg_replace('/(?<=\d)O(?=\d|\b)|\bO(?=\d)/', '0', $s);
    $s = preg_replace('/(?<=\d)[Il|](?=\d)/', '1', $s);

    $add = function (int $y, int $m, int $d) use (&$found, $today, $year) {
        if ($y >= 1900 && $y <= $year && checkdate($m, $d, $y)) {
            $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
            if ($iso <= $today)
                $found[$iso] = true;
        }
    };

    // 1990/09/08  1990-09-08  1990.09.08
    if (preg_match_all('/(\d{4})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})/', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $x)
            $add((int) $x[1], (int) $x[2], (int) $x[3]);

    // 09/08/1990  (month first; day first when the first number is above 12)
    if (preg_match_all('/(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{4})/', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $x) {
            $a = (int) $x[1];
            $b = (int) $x[2];
            $a > 12 ? $add((int) $x[3], $b, $a) : $add((int) $x[3], $a, $b);
        }

    // SEPTEMBER 8, 1990   /   SEPTEMBER 08,1990
    if (preg_match_all('/\b(' . $mon . ')[A-Z]*\.?\s*(\d{1,2})\s*,?\s*(\d{4})/i', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $x)
            $add((int) $x[3], array_search(strtoupper($x[1]), $monList) + 1, (int) $x[2]);

    // 8 SEP 1990
    if (preg_match_all('/\b(\d{1,2})\s*(' . $mon . ')[A-Z]*\.?\s*,?\s*(\d{4})/i', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $x)
            $add((int) $x[3], array_search(strtoupper($x[2]), $monList) + 1, (int) $x[1]);

    ksort($found);
    return array_keys($found);
}

/** Cleans OCR noise from a name: stray symbols, | read instead of I, digits inside words. */
function cleanNameValue(string $v): string
{
    $v = trim($v, " \t|!_[](){}:;,/\\");
    $v = str_replace(['|', '!'], 'I', $v);

    $out = [];
    foreach (preg_split('/\s+/u', $v, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        if (preg_match('/\p{L}/u', $t) && preg_match('/\d/', $t))
            $t = strtr($t, ['0' => 'O', '1' => 'I', '5' => 'S', '8' => 'B']);   // digit inside a word = OCR mistake
        $t = preg_replace('/[^\p{L}\.\-\']/u', '', $t);
        if ($t !== '')
            $out[] = $t;
    }

    return trim(preg_replace('/^[^\p{L}]+|[^\p{L}\.]+$/u', '', implode(' ', $out)));
}

function isNameValue(string $v): bool
{
    if (mb_strlen($v) < 2 || mb_strlen($v) > 50)
        return false;
    if (!preg_match('/^\p{L}[\p{L}\.\-\' ]+$/u', $v))
        return false;
    if (count(preg_split('/\s+/u', $v)) > 5)
        return false;
    return !preg_match(LABEL_RE, $v);
}

/** Removes one-letter OCR junk (a letter is only kept when it is an initial like "S."). */
function dropNoiseTokens(string $name): string
{
    $keep = [];
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen(rtrim($w, '.')) >= 2 || substr($w, -1) === '.')
            $keep[] = $w;
    }
    return implode(' ', $keep);
}

/** Most letters of the line are capitals (names on Philippine IDs are printed in CAPITALS). */
function isMostlyUpper(string $s): bool
{
    $letters = preg_match_all('/\p{L}/u', $s);
    if ($letters < 2)
        return false;
    $upper = preg_match_all('/\p{Lu}/u', $s);
    return $upper / $letters >= 0.7;
}

/** A line that can hold a name (a name never has 2+ digits in a row, e.g. a date or an ID number). */
function isValueLine(string $line): bool
{
    return !preg_match('/\d{2,}/', $line);
}

/**
 * Value that belongs to a printed label.
 * Normal IDs print the value after the label (same line or the line below).
 * Some cards (UMID) print the value ABOVE the label ($above = true).
 */
function labelValue(array $lines, string $labelRe, bool $above = false): string
{
    foreach ($lines as $i => $line) {
        if (!preg_match($labelRe, $line, $m, PREG_OFFSET_CAPTURE))
            continue;

        $cands = [];
        if ($above) {
            if ($i > 0)
                $cands[] = $lines[$i - 1];
            $cands[] = substr($line, $m[0][1] + strlen($m[0][0]));
        } else {
            $cands[] = substr($line, $m[0][1] + strlen($m[0][0]));
            if (isset($lines[$i + 1]))
                $cands[] = $lines[$i + 1];
        }

        foreach ($cands as $c) {
            $v = cleanNameValue($c);
            if (isNameValue($v))
                return $v;
        }
    }
    return '';
}

/* ---------- LABEL RECOGNITION (English + Filipino labels, OCR-tolerant) ----------
   The Philippine National ID prints bilingual labels with the value on the line BELOW:
       Apelyido/Last Name
       DELA CRUZ
       Mga Pangalan/Given Names
       JUAN CARLOS
       Gitnang Apelyido/Middle Name
       SANTOS
       Petsa ng Kapanganakan/Date of Birth
       SEPTEMBER 08, 1990
   Labels are recognised even when OCR garbles one of the two languages. */

function labelWordHit(string $n, string $w): bool
{
    $len = strlen($w);
    if ($len >= 9)
        return fuzzyIncludes($n, $w);                 // long words: allow OCR mistakes
    if ($len >= 6)
        return strpos($n, $w) !== false;              // medium words: exact
    return strlen($n) <= 12 && strpos($n, $w) !== false;   // very short words: only on very short lines
}

/** 'last' | 'first' | 'middle' | 'dob' | 'other' when the line is a printed label, otherwise null. */
function lineLabelKind(string $line): ?string
{
    $n = normText($line);
    if ($n === '' || strlen($n) > 45)
        return null;

    // order matters: "Gitnang Apelyido/Middle Name" also contains APELYIDO
    $sets = [
        'middle' => ['GITNANGAPELYIDO', 'MIDDLENAME', 'GITNANG'],
        'first' => ['MGAPANGALAN', 'GIVENNAMES', 'GIVENNAME', 'FIRSTNAME', 'PANGALAN'],
        'last' => ['APELYIDOLASTNAME', 'LASTNAME', 'APELYIDO', 'SURNAME'],
        'other' => [
            'PLACEOFBIRTH', 'LUGARNGKAPANGANAKAN', 'TIRAHAN', 'ADDRESS', 'KASARIAN', 'BLOODTYPE',
            'DATEOFISSUE', 'DATEOFEXPIRY', 'CIVILSTATUS', 'SIGNATURE', 'NATIONALITY',
            'PHILSYSCARDNUMBER', 'CARDNUMBER', 'SEX', 'PCN',
        ],
        'dob' => ['PETSANGKAPANGANAKAN', 'DATEOFBIRTH', 'KAPANGANAKAN', 'BIRTHDATE'],
    ];

    foreach ($sets as $kind => $words)
        foreach ($words as $w)
            if (labelWordHit($n, $w))
                return $kind;

    return null;
}

/**
 * Reads last / first / middle name from bilingual labelled cards (National ID, passport, ...).
 * 1) the value is the next valid line under its label
 * 2) when OCR listed all the labels first and the values after them, the values are
 *    matched to the labels in order
 */
function parseLabelBlocks(array $lines): array
{
    $kind = [];
    foreach ($lines as $i => $l)
        $kind[$i] = lineLabelKind($l);

    $res = ['last' => '', 'first' => '', 'middle' => ''];
    $pending = [];          // labels that had no value directly under them
    $lastLabelAt = -1;

    /* Stacked labels: OCR printed several name labels one after another and the values
       after all of them (Last Name / Given Names / Middle Name / DELA CRUZ / JUAN / SANTOS).
       Then the value right under the LAST label belongs to the FIRST label, so the values
       are matched to the labels in order. */
    $n = count($lines);
    for ($i = 0; $i < $n - 1; $i++) {
        if (!array_key_exists((string) $kind[$i], $res) || !array_key_exists((string) $kind[$i + 1], $res))
            continue;

        $group = [];
        $e = $i;
        while ($e < $n && $kind[$e] !== null) {
            if (array_key_exists($kind[$e], $res))
                $group[] = $kind[$e];
            $e++;
        }

        $pool = [];
        for ($j = $e; $j < $n && $kind[$j] === null; $j++) {
            if (!isValueLine($lines[$j]) || !isMostlyUpper($lines[$j]))
                continue;
            $c = cleanNameValue($lines[$j]);
            if (isNameValue($c))
                $pool[] = $c;
        }

        foreach ($group as $idx => $k)
            if ($res[$k] === '' && isset($pool[$idx]))
                $res[$k] = $pool[$idx];

        break;
    }

    foreach ($lines as $i => $l) {
        $k = $kind[$i];
        if ($k === null || !array_key_exists($k, $res))
            continue;

        $lastLabelAt = $i;
        $v = '';

        for ($j = $i + 1; $j <= $i + 3 && isset($lines[$j]); $j++) {
            if ($kind[$j] !== null)
                break;                                  // reached the next label: this one has no value
            if (!isValueLine($lines[$j]))
                continue;
            $c = cleanNameValue($lines[$j]);
            if (isNameValue($c)) {
                $v = $c;
                break;
            }
        }

        if ($res[$k] === '') {
            if ($v !== '')
                $res[$k] = $v;
            else
                $pending[] = $k;
        }
    }

    if ($pending && $lastLabelAt >= 0) {
        $pool = [];
        for ($j = $lastLabelAt + 1; $j < count($lines); $j++) {
            if ($kind[$j] !== null)
                break;
            if (!isValueLine($lines[$j]) || !isMostlyUpper($lines[$j]))
                continue;
            $c = cleanNameValue($lines[$j]);
            if (isNameValue($c))
                $pool[] = $c;
        }
        foreach ($pending as $n => $k)
            if (isset($pool[$n]))
                $res[$k] = $pool[$n];
    }

    return $res;
}

/** National ID without readable labels: the CAPITAL lines printed above the birth date are last / given / middle name. */
function nameLinesBeforeBirth(array $lines): array
{
    $stop = null;
    foreach ($lines as $i => $l) {
        if (lineLabelKind($l) === 'dob') {
            $stop = $i;
            break;
        }
    }
    if ($stop === null) {
        foreach ($lines as $i => $l) {
            if (allDates($l)) {
                $stop = $i;
                break;
            }
        }
    }
    if ($stop === null)
        return [];

    $out = [];
    for ($i = 0; $i < $stop; $i++) {
        if (lineLabelKind($lines[$i]) !== null || !isValueLine($lines[$i]) || !isMostlyUpper($lines[$i]))
            continue;
        $c = cleanNameValue($lines[$i]);
        if (mb_strlen($c) >= 3 && isNameValue($c))
            $out[] = $c;
    }
    return $out;
}

/** "DELA CRUZ, JUAN SANTOS" or "DELA CRUZ, JUAN, SANTOS"  ->  [last, first, middle] */
function parseCommaName(string $line): ?array
{
    $parts = [];
    foreach (explode(',', $line) as $piece) {
        $c = cleanNameValue($piece);
        if ($c !== '')
            $parts[] = $c;
    }
    if (count($parts) < 2 || count($parts) > 3)
        return null;
    foreach ($parts as $p)
        if (!isNameValue($p))
            return null;

    if (count($parts) === 3)
        return [$parts[0], $parts[1], $parts[2]];

    $rest = preg_split('/\s+/u', $parts[1]);
    $middle = '';
    if (count($rest) >= 2)
        $middle = array_pop($rest);      // last word = middle name
    return [$parts[0], implode(' ', $rest), $middle];
}

/** "JUAN JR"  ->  ["JUAN", "Jr."] */
function splitExtension(string $name): array
{
    if (preg_match('/^(.*?)[\s,]+(JR|SR|II|III|IV)\.?$/i', trim($name), $m)) {
        $e = strtoupper($m[2]);
        $ext = $e === 'JR' ? 'Jr.' : ($e === 'SR' ? 'Sr.' : $e);
        return [trim($m[1]), $ext];
    }
    return [trim($name), ''];
}

function titleCase(string $s): string
{
    $t = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    // O'Brien, Dela-Cruz
    return preg_replace_callback(
        '/([\'\-])(\p{Ll})/u',
        fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
        $t
    );
}

/** Which of the required details could not be read (middle name / extension fall back to N/A). */
function missingIdInfo(array $f): array
{
    $missing = [];
    if ($f['firstName'] === '')
        $missing[] = 'first name';
    if ($f['lastName'] === '')
        $missing[] = 'last name';
    if ($f['birthDate'] === '')
        $missing[] = 'birth date';
    return $missing;
}

/** Parses ONE scan of the ID. */
function parseIdFieldsSingle(string $raw, string $idType = ''): array
{
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/u', $raw)),
        fn($l) => $l !== ''
    ));

    $first = $middle = $last = $ext = $birth = '';

    /* ---- 1) Driver's license style: a header "Last Name, First Name, Middle Name"
              followed by "DELA CRUZ, JUAN SANTOS" ---- */
    foreach ($lines as $i => $l) {
        if (preg_match('/LAST\s*NAME\W+FIRST\s*NAME/i', $l) && isset($lines[$i + 1])) {
            $p = parseCommaName($lines[$i + 1]);
            if ($p) {
                [$last, $first, $middle] = $p;
                break;
            }
        }
    }

    /* ---- 2) Labelled fields (PhilSys national ID, passport, UMID, PhilHealth, ...) ---- */
    if ($first === '' && $last === '') {
        // UMID prints the value above its label; the others print it after / below
        $above = false;
        if ($idType === 'UMID') {
            foreach ($lines as $i => $l) {
                if (preg_match('/SURNAME|LAST\s*NAME/i', $l)) {
                    $above = $i > 0 && isNameValue(cleanNameValue($lines[$i - 1]));
                    break;
                }
            }
        }

        // bilingual label reader (value under its label, or all values listed after all labels)
        if (!$above) {
            $blk = parseLabelBlocks($lines);
            $last = $blk['last'];
            $first = $blk['first'];
            $middle = $blk['middle'];
        }

        // anything still missing: the older reader (value on the same line as the label, or above it)
        if ($last === '')
            $last = labelValue($lines, '/SURNAME|LAST\s*NAME|APELYIDO/i', $above);
        if ($first === '')
            $first = labelValue($lines, '/GIVEN\s*NAMES?|FIRST\s*NAME|PANGALAN/i', $above);
        if ($middle === '')
            $middle = labelValue($lines, '/MIDDLE\s*NAME|GITNANG/i', $above);
    }

    /* ---- 3) Passport machine-readable line:  P<PHLDELA<CRUZ<<JUAN<CARLOS<<< ---- */
    $mrzBirth = '';
    foreach ($lines as $l) {
        $c = strtoupper(str_replace(['«', ' '], ['<', ''], $l));

        if (($first === '' || $last === '') && preg_match('/P<PHL([A-Z<]+)/', $c, $m)) {
            $parts = explode('<<', $m[1], 2);
            if ($last === '')
                $last = trim(str_replace('<', ' ', $parts[0]));
            if ($first === '')
                $first = trim(str_replace('<', ' ', $parts[1] ?? ''));
        }

        // second MRZ line: ...PHL9009087M... (birth date YYMMDD)
        if ($mrzBirth === '' && preg_match('/PHL(\d{2})(\d{2})(\d{2})\d[MF<]/', $c, $m)) {
            $yy = (int) $m[1];
            $y = 2000 + $yy > (int) date('Y') ? 1900 + $yy : 2000 + $yy;
            if (checkdate((int) $m[2], (int) $m[3], $y))
                $mrzBirth = sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[3]);
        }
    }

    /* ---- 4) Last resort: an ALL-CAPS "LAST, FIRST MIDDLE" line (not for passports / national ID) ---- */
    if (($first === '' || $last === '') && !in_array($idType, ['Passport', 'National ID'], true)) {
        foreach ($lines as $l) {
            if (preg_match('/\d/', $l) || $l !== strtoupper($l))
                continue;
            $p = parseCommaName($l);
            if ($p) {
                [$last, $first, $middle] = $p;
                break;
            }
        }
    }

    /* ---- 5) National ID whose labels could not be read: the capital lines above the
              birth date are  LAST NAME / GIVEN NAMES / MIDDLE NAME  (in this order) ---- */
    if (($first === '' || $last === '') && $idType === 'National ID') {
        $c = array_slice(nameLinesBeforeBirth($lines), -3);
        if (count($c) === 3) {
            if ($last === '')
                $last = $c[0];
            if ($first === '')
                $first = $c[1];
            if ($middle === '')
                $middle = $c[2];
        } elseif (count($c) === 2) {
            if ($last === '')
                $last = $c[0];
            if ($first === '')
                $first = $c[1];
        }
    }

    /* ---- extension (Jr., Sr., III ...) can be printed after the first or last name ---- */
    [$first, $e1] = splitExtension($first);
    [$last, $e2] = splitExtension($last);
    [$middle, $e3] = splitExtension($middle);
    $ext = $e1 !== '' ? $e1 : ($e2 !== '' ? $e2 : $e3);

    $first = dropNoiseTokens($first);
    $last = dropNoiseTokens($last);
    $middle = dropNoiseTokens($middle);

    /* ---- birth date ---- */
    foreach ($lines as $i => $l) {
        if (preg_match('/DATE\s*OF\s*BIRTH|BIRTH\s*DATE|BIRTHDATE|\bDOB\b|KAPANGANAKAN|PETSA|BIRTH/i', $l)) {
            $d = allDates($l . ' ' . ($lines[$i + 1] ?? ''));
            if (!$d)
                $d = allDates($l . ' ' . ($lines[$i + 1] ?? '') . ' ' . ($lines[$i + 2] ?? ''));
            if ($d) {
                $birth = $d[0];
                break;
            }
        }
    }
    if ($birth === '')
        $birth = $mrzBirth;
    if ($birth === '') {
        $d = allDates($raw);          // oldest date on the card = birth date (issue / expiry are later)
        if ($d)
            $birth = $d[0];
    }

    return [
        'firstName' => $first !== '' ? titleCase($first) : '',
        'middleName' => $middle !== '' ? titleCase($middle) : 'N/A',   // no middle name on the ID -> N/A
        'lastName' => $last !== '' ? titleCase($last) : '',
        'extension' => $ext !== '' ? $ext : 'N/A',                      // no extension on the ID -> N/A
        'birthDate' => $birth,
    ];
}

function idFieldsScore(array $f): int
{
    return ($f['firstName'] !== '' ? 2 : 0)
        + ($f['lastName'] !== '' ? 2 : 0)
        + ($f['birthDate'] !== '' ? 2 : 0)
        + ($f['middleName'] !== 'N/A' ? 1 : 0);
}

/** Parses every scan of the ID (see PASS_SEP) and keeps the most complete result. */
function parseIdFields(string $raw, string $idType = ''): array
{
    $passes = array_values(array_filter(
        array_map('trim', explode(PASS_SEP, $raw)),
        fn($p) => $p !== ''
    ));
    if (!$passes)
        return parseIdFieldsSingle($raw, $idType);

    $results = array_map(fn($p) => parseIdFieldsSingle($p, $idType), $passes);

    $best = 0;
    $bestScore = -1;
    foreach ($results as $i => $r) {
        $s = idFieldsScore($r);
        if ($s > $bestScore) {
            $bestScore = $s;
            $best = $i;
        }
    }

    $out = $results[$best];

    foreach ($results as $i => $r) {
        if ($i === $best)
            continue;

        // fill what the best scan could not read
        foreach (['firstName', 'lastName', 'birthDate'] as $k)
            if ($out[$k] === '' && $r[$k] !== '')
                $out[$k] = $r[$k];

        // middle name / extension only when the other scan read the same first + last name
        if (strcasecmp($r['firstName'], $out['firstName']) === 0 && strcasecmp($r['lastName'], $out['lastName']) === 0) {
            if ($out['middleName'] === 'N/A' && $r['middleName'] !== 'N/A')
                $out['middleName'] = $r['middleName'];
            if ($out['extension'] === 'N/A' && $r['extension'] !== 'N/A')
                $out['extension'] = $r['extension'];
        }
    }

    return $out;
}

/* ---------- AGE ---------- */
/** Returns the age in years, or null when the date is invalid / in the future. */
function calcAge(string $birthDate): ?int
{
    $birth = DateTime::createFromFormat('Y-m-d', $birthDate);
    if (!$birth || $birth->format('Y-m-d') !== $birthDate)
        return null;

    $today = new DateTime('today');
    $age = (int) $birth->diff($today)->y;

    return $birth > $today ? null : $age;
}

/* ---------- STEP 1 ---------- */
function validatePasswords(string $password, string $confirm): array
{
    if (strlen($password) < 8)
        return [false, "Password must be at least 8 characters."];
    if ($password !== $confirm)
        return [false, "Passwords do not match."];
    return [true, ""];
}

/* ---------- DATABASE: duplicate check ---------- */
/** Returns "" when free, otherwise a message saying what is already taken. */
function accountTakenMessage(string $username, string $email): string
{
    $st = db()->prepare(
        'SELECT (LOWER(username) = LOWER(:u)) AS u_taken, (LOWER(email) = LOWER(:e)) AS e_taken
           FROM users
          WHERE LOWER(username) = LOWER(:u2) OR LOWER(email) = LOWER(:e2)'
    );
    $st->execute([':u' => $username, ':e' => $email, ':u2' => $username, ':e2' => $email]);

    $userTaken = false;
    $emailTaken = false;
    foreach ($st->fetchAll() as $row) {
        $userTaken = $userTaken || $row['u_taken'];
        $emailTaken = $emailTaken || $row['e_taken'];
    }

    if ($userTaken)
        return "That username is already taken.";
    if ($emailTaken)
        return "That email is already registered.";
    return "";
}

/* ---------- FACE MATCH ---------- */
function faceMatches(float $distance): bool
{
    return $distance <= MATCH_THRESHOLD;
}

/* ---------- FACE LOGIN: save the face from the Valid ID at sign-up ---------- */
function validFaceDescriptor($d): bool
{
    if (!is_array($d) || count($d) !== 128)
        return false;
    foreach ($d as $v)
        if (!is_int($v) && !is_float($v))
            return false;
    return true;
}

// same table that login.php reads
function ensureFaceProfilesTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS face_profiles (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL,
            role TEXT NOT NULL,
            username TEXT,
            descriptor TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )'
    );
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo BYTEA');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo_mime TEXT');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS photo_file TEXT');
    $pdo->exec('ALTER TABLE face_profiles ADD COLUMN IF NOT EXISTS user_id INTEGER');
}

/* ============================================================
   REQUEST HANDLING (the same file answers the page's calls)
   ============================================================ */
function fail(string $message, int $code = 422): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'ok' => false, 'message' => $message]);
    exit;
}

function saveImage(string $field, string $prefix): string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK)
        fail('Image upload failed.');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024)
        fail('Maximum image size is 5 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext)
        fail('Only JPG, PNG or WEBP images are allowed.');

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir))
        mkdir($dir, 0750, true);

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$name"))
        fail('Could not save the image.', 500);
    return $name;
}

function deleteUpload(string $name): void
{
    $path = __DIR__ . '/uploads/' . basename($name);
    if (is_file($path))
        @unlink($path);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    /* ----- final submit ----- */
    if (($_POST['action'] ?? '') === 'register') {
        $p = fn(string $k) => trim((string) ($_POST[$k] ?? ''));

        foreach (['username', 'email', 'password', 'confirmPassword', 'phone', 'idType'] as $f) {
            if ($p($f) === '')
                fail('Please fill in all required fields.');
        }
        if (!filter_var($p('email'), FILTER_VALIDATE_EMAIL))
            fail('Invalid email address.');

        [$ok, $msg] = validatePasswords((string) $_POST['password'], (string) $_POST['confirmPassword']);
        if (!$ok)
            fail($msg);

        if (($_POST['consent'] ?? '') !== '1')
            fail('Please provide consent.');

        [$ok, $msg] = checkIdType($p('idType'), (string) ($_POST['idText'] ?? ''));
        if (!$ok)
            fail($msg);

        // name + birth date always come from the ID itself (the form values are ignored)
        $idInfo = parseIdFields((string) ($_POST['idText'] ?? ''), $p('idType'));
        $missing = missingIdInfo($idInfo);
        if ($missing)
            fail('We could not read your ' . implode(', ', $missing) . ' from the ID. Please upload a clearer photo of the front.');
        if (calcAge($idInfo['birthDate']) === null)
            fail('Invalid birth date.');

        if (!isset($_POST['idFaceDistance']) || !faceMatches((float) $_POST['idFaceDistance'])) {
            fail('Your face does not match your Valid ID.');
        }

        // face print of the Valid ID, computed in the browser (used for Face Login)
        $idDescriptor = json_decode((string) ($_POST['idDescriptor'] ?? ''), true);
        if (!validFaceDescriptor($idDescriptor)) {
            fail('Could not read your face from the Valid ID. Please upload the ID again.');
        }

        try {
            $pdo = db();

            // ID type must exist in the id_types table
            $st = $pdo->prepare('SELECT id FROM id_types WHERE name = :n');
            $st->execute([':n' => $p('idType')]);
            $idTypeId = $st->fetchColumn();
            if ($idTypeId === false)
                fail('Invalid ID type.');

            // username / email must be free
            $taken = accountTakenMessage($p('username'), $p('email'));
            if ($taken !== '')
                fail($taken, 409);
        } catch (PDOException $e) {
            error_log('signup db error: ' . $e->getMessage());
            fail('Could not connect to the database. Please try again later.', 500);
        }

        $validIdFile = saveImage('validId', 'id');
        $faceFile = saveImage('faceImage', 'face');

        $passwordHash = password_hash((string) $_POST['password'], PASSWORD_DEFAULT);
        $nullIfEmpty = fn(string $v) => $v === '' ? null : $v;

        try {
            ensureFaceProfilesTable($pdo);

            $pdo->beginTransaction();

            // 1) users
            $st = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash)
                 VALUES (:username, :email, :hash)
                 RETURNING id'
            );
            $st->execute([
                ':username' => $p('username'),
                ':email' => $p('email'),
                ':hash' => $passwordHash,
            ]);
            $userId = (int) $st->fetchColumn();

            // 2) user_profiles
            $st = $pdo->prepare(
                'INSERT INTO user_profiles
                    (user_id, first_name, middle_name, last_name, extension_name, birth_date, phone)
                 VALUES (:uid, :first, :middle, :last, :ext, :birth, :phone)'
            );
            $st->execute([
                ':uid' => $userId,
                ':first' => $idInfo['firstName'],
                ':middle' => $idInfo['middleName'],       // "N/A" when the ID has none
                ':last' => $idInfo['lastName'],
                ':ext' => $idInfo['extension'],           // "N/A" when the ID has none
                ':birth' => $idInfo['birthDate'],
                ':phone' => $p('phone'),
            ]);

            // 3) user_verifications
            $st = $pdo->prepare(
                'INSERT INTO user_verifications
                    (user_id, id_type_id, valid_id_path, face_image_path, face_distance, consent_given)
                 VALUES (:uid, :type, :idfile, :facefile, :dist, TRUE)'
            );
            $st->execute([
                ':uid' => $userId,
                ':type' => (int) $idTypeId,
                ':idfile' => $validIdFile,
                ':facefile' => $faceFile,
                ':dist' => round((float) $_POST['idFaceDistance'], 4),
            ]);

            // 4) face_profiles: Face Login works right away, no password login needed first
            $ext = strtolower(pathinfo($validIdFile, PATHINFO_EXTENSION));
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
            $fp = fopen(__DIR__ . '/uploads/' . $validIdFile, 'rb');

            $st = $pdo->prepare(
                'INSERT INTO face_profiles (name, role, username, user_id, descriptor, photo, photo_mime, photo_file)
                 VALUES (:n, :r, :u, :uid, :d, :p, :m, :f)'
            );
            $st->bindValue(':n', trim($idInfo['firstName'] . ' ' . $idInfo['lastName']));
            $st->bindValue(':r', 'user');
            $st->bindValue(':u', $p('username'));
            $st->bindValue(':uid', $userId, PDO::PARAM_INT);
            $st->bindValue(':d', json_encode(array_values($idDescriptor)));
            $st->bindParam(':p', $fp, PDO::PARAM_LOB);
            $st->bindValue(':m', $mime);
            $st->bindValue(':f', $validIdFile);
            $st->execute();
            if (is_resource($fp))
                fclose($fp);

            $pdo->commit();

            // log the new employee in, so the dashboard shows the username from sign-up
            session_regenerate_id(true);
            $_SESSION['login_fails'] = 0;
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $p('username');
            $_SESSION['role'] = 'user';
        } catch (PDOException $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();

            // nothing was saved, so remove the uploaded images too
            deleteUpload($validIdFile);
            deleteUpload($faceFile);

            if ($e->getCode() === '23505')   // unique violation (someone registered at the same moment)
                fail('That username or email is already registered.', 409);

            error_log('signup insert error: ' . $e->getMessage());
            fail('Could not create your account. Please try again.', 500);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    /* ----- small logic calls from the page ----- */
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    switch ($in['action'] ?? '') {
        case 'age':
            $age = calcAge((string) ($in['birthDate'] ?? ''));
            echo json_encode(['ok' => $age !== null, 'age' => $age]);
            break;

        case 'validateStep':
            if ((int) ($in['step'] ?? -1) === 0) {
                [$ok, $msg] = validatePasswords((string) ($in['password'] ?? ''), (string) ($in['confirmPassword'] ?? ''));

                // also check the username / email are not used yet (database)
                if ($ok) {
                    $username = trim((string) ($in['username'] ?? ''));
                    $email = trim((string) ($in['email'] ?? ''));
                    try {
                        $msg = accountTakenMessage($username, $email);
                        $ok = $msg === '';
                    } catch (PDOException $e) {
                        error_log('signup db error: ' . $e->getMessage());
                        $ok = false;
                        $msg = 'Could not connect to the database. Please try again later.';
                    }
                }
                echo json_encode(['ok' => $ok, 'message' => $msg]);
            } else {
                echo json_encode(['ok' => true, 'message' => '']);
            }
            break;

        case 'idType':
            $idTypeSel = (string) ($in['idType'] ?? '');
            $idTextIn = (string) ($in['text'] ?? '');
            [$ok, $msg] = checkIdType($idTypeSel, $idTextIn);

            $reply = ['ok' => $ok, 'message' => $msg];
            // ID accepted  ->  the first name, last name and birth date must be readable too
            if ($ok) {
                $fields = parseIdFields($idTextIn, $idTypeSel);
                $missing = missingIdInfo($fields);
                if ($missing) {
                    $reply['ok'] = false;
                    $reply['message'] = 'ID type detected ✓ but we could not read your ' . implode(', ', $missing) .
                        ' clearly. Upload a clear, well-lit, uncropped photo of the front of the ID.';
                } else {
                    $reply['fields'] = $fields;
                }
            }

            echo json_encode($reply);
            break;

        case 'faceMatch':
            echo json_encode(['ok' => faceMatches((float) ($in['distance'] ?? 9))]);
            break;

        default:
            fail('Unknown action.', 400);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- Face recognition library (runs in the browser) -->
    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js"></script>

    <!-- OCR library (reads the text printed on the ID: type + personal information) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>

    <link rel="stylesheet" href="assets/style/signup.css?v=3">
</head>

<body>

    <main class="signup-box">

        <!-- LEFT: brand panel -->
        <aside class="cookie-panel">

            <div class="brand-row">
                <div class="brand-logo">B4</div>
                <div>
                    <div class="brand-name">B4jkery</div>
                    <div class="brand-sub">Inventory System</div>
                </div>
            </div>

            <div class="hero">
                <div class="hero-eyebrow">Cookie Inventory</div>
                <h2>Welcome! to B4jkery</h2>
                <p>Let’s keep your bakery well-stocked today.</p>
            </div>

            <div></div>

        </aside>

        <!-- RIGHT: blurred photo background + sign-up card -->
        <section class="signup-panel">

            <div class="signup-bg" aria-hidden="true"></div>

            <div class="signup-card">

                <div class="card-head">
                    <h1>Sign up</h1>
                    <p class="signup-subtitle" id="step-title">User Information</p>
                </div>

                <div class="step-indicator">
                    <span class="step-dot active">1</span>
                    <span class="step-line"></span>
                    <span class="step-dot">2</span>
                    <span class="step-line"></span>
                    <span class="step-dot">3</span>
                    <span class="step-line"></span>
                    <span class="step-dot">4</span>
                </div>

                <form id="signup-form">

                    <!-- STEP 1: USER INFORMATION -->
                    <div class="form-step active">
                        <div class="input-box">
                            <input class="form-control" type="text" name="username" placeholder="Create Username"
                                autocomplete="username" required>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="email" name="email" placeholder="Email"
                                autocomplete="email" required>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="password" id="password" name="password"
                                placeholder="Create Password" minlength="8" required>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="password" id="confirm-password" name="confirmPassword"
                                placeholder="Confirm Password" required>
                        </div>
                    </div>

                    <!-- STEP 2: VALID ID (validated first) -->
                    <div class="form-step">
                        <p class="section-description">Valid ID (Any Government ID)<br>
                            Your personal information will be filled in automatically from this ID.</p>

                        <div class="input-box">
                            <select class="form-control" name="idType" required>
                                <option value="">Select ID Type</option>
                                <option>National ID</option>
                                <option>Passport</option>
                                <option>Driver's License</option>
                                <option>UMID</option>
                                <option>PhilHealth ID</option>
                                <option>Other Government ID</option>
                            </select>
                        </div>

                        <div class="upload-box">
                            <label for="validId" class="upload-label">Upload Valid ID (clear photo of your face on the
                                ID)</label>
                            <input type="file" id="validId" name="validId" accept="image/*" required>
                            <img id="idPreview" class="id-preview" alt="Valid ID preview" hidden>
                            <p class="verify-status info" id="idStatus"></p>
                            <!-- result of the ID type check -->
                            <p class="verify-status info" id="idTypeStatus"></p>
                        </div>

                    </div>

                    <!-- STEP 3: PERSONAL INFORMATION (read from the ID, locked) -->
                    <div class="form-step">
                        <p class="section-description">These details were read from your Valid ID and cannot be
                            edited. If something is wrong, go back and upload a clearer photo of your ID.</p>
                        <p class="verify-status info" id="autofillStatus"></p>

                        <div class="input-box">
                            <input class="form-control" type="text" name="firstName" placeholder="First Name" readonly
                                tabindex="-1" required>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="text" name="middleName" placeholder="Middle Name" readonly
                                tabindex="-1">
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="text" name="lastName" placeholder="Last Name" readonly
                                tabindex="-1" required>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="text" name="extension" placeholder="Extension Name"
                                readonly tabindex="-1">
                        </div>
                        <div class="input-row">
                            <div class="input-box">
                                <input class="form-control" type="date" id="birthDate" name="birthDate" readonly
                                    tabindex="-1" required>
                            </div>
                            <div class="input-box">
                                <input class="form-control" type="number" id="age" name="age" placeholder="Age"
                                    readonly tabindex="-1" required>
                            </div>
                        </div>
                        <div class="input-box">
                            <input class="form-control" type="tel" name="phone" placeholder="Phone Number" required>
                        </div>
                    </div>

                    <!-- STEP 4: FACE VERIFICATION (liveness) -->
                    <div class="form-step">
                        <p class="section-description">Face Verification</p>

                        <div class="liveness-wrap" id="livenessWrap">
                            <svg class="liveness-ring" viewBox="0 0 240 240" aria-hidden="true">
                                <circle class="ring-track" cx="120" cy="120" r="112"></circle>
                                <circle class="ring-progress" id="ringProgress" cx="120" cy="120" r="112"></circle>
                            </svg>
                            <div class="liveness-circle">
                                <video id="camera" autoplay muted playsinline hidden></video>
                                <img id="facePreview" alt="Captured face" hidden>
                                <span id="cameraPlaceholder">Tap “Start” to begin</span>
                            </div>
                            <div class="liveness-check" id="livenessCheck" hidden>✓</div>
                        </div>

                        <p class="live-instruction" id="liveInstruction">Get ready to verify your face</p>
                        <div class="live-chips" id="liveChips"></div>
                        <p class="verify-status info" id="faceStatus"></p>

                        <div class="camera-actions">
                            <button type="button" class="secondary-btn" id="startVerify">Start Face
                                Verification</button>
                        </div>

                        <label class="consent-label">
                            <input type="checkbox" id="consent" required>
                            I consent to face image processing for verification.
                        </label>
                    </div>

                    <p id="form-message" class="form-message"></p>

                    <!-- NAVIGATION -->
                    <div class="button-group">
                        <button type="button" class="back-btn" id="backBtn" hidden>Back</button>
                        <button type="button" class="signup-btn" id="nextBtn">Next</button>
                        <button type="submit" class="signup-btn" id="submitBtn" hidden>Confirm</button>
                    </div>

                    <p class="login-text">
                        Already have an account?
                        <a href="login.php" class="login-link">Log In</a>
                    </p>

                </form>

            </div>

        </section>
    </main>

    <script>
        /* ================= CONFIG ================= */
        const REGISTER_URL = "signup.php";   // this same file handles the submit
        const API_URL = "signup.php";        // this same file runs the PHP logic
        const LIVENESS = {
            turnRight: 0.38,    // nose position ratio below this = turned right
            turnLeft: 0.62,     // nose position ratio above this = turned left
            centerMin: 0.43,    // "looking straight" range
            centerMax: 0.57,
            blinkClose: 0.88,   // eye counts as closed below 88% of its recent open level
            blinkOpen: 0.94,    // eye counts as reopened above 94% of its open level
            holdMs: 700         // how long to hold still when looking straight
        };
        const MODEL_URL = "https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/";

        // personal-information fields that are filled in from the ID
        const AUTO_FIELDS = ["firstName", "middleName", "lastName", "extension", "birthDate"];

        /* ================= PHP LOGIC CALL ================= */
        async function api(action, payload = {}) {
            const res = await fetch(API_URL, {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({action, ...payload})
            });
            return res.json();
        }

        /* ================= STATE ================= */
        const form = document.getElementById("signup-form");
        const steps = [...document.querySelectorAll(".form-step")];
        const dots = [...document.querySelectorAll(".step-dot")];
        const titles = [
            "User Information",
            "Valid ID",
            "Personal Information",
            "Face Verification"
        ];
        const STEP_ID = 1;           // index of the Valid ID step
        const LAST = steps.length - 1;

        let current = 0;
        let stream = null;
        let captured = false;
        let faceVerified = false;
        let faceBlob = null;
        let faceScores = {};
        let idDescriptor = null;
        let busy = false;
        let idText = null;        // text read from the uploaded ID (null = not read yet)
        let idTypeOk = false;     // true when the ID matches the selected ID type

        /* ================= MODEL LOADING ================= */
        const modelsPromise = Promise.all([
            faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
        ]).then(() => true).catch(() => false);

        function setStatus(id, text, type = "info") {
            const el = document.getElementById(id);
            el.textContent = text;
            el.className = "verify-status " + type;
        }

        async function getDescriptor(source) {
            const result = await faceapi
                .detectSingleFace(source, new faceapi.SsdMobilenetv1Options({minConfidence: 0.5}))
                .withFaceLandmarks()
                .withFaceDescriptor();
            return result ? result.descriptor : null;
        }

        /* ================= STEPS ================= */
        function showStep(index) {
            current = index;

            steps.forEach((step, i) => step.classList.toggle("active", i === index));
            dots.forEach((dot, i) => dot.classList.toggle("active", i <= index));

            document.getElementById("step-title").textContent = titles[index];
            document.getElementById("backBtn").hidden = index === 0;
            document.getElementById("nextBtn").hidden = index === LAST;
            document.getElementById("submitBtn").hidden = index !== LAST;
            document.getElementById("form-message").textContent = "";

            if (index !== LAST) stopCamera();
        }

        async function validateStep() {
            // personal information step: the locked fields must hold the data read from the ID
            if (current === STEP_ID + 1) {
                const f = form.elements;
                if (!f["firstName"].value || !f["lastName"].value || !f["birthDate"].value) {
                    alert("We could not read your details from the Valid ID. Please upload a clearer photo.");
                    showStep(STEP_ID);
                    return false;
                }
            }

            const fields = steps[current].querySelectorAll("input:not([type=checkbox]), select");

            for (const field of fields) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }

            if (busy) {
                alert("Please wait, the image is still being analyzed.");
                return false;
            }

            // password check + "username/email already used" check run in PHP
            if (current === 0) {
                try {
                    const r = await api("validateStep", {
                        step: 0,
                        username: form.elements["username"].value,
                        email: form.elements["email"].value,
                        password: document.getElementById("password").value,
                        confirmPassword: document.getElementById("confirm-password").value
                    });
                    if (!r.ok) {
                        alert(r.message || "Passwords do not match.");
                        return false;
                    }
                } catch {
                    alert("Could not reach the server. Please try again.");
                    return false;
                }
            }

            // Valid ID step: the ID must have a readable face, match the selected type
            // and show a readable first name, last name and birth date
            if (current === STEP_ID && !idDescriptor) {
                alert("We could not read a face from your Valid ID. Please upload a clearer photo.");
                return false;
            }

            if (current === STEP_ID && !idTypeOk) {
                alert("The uploaded ID does not match the selected ID type, or your name / birth date " +
                    "could not be read from it. Please upload a clearer photo of the correct ID.");
                return false;
            }

            if (current === LAST) {
                if (!faceVerified) {
                    alert("Please complete the face verification first.");
                    return false;
                }
            }

            return true;
        }

        document.getElementById("nextBtn").onclick = async () => {
            if (await validateStep()) showStep(current + 1);
        };

        document.getElementById("backBtn").onclick = () => {
            if (current > 0) showStep(current - 1);
        };

        /* ================= AGE (calculated in PHP) ================= */
        const birthInput = document.getElementById("birthDate");
        birthInput.max = new Date().toISOString().split("T")[0];

        birthInput.addEventListener("change", async e => {
            const ageInput = document.getElementById("age");

            if (!e.target.value) {
                ageInput.value = "";
                return;
            }

            try {
                const r = await api("age", {birthDate: e.target.value});
                ageInput.value = r.ok ? r.age : "";
            } catch {
                ageInput.value = "";
            }
        });

        /* ================= AUTO-FILL PERSONAL INFORMATION FROM THE ID =================
           PHP reads the OCR text and returns the fields. They are locked
           (readonly) so the user cannot type a different name; PHP also
           re-reads them from the ID when the form is submitted.
        ================================================================ */
        function clearAutofill() {
            for (const name of AUTO_FIELDS) form.elements[name].value = "";
            document.getElementById("age").value = "";
            setStatus("autofillStatus", "", "info");
        }

        function applyAutofill(fields) {
            for (const name of AUTO_FIELDS) form.elements[name].value = fields[name] || "";

            if (fields.birthDate) birthInput.dispatchEvent(new Event("change"));   // calculates the age

            setStatus("autofillStatus", "Filled in automatically from your ID ✓", "ok");
        }

        /* ================= ID TYPE DETECTION (matching runs in PHP) =================
           The browser still reads the text off the ID (OCR); PHP decides
           whether that text matches the selected ID type and, when it does,
           also reads the personal information from it.
           quiet = true: do not show a failure message (another scan will follow).
           Returns true when the ID was accepted.
        ================================================================ */
        async function checkIdType(quiet = false) {
            const selected = document.querySelector("[name=idType]").value;

            idTypeOk = false;
            clearAutofill();

            if (idText === null) {
                setStatus("idTypeStatus", "", "info");
                return false;
            }
            if (!selected) {
                if (!quiet) setStatus("idTypeStatus", "Select the ID type above.", "error");
                return false;
            }

            try {
                const r = await api("idType", {idType: selected, text: idText});
                idTypeOk = !!r.ok;
                if (r.ok || !quiet) setStatus("idTypeStatus", r.message, r.ok ? "ok" : "error");
                if (r.ok) applyAutofill(r.fields || {});
            } catch {
                setStatus("idTypeStatus", "Could not reach the server. Please try again.", "error");
            }
            return idTypeOk;
        }

        /* ----- OCR ----- */
        // Philippine IDs are printed on shiny / patterned cards, so one scan is often not enough.
        // The ID is scanned with up to 3 different settings; scanning stops as soon as PHP can read
        // the ID type, the names and the birth date. All the texts are sent to PHP, which
        // keeps the best result.
        const PASS_SEP = "\n=====PASS=====\n";   // must be the same text as PASS_SEP in the PHP above
        const OCR_PASSES = [
            {psm: "3", width: 1800, contrast: 1.3, brightness: 0},     // automatic page layout
            {psm: "6", width: 2300, contrast: 1.8, brightness: 10},    // one uniform block, bigger + sharper
            {psm: "4", width: 1500, contrast: 2.2, brightness: -10}    // single column, harder contrast
        ];

        let ocrWorkerPromise = null;

        function getOcrWorker() {
            if (!ocrWorkerPromise) {
                ocrWorkerPromise = Tesseract.createWorker("eng").catch(err => {
                    ocrWorkerPromise = null;
                    throw err;
                });
            }
            return ocrWorkerPromise;
        }

        // resize + grayscale + contrast (done pixel by pixel so it also works on Safari / iPhone)
        function toOcrCanvas(img, pass) {
            const w = img.naturalWidth, h = img.naturalHeight;
            const scale = pass.width / w;

            const canvas = document.createElement("canvas");
            canvas.width = Math.round(w * scale);
            canvas.height = Math.round(h * scale);

            const ctx = canvas.getContext("2d", {willReadFrequently: true});
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const d = image.data;
            for (let i = 0; i < d.length; i += 4) {
                const gray = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
                let v = (gray - 128) * pass.contrast + 128 + pass.brightness;
                v = v < 0 ? 0 : v > 255 ? 255 : v;
                d[i] = d[i + 1] = d[i + 2] = v;
            }
            ctx.putImageData(image, 0, 0);
            return canvas;
        }

        async function readIdText(img, pass) {
            const worker = await getOcrWorker();
            await worker.setParameters({
                tessedit_pageseg_mode: pass.psm,
                preserve_interword_spaces: "1"
            });
            const result = await worker.recognize(toOcrCanvas(img, pass));
            return result.data.text || "";
        }

        document.querySelector("[name=idType]").addEventListener("change", () => checkIdType());

        /* ================= ID UPLOADS (extract face from ID) ================= */
        function resetFace() {
            stopCamera();
            captured = false;
            faceVerified = false;
            faceBlob = null;
            faceScores = {};
            document.getElementById("facePreview").hidden = true;
            document.getElementById("cameraPlaceholder").hidden = false;
            document.getElementById("livenessWrap").className = "liveness-wrap";
            document.getElementById("livenessCheck").hidden = true;
            document.getElementById("ringProgress").style.strokeDashoffset = RING_LENGTH;
            document.getElementById("liveInstruction").textContent = "Get ready to verify your face";
            document.getElementById("liveChips").innerHTML = "";
            const btn = document.getElementById("startVerify");
            btn.textContent = "Start Face Verification";
            btn.disabled = false;
            btn.hidden = false;
            setStatus("faceStatus", "", "info");
        }

        function setupIdUpload({inputId, previewId, statusId, label, onDescriptor}) {
            document.getElementById(inputId).addEventListener("change", async e => {
                const file = e.target.files[0];
                const preview = document.getElementById(previewId);

                onDescriptor(null);
                resetFace();

                // forget the previous ID's text, type result and auto-filled info
                idText = null;
                idTypeOk = false;
                clearAutofill();
                setStatus("idTypeStatus", "", "info");

                if (!file) {
                    preview.hidden = true;
                    setStatus(statusId, "", "info");
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    alert("Maximum image size is 5 MB.");
                    e.target.value = "";
                    preview.hidden = true;
                    setStatus(statusId, "", "info");
                    return;
                }

                preview.src = URL.createObjectURL(file);
                preview.hidden = false;

                busy = true;
                setStatus(statusId, "Analyzing " + label + "...", "info");

                try {
                    const ready = await modelsPromise;
                    if (!ready) throw new Error("models");

                    await preview.decode();
                    const descriptor = await getDescriptor(preview);

                    if (descriptor) {
                        onDescriptor(descriptor);
                        setStatus(statusId, "Face detected on " + label + " ✓", "ok");
                    } else {
                        setStatus(statusId,
                            "No face detected. Upload a clear, well-lit, uncropped photo of the ID.", "error");
                    }
                } catch {
                    setStatus(statusId,
                        "Face recognition could not load. Check your internet connection and refresh.", "error");
                }

                // read the text on the ID (browser OCR); PHP checks the ID type
                // and, when it matches, sends back the personal information.
                // If the first scan is not enough, the ID is scanned again with other settings.
                if (idDescriptor) {
                    try {
                        const texts = [];
                        for (let p = 0; p < OCR_PASSES.length; p++) {
                            setStatus("idTypeStatus", p === 0
                                ? "Reading your ID (this can take a few seconds)..."
                                : "Reading your ID again with a different scan (" + (p + 1) + "/" +
                                OCR_PASSES.length + ")...", "info");

                            texts.push(await readIdText(preview, OCR_PASSES[p]));
                            idText = texts.join(PASS_SEP);

                            const last = p === OCR_PASSES.length - 1;
                            if (await checkIdType(!last)) break;   // everything was read: stop scanning
                        }
                    } catch {
                        idText = null;
                        idTypeOk = false;
                        setStatus("idTypeStatus",
                            "The ID check could not load. Check your internet connection and upload the ID again.",
                            "error");
                    }
                }

                busy = false;
            });
        }

        setupIdUpload({
            inputId: "validId", previewId: "idPreview", statusId: "idStatus",
            label: "Valid ID", onDescriptor: d => (idDescriptor = d)
        });


        /* ================= LIVENESS FACE VERIFICATION ================= */
        const RING_LENGTH = 2 * Math.PI * 112;
        const camera = document.getElementById("camera");
        const wrap = document.getElementById("livenessWrap");
        const startBtn = document.getElementById("startVerify");
        const LABELS = {center: "Look straight", right: "Turn right", left: "Turn left", blink: "Blink"};
        const INSTRUCTIONS = {
            center: "Look straight at the camera",
            right: "Slowly turn your head to the RIGHT ➡️",
            left: "Now slowly turn your head to the LEFT ⬅️",
            blink: "Look straight and BLINK 👁️"
        };

        let session = 0;   // changes whenever a verification run is cancelled

        const nextFrame = () => new Promise(r => requestAnimationFrame(r));

        function setRing(fraction) {
            document.getElementById("ringProgress").style.strokeDashoffset = RING_LENGTH * (1 - fraction);
        }

        function renderChips(stages, done) {
            document.getElementById("liveChips").innerHTML = stages.map((st, i) =>
                `<span class="live-chip ${i < done ? "done" : i === done ? "current" : ""}">${i < done ? "✓ " : ""}${LABELS[st]}</span>`
            ).join("");
        }

        function stopCamera() {
            session++;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            camera.srcObject = null;
            camera.hidden = true;
        }

        async function openCamera() {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: "user", width: {ideal: 640}, height: {ideal: 480}},
                audio: false
            });
            camera.srcObject = stream;
            camera.hidden = false;
            document.getElementById("cameraPlaceholder").hidden = true;
            document.getElementById("facePreview").hidden = true;
            await camera.play().catch(() => { });
            while (!camera.videoWidth) await nextFrame();
        }

        function snapshot() {
            const canvas = document.createElement("canvas");
            canvas.width = camera.videoWidth;
            canvas.height = camera.videoHeight;
            canvas.getContext("2d").drawImage(camera, 0, 0, canvas.width, canvas.height);
            return canvas;
        }

        const dist2d = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

        function eyeRatio(p) {   // eye aspect ratio: smaller = more closed
            return (dist2d(p[1], p[5]) + dist2d(p[2], p[4])) / (2 * dist2d(p[0], p[3]));
        }

        function measure(det) {
            const pts = det.landmarks.positions;
            const nose = pts[30], jawA = pts[0], jawB = pts[16];
            return {
                // ~0.5 = straight; < 0.5 = user's right; > 0.5 = user's left
                ratio: (nose.x - jawA.x) / (jawB.x - jawA.x),
                ear: (eyeRatio(pts.slice(36, 42)) + eyeRatio(pts.slice(42, 48))) / 2,
                box: det.detection.box
            };
        }

        async function waitFor(id, check, inputSize = 320) {
            const options = new faceapi.TinyFaceDetectorOptions({inputSize, scoreThreshold: 0.5});

            while (true) {
                if (id !== session) throw new Error("cancelled");

                const det = await faceapi.detectSingleFace(camera, options).withFaceLandmarks();
                if (id !== session) throw new Error("cancelled");

                if (!det) {
                    setStatus("faceStatus", "No face detected. Stay inside the circle.", "info");
                } else {
                    const m = measure(det);
                    const w = m.box.width / camera.videoWidth;
                    const cx = (m.box.x + m.box.width / 2) / camera.videoWidth;

                    if (w < 0.22) setStatus("faceStatus", "Move a little closer.", "info");
                    else if (w > 0.8) setStatus("faceStatus", "Move a little farther.", "info");
                    else if (cx < 0.25 || cx > 0.75) setStatus("faceStatus", "Center your face in the circle.", "info");
                    else {
                        setStatus("faceStatus", "", "info");
                        if (check(m)) return m;
                    }
                }
                await nextFrame();
            }
        }

        async function runLiveness(id) {
            const challenges = ["right", "left", "blink"].sort(() => Math.random() - 0.5);
            const stages = ["center", ...challenges];
            let faceCanvas = null;

            setRing(0);

            for (let i = 0; i < stages.length; i++) {
                const stage = stages[i];
                renderChips(stages, i);
                document.getElementById("liveInstruction").textContent = INSTRUCTIONS[stage];

                let holdStart = 0, closed = false, closedAt = 0;
                const earSamples = [];
                const recentEar = [];

                const checks = {
                    center: m => {
                        if (m.ratio < LIVENESS.centerMin || m.ratio > LIVENESS.centerMax) {
                            holdStart = 0; earSamples.length = 0;
                            return false;
                        }
                        if (!holdStart) holdStart = performance.now();
                        earSamples.push(m.ear);
                        return performance.now() - holdStart >= LIVENESS.holdMs;
                    },
                    right: m => m.ratio < LIVENESS.turnRight,
                    left: m => m.ratio > LIVENESS.turnLeft,
                    blink: m => {
                        if (m.ratio < 0.25 || m.ratio > 0.75) return false;   // roughly facing the camera
                        const now = performance.now();

                        // collect a few "open eye" samples first
                        if (recentEar.length < 5) {
                            recentEar.push(m.ear);
                            return false;
                        }

                        const sorted = [...recentEar].sort((a, b) => a - b);
                        const open = sorted[Math.floor(sorted.length / 2)];

                        if (!closed) {
                            if (m.ear < open * LIVENESS.blinkClose) {
                                closed = true;
                                closedAt = now;
                            } else {
                                recentEar.push(m.ear);                 // keep tracking the open level
                                if (recentEar.length > 12) recentEar.shift();
                            }
                            return false;
                        }

                        if (m.ear > open * LIVENESS.blinkOpen) return true;   // eye reopened = blink done
                        if (now - closedAt > 1500) closed = false;            // eyes stuck "closed"? reset
                        return false;
                    }
                };

                await waitFor(id, checks[stage], stage === "blink" ? 224 : 320);

                if (stage === "center") {
                    // compare the straight-on face with the uploaded Valid ID
                    document.getElementById("liveInstruction").textContent = "Checking your face...";
                    faceCanvas = snapshot();
                    const live = await getDescriptor(faceCanvas);
                    if (id !== session) throw new Error("cancelled");
                    if (!live) throw new Error("noface");

                    const d = faceapi.euclideanDistance(live, idDescriptor);
                    faceScores = {idDistance: d};

                    // the match threshold is decided in PHP
                    let match;
                    try {
                        match = await api("faceMatch", {distance: d});
                    } catch {
                        throw new Error("server");
                    }
                    if (id !== session) throw new Error("cancelled");
                    if (!match.ok) throw new Error("mismatch");
                }

                setRing((i + 1) / stages.length);
            }

            renderChips(stages, stages.length);

            // success: ring is fully green
            faceBlob = await new Promise(res => faceCanvas.toBlob(res, "image/jpeg", 0.92));
            document.getElementById("facePreview").src = faceCanvas.toDataURL("image/jpeg", 0.92);
            stopCamera();
            document.getElementById("facePreview").hidden = false;

            captured = true;
            faceVerified = true;
            wrap.className = "liveness-wrap success";
            document.getElementById("livenessCheck").hidden = false;
            document.getElementById("liveInstruction").textContent = "Verification complete!";
            setStatus("faceStatus", "Face verified ✓ You can now press Confirm.", "ok");
            startBtn.hidden = true;
        }

        startBtn.onclick = async () => {
            if (!idDescriptor) {
                alert("Please upload your Valid ID with a visible face first.");
                return;
            }

            resetFace();
            startBtn.disabled = true;
            startBtn.textContent = "Verifying...";

            let ok = false;
            try {
                if (!(await modelsPromise)) throw new Error("models");
                await openCamera();
                const id = session;
                wrap.className = "liveness-wrap scanning";
                await runLiveness(id);
                ok = true;
            } catch (err) {
                if (err.message === "cancelled") return;

                stopCamera();
                wrap.className = "liveness-wrap error";
                document.getElementById("cameraPlaceholder").hidden = false;
                document.getElementById("liveInstruction").textContent = "Verification failed";

                const messages = {
                    timeout: "Time's up. Please try again and follow the instructions.",
                    mismatch: "Your face does not match your Valid ID. Try again or upload a clearer ID.",
                    noface: "We couldn't read your face. Try again in good lighting.",
                    models: "Face recognition could not load. Check your connection and refresh.",
                    server: "Could not reach the server. Please try again."
                };
                setStatus("faceStatus",
                    messages[err.message] || "Please allow camera access and try again.", "error");
            } finally {
                if (!ok) {
                    startBtn.disabled = false;
                    startBtn.textContent = "Try Again";
                }
            }
        };

        /* ================= SUBMIT ================= */
        form.addEventListener("submit", async event => {
            event.preventDefault();

            if (!(await validateStep())) return;

            if (!document.getElementById("consent").checked) {
                alert("Please provide consent.");
                return;
            }

            const message = document.getElementById("form-message");
            const submitBtn = document.getElementById("submitBtn");

            const data = new FormData(form);          // includes the validId file
            data.append("faceImage", faceBlob, "face.jpg");
            data.append("idFaceDistance", faceScores.idDistance);
            // face print of the Valid ID: saved by PHP so Face Login works right after sign-up
            data.append("idDescriptor", JSON.stringify(Array.from(idDescriptor, v => Math.round(v * 10000) / 10000)));
            data.append("idText", idText || "");      // PHP re-checks the ID type and re-reads the names
            data.append("consent", "1");              // saved in the database (consent_given)
            data.append("action", "register");

            submitBtn.disabled = true;
            message.textContent = "Creating your account...";

            try {
                const res = await fetch(REGISTER_URL, {method: "POST", body: data});
                const result = await res.json().catch(() => ({}));

                if (res.ok && result.success !== false) {
                    message.textContent = "Account created! Opening your dashboard...";
                    setTimeout(() => (window.location.href = "staffdash.php"), 1500);
                } else {
                    message.textContent = result.message || "Registration failed. Please try again.";
                    submitBtn.disabled = false;
                }
            } catch {
                message.textContent = "Could not reach the server. Please try again.";
                submitBtn.disabled = false;
            }
        });

        window.addEventListener("pagehide", stopCamera);

        showStep(0);
    </script>

</body>

</html>