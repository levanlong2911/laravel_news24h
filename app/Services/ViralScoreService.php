<?php

namespace App\Services;

use App\Models\Keyword;
use App\Models\NewsSource;
use App\Models\NewsWeb;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ViralScoreService — Facebook Viral Score Calculator
 *
 * Đánh giá từ title/snippet/source của bài lấy từ Google News.
 * 15 signals — max 194 raw → normalize 0-100
 */
class ViralScoreService
{
    private const MAX_RAW_SCORE = 194;

    private ?array $tier1Domains = null;
    private ?array $tier2Domains = null;

    // ── A. TITLE SIGNALS ──────────────────────────────────────────────────────

    private const POWER_WORDS = [
        'negative_shock' => [
            'words'  => [
                'ban', 'banned', 'fired', 'arrested', 'collapsed',
                'failed', 'disaster', 'crisis', 'scandal', 'exposed',
                'caught', 'accused', 'suspended', 'fined', 'sued',
                'bankrupt', 'shutdown', 'cancelled', 'denied',
                'rejected', 'stolen', 'leaked', 'hacked', 'crashed',
            ],
            'points' => 9,
        ],
        'urgency' => [
            'words'  => [
                'breaking', 'just in', 'alert', 'now', 'hours ago',
                'developing', 'urgent', 'immediately', 'official',
                'confirmed', 'announced', 'effective immediately',
                'just announced', 'just confirmed',
            ],
            'points' => 8,
        ],
        'controversy' => [
            'words'  => [
                'vs', 'versus', 'slams', 'attacks', 'calls out',
                'responds', 'fires back', 'denies', 'defends',
                'blasts', 'feuds', 'clashes', 'disagrees',
                'backlash', 'outrage', 'debate', 'divided',
                'sparks', 'reaction', 'criticism',
            ],
            'points' => 8,
        ],
        'personal_threat' => [
            'words'  => [
                'warning', 'danger', 'risk', 'deadly', 'toxic',
                'recall', 'unsafe', 'avoid', 'stop', 'harmful',
                'kills', 'linked to', 'side effects', 'alert',
            ],
            'points' => 8,
        ],
        'superlative' => [
            'words'  => [
                'biggest', 'largest', 'first ever', 'never before',
                'record-breaking', 'historic', 'all-time', 'worst ever',
                'best ever', 'unprecedented', 'most expensive',
                'number one', 'only one', 'rarest',
            ],
            'points' => 7,
        ],
        'positive_shock' => [
            'words'  => [
                'wins', 'won', 'champion', 'victory', 'saved',
                'survived', 'comeback', 'miracle', 'finally',
                'breakthrough', 'game-changer', 'transforms',
                'beats', 'defeats', 'overcomes', 'achieves',
            ],
            'points' => 7,
        ],
        'curiosity_gap' => [
            'words'  => [
                'secret', 'hidden', 'nobody knew', 'untold',
                'real reason', 'truth about', 'what really',
                'here\'s why', 'this is why', 'the reason',
                'what happened', 'inside story', 'behind the scenes',
                'exclusive', 'reveals', 'you won\'t believe',
            ],
            'points' => 6,
        ],
        'money_number' => [
            'words'  => [],
            'regex'  => '/\$[\d,.]+[MBKmb]?|\d+[\d,.]*\s*(million|billion|thousand)/i',
            'points' => 5,
        ],
        'identity' => [
            'words'  => [
                'fans react', 'twitter reacts', 'everyone',
                'nobody', 'you need to', 'we need to talk about',
                'this changes everything', 'what this means',
            ],
            'points' => 5,
        ],
    ];

    private const NEGATIVE_HOOK_PATTERNS = [
        '/no longer/i'                          => 8,
        '/is over|it\'s over/i'                 => 7,
        '/nobody wants|no one wants/i'          => 7,
        '/fails? to|failed to/i'                => 6,
        '/the end of/i'                         => 7,
        '/why .+ is wrong/i'                    => 6,
        '/won\'t .+ anymore|will no longer/i'   => 7,
        '/the problem with/i'                   => 5,
        '/not what you think/i'                 => 6,
        '/nobody (saw|expected|noticed)/i'      => 7,
        '/walking away|steps down|retires/i'    => 6,
        '/loses? (contract|deal|job|spot)/i'    => 6,
    ];

    private const FB_TITLE_SIGNALS = [
        '/^this /i'                            => 2,
        '/you |your /i'                        => 2,
        '/we |our /i'                          => 2,
        '/today|tonight|this week|right now/i' => 2,
        '/exclusive|first look|revealed/i'     => 3,
        '/tag someone|share this/i'            => 3,
        '/\?\s*$/i'                            => 2,
    ];

    // ── B. EMOTION SIGNALS ────────────────────────────────────────────────────

    private const EMOTION_KEYWORDS = [
        25 => [
            'shocking', 'scandal', 'outrage', 'betrayal', 'disgrace',
            'disgusting', 'unacceptable', 'amazing', 'incredible win',
            'proud', 'victory', 'champion', 'legend',
        ],
        20 => [
            'nobody expected', 'stunning', 'unbelievable',
            'out of nowhere', 'shocking twist', 'jaw-dropping',
            'no one saw', 'blindsided',
        ],
        15 => [
            'finally', 'hope', 'inspiring', 'comeback', 'redemption',
            'overcomes', 'beats the odds', 'rises up',
        ],
        10 => [
            'heartbreaking', 'sad', 'tragedy', 'devastating',
            'loss', 'grief', 'mourning', 'passing',
        ],
        5  => [
            'interesting', 'curious', 'wonder', 'fascinating',
        ],
    ];

    private const COMMENT_TRIGGERS = [
        'two_side' => [
            'patterns' => [
                '/patriots|cowboys|eagles|chiefs|steelers|49ers/i',
                '/ferrari|mercedes|red bull/i',
                '/mahomes|allen|burrow|jackson/i',
            ],
            'points' => 15,
        ],
        'blame' => [
            'patterns' => [
                '/blame|fault|responsible|caused/i',
                '/should have|could have|mistake/i',
            ],
            'points' => 12,
        ],
        'defense' => [
            'patterns' => [
                '/overrated|underrated/i',
                '/doesn\'t deserve|should be/i',
                '/worst|greatest of all time|goat/i',
            ],
            'points' => 10,
        ],
        'personal' => [
            'patterns' => [
                '/you |your |we |our /i',
                '/everyone|nobody|anybody/i',
            ],
            'points' => 8,
        ],
    ];

    // ── C. RECOGNITION SIGNALS ────────────────────────────────────────────────

    private const RECOGNITION_LISTS = [
        // NFL teams — 2026 season rosters (updated April 2026)
        'KANSAS CITY CHIEFS' => [
            'tier1' => ['Patrick Mahomes', 'Travis Kelce', 'Chris Jones', 'Andy Reid', 'Kenneth Walker III', 'Justin Fields'],
            'tier2' => ['George Karlaftis', 'Xavier Worthy', 'Creed Humphrey', 'R. Mason Thomas', 'Mansoor Delane', 'Peter Woods'],
        ],
        'DALLAS COWBOYS' => [
            'tier1' => ['Dak Prescott', 'CeeDee Lamb', 'George Pickens', 'Rashan Gary', 'Kenny Clark'],
            'tier2' => ['Jake Ferguson', 'Javonte Williams', 'Tyler Booker', 'Brandon Aubrey', 'Caleb Downs', 'Malachi Lawrence', 'Quinnen Williams', 'Jalen Thompson'],
        ],
        'PHILADELPHIA EAGLES' => [
            'tier1' => ['Jalen Hurts', 'A.J. Brown', 'Saquon Barkley', 'Jalen Carter', 'DeVonta Smith', 'Dallas Goedert'],
            'tier2' => ['Jonathan Greenard', 'Jordan Davis', 'Makai Lemon', 'Eli Stowers', 'Cooper DeJean'],
        ],
        'SAN FRANCISCO 49ERS' => [
            'tier1' => ['Brock Purdy', 'Christian McCaffrey', 'Nick Bosa', 'George Kittle', 'Brandon Aiyuk' ],
            'tier2' => ['Deebo Samuel', 'Trent Williams', 'Fred Warner', 'Mike Evans', 'Ricky Pearsall', 'Mykel Williams',],
        ],
        'PITTSBURGH STEELERS' => [
            'tier1' => ['T.J. Watt', 'DK Metcalf', 'Cam Heyward', 'Aaron Rodgers'],
            'tier2' => ['Michael Pittman Jr.', 'Pat Freiermuth', 'Alex Highsmith', 'Jalen Ramsey', 'Joey Porter Jr.', 'Jaylen Warren'],
        ],
        'GREEN BAY PACKERS' => [
            'tier1' => ['Jordan Love', 'Micah Parsons', 'Jayden Reed', 'Josh Jacobs', 'Xavier McKinney', 'Tucker Kraft', 'Lukas Van Ness', 'Christian Watson'],
            'tier2' => ['Devonte Wyatt', 'Javon Hargrave', 'Edgerrin Cooper', 'Keisean Nixon', 'Matthew Golden', 'Zach Tom'],
        ],
        'NEW ENGLAND PATRIOTS' => [
            'tier1' => ['Drake Maye', 'Rhamondre Stevenson', 'Christian Barmore', 'Christian Gonzalez', 'Hunter Henry', 'Will Campbell', 'TreVeyon Henderson', 'Milton Williams', 'Harold Landry III' ],
            'tier2' => ['Kayshon Boutte', 'Romeo Doubs', 'Kevin Byard', 'Robert Spillane', 'Marte Mapu', 'Caleb Lomu', 'Eli Raridon', 'Gabe Jacas' ],
        ],
        'CHICAGO BEARS' => [
            'tier1' => ['Caleb Williams', 'Montez Sweat', 'Rome Odunze', 'Luther Burden III', 'Colston Loveland', 'Cole Kmet', "D'Andre Swift", 'Darnell Wright', 'Montez Sweat', 'Jaylon Johnson'],
            'tier2' => ['Kyler Gordon', 'Grady Jarrett', 'Austin Booker', 'Devin Bush', 'Jahdae Walker', 'Kalif Raymond', 'Dillon Thieneman', 'Malik Muhammad', 'Keyshaun Elliott',],
        ],
        'NEW YORK GIANTS' => [
            'tier1' => ['Malik Nabers', 'Jaxson Dart', 'Andrew Thomas', 'Abdul Carter', 'Brian Burns', 'Kayvon Thibodeaux'],
            'tier2' => ["Tremaine Edmunds", 'Arvell Reese', 'Cam Skattebo', 'Francis Mauigoa', 'Isaiah Likely', 'Malachi Fields', 'Colton Hood', 'Jermaine Eluemunor'],
        ],
        'SHOWBIZ' => [
            'tier1' => [
                'Taylor Swift', 'Beyoncé', 'Elon Musk',
                'Kim Kardashian', 'Drake', 'Kanye West',
                'Rihanna', 'Adele', 'Justin Bieber',
            ],
            'tier2' => [
                'Selena Gomez', 'Dua Lipa', 'Bad Bunny',
                'Zendaya', 'Tom Holland', 'Billie Eilish',
            ],
        ],
        'AIRLINE' => [
            'tier1' => [
                'American Airlines', 'United Airlines',
                'Southwest Airlines', 'Delta Air Lines',
                'Alaska Airlines', 'JetBlue',
            ],
            'tier2' => [
                'Spirit Airlines', 'Frontier Airlines',
                'Allegiant',
            ],
        ],
        'FORMULA 1' => [
            'tier1' => [
                'Max Verstappen', 'Lewis Hamilton',
                'Charles Leclerc', 'Lando Norris',
                'Ferrari', 'Red Bull', 'Mercedes',
            ],
            'tier2' => [
                'Carlos Sainz', 'Fernando Alonso',
                'George Russell', 'Oscar Piastri',
            ],
        ],
        'GOLF' => [
            'tier1' => [
                'Tiger Woods', 'Rory McIlroy',
                'Scottie Scheffler', 'Jon Rahm',
                'PGA Tour', 'LIV Golf',
            ],
            'tier2' => [
                'Jordan Spieth', 'Justin Thomas',
                'Brooks Koepka', 'Bryson DeChambeau',
            ],
        ],
        'TENNIS' => [
            'tier1' => [
                'Carlos Alcaraz', 'Jannik Sinner',
                'Coco Gauff', 'Iga Swiatek', 'Aryna Sabalenka', 'US Open',
                'Wimbledon', 'Roland Garros', 'Australian Open'
            ],
            'tier2' => [
                'Ben Shelton', 'Taylor Fritz', 'Frances Tiafoe',
                'Jessica Pegula', 'Daniil Medvedev',
                'Wimbledon', 'US Open', 'Roland Garros',
            ],
        ],
        'CARS' => [
            'tier1' => [
                'Ferrari', 'Lamborghini', 'Bugatti',
                'McLaren', 'Porsche', 'Rolls-Royce',
                'Koenigsegg', 'Pagani',
            ],
            'tier2' => ['Aston Martin', 'Bentley', 'Maserati', 'Lotus', 'Rimac'],
        ],
        'MOTOGP' => [
            'tier1' => [
                'Marc Marquez', 'Francesco Bagnaia',
                'Jorge Martin', 'Ducati', 'Honda',
            ],
            'tier2' => ['Fabio Quartararo', 'Maverick Viñales', 'Yamaha', 'Aprilia'],
        ],
        'MOTO' => [
            'tier1' => ['Harley-Davidson', 'Ducati', 'BMW Motorrad', 'Indian Motorcycle', 'Honda'],
            'tier2' => ['Kawasaki', 'Yamaha', 'Triumph', 'KTM', 'Suzuki'],
        ],
        'SUPERYACHT' => [
            'tier1' => ['Azzam', 'Eclipse', 'Flying Fox', 'Amadea', 'Jubilee', 'Dilbar'],
            'tier2' => ['Feadship', 'Lürssen', 'Oceanco', 'Benetti', 'Sunseeker'],
        ],
        'YACHT' => [
            'tier1' => ['Azzam', 'Eclipse', 'Feadship', 'Lürssen', 'Oceanco'],
            'tier2' => ['Beneteau', 'Jeanneau', 'Sunseeker', 'Azimut', 'Princess Yachts'],
        ],
        'HEALTH' => [
            'tier1' => ['FDA', 'WHO', 'CDC', 'Harvard', 'Mayo Clinic', 'NIH', 'Stanford'],
            'tier2' => ['Johns Hopkins', 'Oxford', 'MIT', 'Cleveland Clinic'],
        ],
        'ASTRONOMY' => [
            'tier1' => ['NASA', 'SpaceX', 'James Webb', 'Elon Musk', 'ESA', 'Hubble'],
            'tier2' => ['ISRO', 'JAXA', 'Blue Origin'],
        ],
    ];


    // ── D. DISTRIBUTION SIGNALS ───────────────────────────────────────────────

    private const SHAREABILITY_SCORES = [
        'KANSAS CITY CHIEFS'   => 20,
        'DALLAS COWBOYS'       => 20,
        'PHILADELPHIA EAGLES'  => 19,
        'SAN FRANCISCO 49ERS'  => 18,
        'PITTSBURGH STEELERS'  => 17,
        'GREEN BAY PACKERS'    => 16,
        'CHICAGO BEARS'        => 16,
        'NEW ENGLAND PATRIOTS' => 16,
        'NEW YORK GIANTS'      => 15,
        'SHOWBIZ'              => 18,
        'WEIRD NEWS'           => 16,
        'WEIRD'                => 14,
        'HEALTH'               => 15,
        'CATS'                 => 14,
        'DOGS'                 => 14,
        'FORMULA 1'            => 12,
        'MOTOGP'               => 12,
        'ASTRONOMY'            => 12,
        'TENNIS'               => 10,
        'GOLF'                 => 10,
        'AIRLINE'              => 10,
        'CARS'                 => 10,
        'TINY HOME'            => 9,
        'MOTO'                 => 8,
        'SUPERYACHT'           => 8,
        'YACHT'                => 7,
    ];

    // ── E. TIMING SIGNALS ─────────────────────────────────────────────────────

    private const GOLDEN_HOURS = [
        'KANSAS CITY CHIEFS'   => [18, 19, 20, 21, 22],
        'DALLAS COWBOYS'       => [18, 19, 20, 21, 22],
        'PHILADELPHIA EAGLES'  => [18, 19, 20, 21, 22],
        'SAN FRANCISCO 49ERS'  => [18, 19, 20, 21, 22],
        'PITTSBURGH STEELERS'  => [18, 19, 20, 21, 22],
        'GREEN BAY PACKERS'    => [18, 19, 20, 21, 22],
        'CHICAGO BEARS'        => [18, 19, 20, 21, 22],
        'NEW ENGLAND PATRIOTS' => [18, 19, 20, 21, 22],
        'NEW YORK GIANTS'      => [18, 19, 20, 21, 22],
        'SHOWBIZ'              => [12, 13, 20, 21],
        'HEALTH'               => [7, 8, 9, 21, 22],
        'WEIRD NEWS'           => [11, 12, 17, 18],
        'WEIRD'                => [11, 12, 17, 18],
        'CATS'                 => [8, 9, 20, 21],
        'DOGS'                 => [8, 9, 20, 21],
        'ASTRONOMY'            => [20, 21, 22, 23],
        'FORMULA 1'            => [14, 15, 20, 21],
        'MOTOGP'               => [14, 15, 20, 21],
        'GOLF'                 => [13, 14, 15, 16],
        'CARS'                 => [12, 18, 19],
        'MOTO'                 => [12, 18, 19],
        'AIRLINE'              => [6, 7, 17, 18],
        'TINY HOME'            => [9, 10, 20, 21],
        'SUPERYACHT'           => [11, 12, 18, 19],
        'YACHT'                => [11, 12, 18, 19],
        'TENNIS'               => [13, 14, 15, 20],
    ];

    private const BEST_DAYS = [
        'KANSAS CITY CHIEFS'   => [0, 1, 4],
        'DALLAS COWBOYS'       => [0, 1, 4],
        'PHILADELPHIA EAGLES'  => [0, 1, 4],
        'SAN FRANCISCO 49ERS'  => [0, 1, 4],
        'PITTSBURGH STEELERS'  => [0, 1, 4],
        'GREEN BAY PACKERS'    => [0, 1, 4],
        'CHICAGO BEARS'        => [0, 1, 4],
        'NEW ENGLAND PATRIOTS' => [0, 1, 4],
        'NEW YORK GIANTS'      => [0, 1, 4],
        'GOLF'                 => [6, 0],
        'FORMULA 1'            => [6, 0],
        'MOTOGP'               => [6, 0],
        'SHOWBIZ'              => [1, 2, 3],
        'TENNIS'               => [6, 0, 1],
    ];

    // ── PUBLIC — Main entry point ─────────────────────────────────────────────

    public function calculateFromRaw(array $raw, Keyword $kw): array
    {
        // SerpAPI already tells us if this is a top story and at what position
        $topStoryHint = null;
        if (!empty($raw['top_story'])) {
            $position = (int) ($raw['position'] ?? 99);
            $topStoryHint = match(true) {
                $position <= 3  => 15,
                $position <= 5  => 10,
                $position <= 10 => 5,
                default         => 3,
            };
        } elseif (!empty($raw['stories'])) {
            $topStoryHint = 5; // part of a story cluster but not the lead
        }

        $category = strtoupper($kw->category?->name ?? '');
        if (empty($category)) {
            Log::warning('ViralScoreService: keyword has no category', ['keyword_id' => $kw->id]);
        }

        return $this->calculate(
            title:         $raw['title']     ?? '',
            snippet:       $raw['snippet']   ?? '',
            sourceUrl:     $raw['link']      ?? '',
            thumbnail:     $raw['thumbnail'] ?? '',
            content:       null,
            faq:           null,
            category:      $category,
            createdAt:     !empty($raw['date']) ? (strtotime($raw['date']) ?: time()) : time(),
            articleId:     md5($raw['link'] ?? ''),
            topStoryHint:  $topStoryHint,
        );
    }

    public function calculateFromArticle(object $article): array
    {
        return $this->calculate(
            title:      $article->title ?? '',
            snippet:    $article->meta_description ?? '',
            sourceUrl:  $article->source_url ?? '',
            thumbnail:  $article->thumbnail ?? '',
            content:    $article->content ?? '',
            faq:        $article->faq ?? null,
            category:   strtoupper($article->keyword?->category?->name ?? ''),
            createdAt:  $article->created_at ? $article->created_at->timestamp : time(),
            articleId:  $article->id ?? '',
        );
    }

    private function calculate(
        string  $title,
        string  $snippet,
        string  $sourceUrl,
        ?string $thumbnail,
        ?string $content,
        mixed   $faq,
        string  $category,
        int     $createdAt,
        string  $articleId,
        ?int    $topStoryHint = null,
    ): array {
        $text = strtolower($title . ' ' . $snippet);
        $hoursAgo   = (time() - $createdAt) / 3600;

        $signals = [
            // A. TITLE/HOOK (max 48)
            'power_words'     => $this->powerWordScore($title),
            'title_structure' => $this->titleStructureScore($title),
            'negative_hooks'  => $this->negativeHookScore($title),
            'fb_title'        => $this->fbTitleScore($title),

            // B. EMOTION (max 40)
            'emotion'         => $this->emotionScore($text),
            'comment_bait'    => $this->commentBaitScore($text),

            // C. RECOGNITION (max 41)
            'celebrity_brand' => $this->recognitionScore($title . ' ' . $snippet, $category),
            'social_proof'    => $this->socialProofScore($sourceUrl, $content ?? ''),
            'top_story'       => $topStoryHint ?? 0,

            // D. DISTRIBUTION (max 30)
            'shareability'    => $this->shareabilityScore($category),
            'visual_stop'     => $this->visualStopScore($thumbnail),
            'content_format'  => $this->contentFormatScore($content ?? '', $faq),

            // E. TIMING (max 15)
            'platform_timing' => $this->platformTimingScore($category, $hoursAgo),

            // F. CONTENT (max 20)
            'controversy'     => $this->controversyScore($text),
            'specificity'     => $this->specificityScore($title . ' ' . $snippet),
        ];

        $rawScore        = array_sum($signals);
        $normalizedScore = (int) min(round(($rawScore / self::MAX_RAW_SCORE) * 100), 100);
        $tier            = $this->getTier($normalizedScore);

        Log::debug('ViralScoreService', [
            'id'    => $articleId,
            'score' => $normalizedScore,
            'tier'  => $tier,
            'top3'  => $this->topSignals($signals, 3),
        ]);

        return [
            'score'   => $normalizedScore,
            'tier'    => $tier,
            'raw'     => $rawScore,
            'signals' => $signals,
            'top3'    => $this->topSignals($signals, 3),
        ];
    }

    // ── A. TITLE / HOOK ───────────────────────────────────────────────────────

    private function powerWordScore(string $title): int
    {
        $score   = 0;
        $matched = [];

        foreach (self::POWER_WORDS as $type => $config) {
            if (isset($config['regex'])) {
                if (preg_match($config['regex'], $title)) {
                    $score   += $config['points'];
                    $matched[] = $type;
                }
                continue;
            }
            foreach ($config['words'] as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $title)) {
                    $score   += $config['points'];
                    $matched[] = $type;
                    break;
                }
            }
        }

        if (in_array('urgency', $matched) && in_array('negative_shock', $matched)) $score += 5;
        if (in_array('superlative', $matched) && in_array('money_number', $matched)) $score += 3;
        if (in_array('controversy', $matched) && in_array('negative_shock', $matched)) $score += 4;

        return min($score, 25);
    }

    private function titleStructureScore(string $title): int
    {
        $score = 0;

        if (preg_match('/^\d+/', $title))                        $score += 3;
        $len = strlen($title);
        if ($len >= 50 && $len <= 80)                            $score += 2;
        elseif ($len >= 40 && $len <= 90)                        $score += 1;
        if (str_contains($title, ':'))                           $score += 1;
        if (preg_match('/\.\s+[A-Z]|—|but\s+[a-z]/i', $title)) $score += 2;
        if (str_ends_with(trim($title), '?'))                    $score += 1;
        if (preg_match('/\b[A-Z]{3,}\b/', $title))              $score += 1;

        return min($score, 10);
    }

    private function negativeHookScore(string $title): int
    {
        $best = 0;
        foreach (self::NEGATIVE_HOOK_PATTERNS as $pattern => $points) {
            if (preg_match($pattern, $title)) $best = max($best, $points);
        }
        return $best;
    }

    private function fbTitleScore(string $title): int
    {
        $score = 0;
        foreach (self::FB_TITLE_SIGNALS as $pattern => $points) {
            if (preg_match($pattern, $title)) $score += $points;
        }
        return min($score, 5);
    }

    // ── B. EMOTION ────────────────────────────────────────────────────────────

    private function emotionScore(string $text): int
    {
        $best = 0;
        foreach (self::EMOTION_KEYWORDS as $points => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $best = max($best, $points);
                    break;
                }
            }
        }
        return $best;
    }

    private function commentBaitScore(string $text): int
    {
        $score = 0;
        foreach (self::COMMENT_TRIGGERS as $config) {
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $text)) {
                    $score += $config['points'];
                    break;
                }
            }
        }
        return min($score, 15);
    }

    // ── C. RECOGNITION ────────────────────────────────────────────────────────

    private function recognitionScore(string $text, string $category): int
    {
        $lists = self::RECOGNITION_LISTS[$category] ?? [];

        foreach ($lists['tier1'] ?? [] as $name) {
            if (stripos($text, $name) !== false) return 15;
        }
        foreach ($lists['tier2'] ?? [] as $name) {
            if (stripos($text, $name) !== false) return 10;
        }
        if (preg_match('/\b[A-Z][a-z]+ [A-Z][a-z]+\b/', $text)) return 3;

        return 0;
    }

    private function socialProofScore(string $sourceUrl, string $content): int
    {
        $score  = 0;
        $domain = str_replace('www.', '', parse_url($sourceUrl, PHP_URL_HOST) ?? '');

        $tier1 = $this->tier1Domains ??= Cache::remember('viral_tier1_domains', 3600, fn() =>
            NewsWeb::where('is_trusted', true)->where('is_active', true)->pluck('domain')->toArray()
        );
        $tier2 = $this->tier2Domains ??= NewsSource::trustedDomains();

        $inTier = fn(array $list) => collect($list)->contains(fn($d) => str_contains($domain, $d));

        if ($inTier($tier1))     $score += 8;
        elseif ($inTier($tier2)) $score += 5;
        else                     $score += 2;

        if (preg_match_all('/according to|told [A-Z]|said [A-Z]/i', $content) >= 2) $score += 3;

        return min($score, 11);
    }

    // ── D. DISTRIBUTION ───────────────────────────────────────────────────────

    private function shareabilityScore(string $category): int
    {
        return min(self::SHAREABILITY_SCORES[$category] ?? 5, 15);
    }

    private function visualStopScore(?string $thumbnail): int
    {
        if (empty($thumbnail)) return 0;

        $score  = 3;
        $domain = parse_url($thumbnail, PHP_URL_HOST) ?? '';

        $trusted = ['espn.com','nfl.com','cbssports.com','apnews.com','reuters.com',
                    'bbc.co.uk','cnn.com','foxnews.com','nbcsports.com','usatoday.com'];
        foreach ($trusted as $d) {
            if (str_contains($domain, $d)) { $score += 5; break; }
        }

        if (preg_match('/\.(jpg|jpeg|png|webp)(\?.*)?$/i', $thumbnail)) $score += 2;

        return min($score, 10);
    }

    private function contentFormatScore(string $content, mixed $faq): int
    {
        $score    = 0;
        $artWords = str_word_count(strip_tags($content));

        if ($artWords >= 400 && $artWords <= 800) $score += 3;
        if (!empty($faq))                         $score += 2;

        return min($score, 5);
    }

    // ── E. TIMING ─────────────────────────────────────────────────────────────

    private function platformTimingScore(string $category, float $hoursAgo): int
    {
        $score = 0;
        $hour  = now()->hour;
        $day   = now()->dayOfWeek;

        $golden = self::GOLDEN_HOURS[$category] ?? [12, 18, 20];
        if (in_array($hour, $golden)) $score += 5;

        $bestDays = self::BEST_DAYS[$category] ?? [1, 2, 3, 4, 5];
        if (in_array($day, $bestDays)) $score += 3;

        $score += match(true) {
            $hoursAgo <= 3  => 7,
            $hoursAgo <= 6  => 5,
            $hoursAgo <= 24 => 3,
            default         => 1,
        };

        return min($score, 15);
    }

    // ── F. CONTENT ────────────────────────────────────────────────────────────

    private function controversyScore(string $text): int
    {
        if (str_contains($text, 'disagrees') || str_contains($text, ' vs ')) return 10;
        if (str_contains($text, 'blamed')    || str_contains($text, 'loser')) return 7;
        if (str_contains($text, 'divided')   || str_contains($text, 'debate')) return 5;
        return 0;
    }

    private function specificityScore(string $text): int
    {
        $score = 0;

        if (preg_match('/\$[\d,]+/', $text))                      $score += 2;
        if (preg_match('/\b[A-Z][a-z]+ [A-Z][a-z]+\b/', $text))  $score += 2;
        if (preg_match('/\d+(st|nd|rd|th)|\b20\d{2}\b/', $text))  $score += 2;
        if (preg_match('/\bNo\.\s*\d+|#\d+|\d+%/', $text))       $score += 2;
        if (preg_match('/\b[A-Z]{2,}\b/', $text))                 $score += 2;

        return min($score, 10);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function getTier(int $score): string
    {
        return match(true) {
            $score >= 90 => 'VIRAL_GUARANTEED',
            $score >= 75 => 'HIGH_POTENTIAL',
            $score >= 55 => 'AVERAGE',
            $score >= 35 => 'LOW',
            default      => 'SKIP',
        };
    }

    private function topSignals(array $signals, int $n): array
    {
        arsort($signals);
        return array_slice($signals, 0, $n, true);
    }
}
