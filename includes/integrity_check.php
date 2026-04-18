<?php
/**
 * Advanced Plagiarism & AI Content Detection Engine
 * ─────────────────────────────────────────────────
 * Turnitin-class integrity checking with:
 *
 * PLAGIARISM:  Winnowing fingerprinting, multi-level n-gram analysis (3/4/5/8-gram),
 *              cross-database comparison (all submissions + all dissertations),
 *              sentence-level matching, paraphrase detection, common-phrase exclusion,
 *              weighted composite scoring.
 *
 * AI DETECTION: Perplexity estimation, burstiness, entropy analysis, Zipf's Law
 *               conformance, vocabulary sophistication (hapax legomena), sentence-
 *               starter diversity, punctuation pattern analysis, discourse markers,
 *               structural repetition, pronoun/contraction usage, readability
 *               consistency, emotional flatness, coherence analysis.
 *
 * Usage:
 *   require_once 'includes/integrity_check.php';
 *   $engine = new IntegrityCheckEngine($conn);
 *   $result = $engine->checkSubmission($text, $exclude_id, $context);
 *   // $result = ['plagiarism' => [...], 'ai' => [...]]
 */

class IntegrityCheckEngine {
    private $conn;

    // Common academic phrases that should NOT count as plagiarism
    private $commonPhrases = [
        'the results show that', 'in this study we', 'the purpose of this',
        'according to the findings', 'as shown in table', 'as shown in figure',
        'the data suggests that', 'it can be concluded', 'further research is needed',
        'the following section discusses', 'this chapter presents', 'in the next section',
        'literature review shows', 'the methodology used', 'data was collected',
        'a sample of', 'the population of', 'the study was conducted',
        'the findings indicate', 'the analysis reveals', 'based on the results',
        'the following table shows', 'the following figure shows', 'et al',
        'for example', 'for instance', 'on the other hand', 'in addition to',
        'as well as', 'in order to', 'due to the fact', 'in terms of',
        'with respect to', 'it is important to note', 'the aim of this study',
        'the objective of this', 'research questions', 'null hypothesis',
        'standard deviation', 'mean value', 'statistically significant',
        'p value less than', 'confidence interval', 'correlational analysis',
        'qualitative research', 'quantitative research', 'mixed methods',
        'random sampling', 'data collection', 'informed consent',
        'ethical approval', 'thematic analysis', 'content analysis',
    ];

    // Extended AI transition/filler words
    private $aiTransitionWords = [
        'however', 'furthermore', 'moreover', 'additionally', 'consequently',
        'therefore', 'nevertheless', 'nonetheless', 'meanwhile', 'subsequently',
        'in addition', 'as a result', 'on the other hand', 'in conclusion',
        'for instance', 'for example', 'in particular', 'specifically',
        'overall', 'ultimately', 'essentially', 'significantly', 'notably',
        'interestingly', 'importantly', 'accordingly', 'thus', 'hence',
        'undeniably', 'undoubtedly', 'clearly', 'evidently', 'indeed',
        'certainly', 'fundamentally', 'remarkably', 'arguably', 'inherently',
        'notwithstanding', 'conversely', 'alternatively', 'likewise',
        'similarly', 'comparatively', 'in contrast', 'on the contrary',
        'to summarize', 'to conclude', 'in summary', 'in essence',
        'that being said', 'having said that', 'it is worth noting',
        'it should be noted', 'needless to say', 'it goes without saying',
    ];

    // AI phrase patterns (regex)
    private $aiPhrasePatterns = [
        '/it is (important|worth|essential|crucial|necessary|imperative|vital) to (note|mention|highlight|emphasize|acknowledge|recognize|understand|consider)/i',
        '/in (today\'s|the modern|the current|our contemporary|the present|the digital|the ever.changing) (world|society|era|age|landscape|environment)/i',
        '/plays a (crucial|vital|significant|important|key|pivotal|fundamental|critical|central|essential) role/i',
        '/(delve|delving|delved|delves) (into|deeper|further)/i',
        '/this (essay|paper|article|report|analysis|study|discussion|exploration|examination) (will|aims to|seeks to|explores|examines|investigates|discusses|analyzes)/i',
        '/it (is|remains) (important|essential|crucial|vital|imperative|critical) (that|to)/i',
        '/(realm|landscape|tapestry|myriad|plethora|multifaceted|paradigm) of/i',
        '/a (comprehensive|thorough|in.depth|nuanced|holistic|detailed|exhaustive) (understanding|analysis|examination|overview|exploration|look)/i',
        '/has (garnered|gained|attracted|received|sparked) (significant|considerable|increasing|growing|widespread) (attention|interest|scrutiny)/i',
        '/the (transformative|profound|significant|crucial|pivotal) (impact|effect|influence|role|importance)/i',
        '/(navigate|navigating) the (complexities|challenges|intricacies|nuances|dynamics)/i',
        '/in the (realm|context|scope|domain|sphere|landscape) of/i',
        '/foster(s|ing)? (a|an)? ?(sense|culture|environment|atmosphere|spirit) of/i',
        '/it (can|could|should|must) be (argued|noted|observed|stated|said|emphasized|stressed)/i',
        '/(serves|serve) as a (testament|reminder|foundation|cornerstone|catalyst|stepping.stone)/i',
        '/the (ever.evolving|rapidly changing|constantly shifting|dynamic|fast.paced) (landscape|world|environment|field)/i',
        '/(harness|leverage|utilize|embrace|unlock) the (power|potential|capabilities|benefits|opportunities)/i',
        '/a (deeper|broader|richer|more nuanced|more comprehensive) understanding/i',
        '/shed(s|ding)? light on/i',
        '/pave(s|d|ing)? the way (for|to|towards)/i',
    ];

    // Common academic sentence starters (humans vary more)
    private $sentenceStarterCategories = [
        'article' => ['the', 'a', 'an'],
        'pronoun' => ['it', 'this', 'that', 'these', 'those', 'they', 'we', 'he', 'she'],
        'conjunction' => ['however', 'furthermore', 'moreover', 'additionally', 'therefore', 'thus', 'hence'],
        'preposition' => ['in', 'on', 'at', 'by', 'for', 'with', 'from', 'to', 'of'],
        'demonstrative' => ['this', 'these', 'such', 'another', 'each'],
    ];

    public function __construct($conn) {
        $this->conn = $conn;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Run full integrity check on text.
     *
     * @param string $text        The extracted text to check
     * @param int    $exclude_id  Submission ID to exclude from comparison
     * @param array  $context     ['type'=>'assignment'|'dissertation', 'assignment_id'=>int, 'dissertation_id'=>int, 'student_id'=>string|int]
     * @return array ['plagiarism'=>[...], 'ai'=>[...]]
     */
    public function checkSubmission(string $text, int $exclude_id, array $context): array {
        $plagiarism = $this->advancedPlagiarismCheck($text, $exclude_id, $context);
        $ai = $this->advancedAICheck($text);
        return ['plagiarism' => $plagiarism, 'ai' => $ai];
    }

    /**
     * Run only plagiarism check.
     */
    public function checkPlagiarism(string $text, int $exclude_id, array $context): array {
        return $this->advancedPlagiarismCheck($text, $exclude_id, $context);
    }

    /**
     * Run only AI detection.
     */
    public function checkAI(string $text): array {
        return $this->advancedAICheck($text);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PLAGIARISM ENGINE
    // ═══════════════════════════════════════════════════════════════════════

    private function advancedPlagiarismCheck(string $text, int $exclude_id, array $context): array {
        if (strlen($text) < 50) {
            return ['score' => 0.0, 'matches' => [], 'details' => ['method' => 'insufficient_text']];
        }

        $normalized = $this->normalizeText($text);
        $sentences = $this->extractSentences($text);

        // Generate multi-level fingerprints
        $fp3 = $this->generateNgrams($normalized, 3);
        $fp4 = $this->generateNgrams($normalized, 4);
        $fp5 = $this->generateNgrams($normalized, 5);
        $fp8 = $this->generateNgrams($normalized, 8);

        // Winnowing fingerprints for robust matching
        $winnowing = $this->winnowingFingerprint($normalized, 5, 4);

        // Sentence fingerprints for exact/near sentence matching
        $sentenceFPs = $this->sentenceFingerprints($sentences);

        // Remove common academic phrases from n-grams
        $fp4 = $this->excludeCommonPhrases($fp4);
        $fp5 = $this->excludeCommonPhrases($fp5);

        // Collect comparison corpus
        $corpus = $this->buildComparisonCorpus($exclude_id, $context);

        $max_score = 0.0;
        $all_matches = [];
        $matched_sentences = 0;
        $total_sentences = count($sentences);

        foreach ($corpus as $doc) {
            $doc_norm = $this->normalizeText($doc['text']);
            $doc_sentences = $this->extractSentences($doc['text']);

            // ── Multi-level N-gram Similarity ──
            $scores = [];

            // 3-gram (catches paraphrasing / word substitution)
            $doc_fp3 = $this->generateNgrams($doc_norm, 3);
            $sim3 = $this->jaccardSimilarity($fp3, $doc_fp3);
            $scores['ngram3'] = $sim3;

            // 4-gram (standard overlap)
            $doc_fp4 = $this->excludeCommonPhrases($this->generateNgrams($doc_norm, 4));
            $sim4 = $this->jaccardSimilarity($fp4, $doc_fp4);
            $scores['ngram4'] = $sim4;

            // 5-gram (stronger phrase match)
            $doc_fp5 = $this->excludeCommonPhrases($this->generateNgrams($doc_norm, 5));
            $sim5 = $this->jaccardSimilarity($fp5, $doc_fp5);
            $scores['ngram5'] = $sim5;

            // 8-gram (exact copying detection)
            $doc_fp8 = $this->generateNgrams($doc_norm, 8);
            $sim8 = $this->jaccardSimilarity($fp8, $doc_fp8);
            $scores['ngram8'] = $sim8;

            // ── Winnowing Fingerprint Match ──
            $doc_winnowing = $this->winnowingFingerprint($doc_norm, 5, 4);
            $win_sim = $this->winnowingSimilarity($winnowing, $doc_winnowing);
            $scores['winnowing'] = $win_sim;

            // ── Sentence-level matching ──
            $doc_sentenceFPs = $this->sentenceFingerprints($doc_sentences);
            $sent_match = $this->sentenceSimilarity($sentenceFPs, $doc_sentenceFPs);
            $scores['sentence'] = $sent_match['score'];
            $matched_sentences = max($matched_sentences, $sent_match['matched_count']);

            // ── Containment check (is target contained in source?) ──
            if (count($fp5) > 0 && count($doc_fp5) > 0) {
                $contained = count(array_intersect_key($fp5, $doc_fp5));
                $containment = ($contained / count($fp5)) * 100;
                $scores['containment'] = $containment;
            }

            // ── Weighted composite score ──
            $composite = $this->weightedPlagiarismScore($scores);

            if ($composite > 3.0) {
                $all_matches[] = [
                    'source_type' => $doc['source_type'],
                    'source_id' => $doc['source_id'],
                    'source_label' => $doc['source_label'],
                    'student_id' => $doc['student_id'] ?? null,
                    'similarity' => round($composite, 1),
                    'breakdown' => array_map(fn($v) => round($v, 1), $scores),
                ];
            }

            $max_score = max($max_score, $composite);
        }

        // Sort matches descending
        usort($all_matches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        $word_count = str_word_count($text);
        $flagged_pct = min(100.0, round($max_score, 1));

        return [
            'score' => $flagged_pct,
            'matches' => array_slice($all_matches, 0, 10),
            'matched_sentences' => $matched_sentences,
            'total_sentences' => $total_sentences,
            'word_count' => $word_count,
            'flagged_words' => (int)($word_count * ($flagged_pct / 100)),
            'details' => [
                'method' => 'winnowing + multi-ngram (3/4/5/8) + sentence matching + containment',
                'corpus_size' => count($corpus),
                'common_phrases_excluded' => true,
            ],
        ];
    }

    /**
     * Build comparison corpus from entire database.
     * Checks: same-assignment submissions, all other assignment submissions,
     * and all dissertation submissions.
     */
    private function buildComparisonCorpus(int $exclude_id, array $context): array {
        $corpus = [];
        $type = $context['type'] ?? 'assignment';

        if ($type === 'assignment') {
            // 1. Same assignment submissions (highest priority)
            $assignment_id = (int)($context['assignment_id'] ?? 0);
            if ($assignment_id) {
                $stmt = $this->conn->prepare("
                    SELECT vs.submission_id, vs.text_content, vs.file_path, vs.student_id, vs.assignment_id as a_id,
                           CONCAT('Assignment #', va.assignment_id, ' - ', LEFT(va.title, 50)) as label
                    FROM vle_submissions vs
                    JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
                    WHERE vs.assignment_id = ? AND vs.submission_id != ?
                    LIMIT 200
                ");
                $stmt->bind_param("ii", $assignment_id, $exclude_id);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($rows as $row) {
                    $txt = $this->extractSubmissionText($row);
                    if (strlen($txt) >= 50) {
                        $corpus[] = [
                            'text' => $txt,
                            'source_type' => 'same_assignment',
                            'source_id' => $row['submission_id'],
                            'source_label' => $row['label'] ?? 'Assignment submission',
                            'student_id' => $row['student_id'],
                        ];
                    }
                }
            }

            // 2. Other assignment submissions across all courses (wider check)
            $student_id = $context['student_id'] ?? '';
            $stmt = $this->conn->prepare("
                SELECT vs.submission_id, vs.text_content, vs.file_path, vs.student_id, vs.assignment_id as a_id,
                       CONCAT(vc.course_name, ' - ', LEFT(va.title, 40)) as label
                FROM vle_submissions vs
                JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
                JOIN vle_courses vc ON va.course_id = vc.course_id
                WHERE vs.assignment_id != ? AND vs.submission_id != ?
                ORDER BY vs.submitted_at DESC
                LIMIT 300
            ");
            $aid = (int)($context['assignment_id'] ?? 0);
            $stmt->bind_param("ii", $aid, $exclude_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $txt = $this->extractSubmissionText($row);
                if (strlen($txt) >= 50) {
                    $corpus[] = [
                        'text' => $txt,
                        'source_type' => 'cross_assignment',
                        'source_id' => $row['submission_id'],
                        'source_label' => $row['label'] ?? 'Cross-course submission',
                        'student_id' => $row['student_id'],
                    ];
                }
            }

            // 3. Dissertation submissions (cross-check)
            $this->addDissertationCorpus($corpus, 0, 0);

        } elseif ($type === 'dissertation') {
            $dissertation_id = (int)($context['dissertation_id'] ?? 0);

            // 1. Other dissertation submissions
            $this->addDissertationCorpus($corpus, $exclude_id, $dissertation_id);

            // 2. Assignment submissions across all courses
            $stmt = $this->conn->prepare("
                SELECT vs.submission_id, vs.text_content, vs.file_path, vs.student_id, vs.assignment_id as a_id,
                       CONCAT(vc.course_name, ' - ', LEFT(va.title, 40)) as label
                FROM vle_submissions vs
                JOIN vle_assignments va ON vs.assignment_id = va.assignment_id
                JOIN vle_courses vc ON va.course_id = vc.course_id
                ORDER BY vs.submitted_at DESC
                LIMIT 300
            ");
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $txt = $this->extractSubmissionText($row);
                if (strlen($txt) >= 50) {
                    $corpus[] = [
                        'text' => $txt,
                        'source_type' => 'assignment',
                        'source_id' => $row['submission_id'],
                        'source_label' => $row['label'] ?? 'Assignment submission',
                        'student_id' => $row['student_id'],
                    ];
                }
            }
        }

        return $corpus;
    }

    private function addDissertationCorpus(array &$corpus, int $exclude_sub_id, int $exclude_diss_id): void {
        $sql = "
            SELECT ds.submission_id, ds.submission_text, ds.file_path, ds.file_name,
                   d.student_id, d.title as diss_title, d.dissertation_id
            FROM dissertation_submissions ds
            JOIN dissertations d ON ds.dissertation_id = d.dissertation_id
            WHERE 1=1
        ";
        $params = [];
        $types = '';

        if ($exclude_sub_id > 0) {
            $sql .= " AND ds.submission_id != ?";
            $params[] = $exclude_sub_id;
            $types .= 'i';
        }
        if ($exclude_diss_id > 0) {
            $sql .= " AND d.dissertation_id != ?";
            $params[] = $exclude_diss_id;
            $types .= 'i';
        }

        $sql .= " ORDER BY ds.submitted_at DESC LIMIT 150";

        $stmt = $this->conn->prepare($sql);
        if ($stmt && $types) {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt) return;

        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            $txt = $this->extractDissertationText($row);
            if (strlen($txt) >= 50) {
                $corpus[] = [
                    'text' => $txt,
                    'source_type' => 'dissertation',
                    'source_id' => $row['submission_id'],
                    'source_label' => 'Dissertation: ' . ($row['diss_title'] ?? 'Untitled'),
                    'student_id' => $row['student_id'],
                ];
            }
        }
    }

    // ── Winnowing Algorithm (Schleimer, Wilkerson, Aiken – same family as Turnitin) ──

    private function winnowingFingerprint(string $text, int $kgramSize = 5, int $windowSize = 4): array {
        $words = explode(' ', $text);
        if (count($words) < $kgramSize) return [];

        // Generate k-gram hashes
        $hashes = [];
        for ($i = 0; $i <= count($words) - $kgramSize; $i++) {
            $gram = implode(' ', array_slice($words, $i, $kgramSize));
            $hashes[] = crc32($gram);
        }

        if (count($hashes) < $windowSize) return $hashes;

        // Select minimum hash from each window
        $fingerprints = [];
        $prev_min_idx = -1;

        for ($i = 0; $i <= count($hashes) - $windowSize; $i++) {
            $window = array_slice($hashes, $i, $windowSize);
            $min_val = min($window);
            $min_idx = $i + array_search($min_val, $window);

            if ($min_idx !== $prev_min_idx) {
                $fingerprints[$min_val] = true;
                $prev_min_idx = $min_idx;
            }
        }

        return $fingerprints;
    }

    private function winnowingSimilarity(array $fp1, array $fp2): float {
        if (empty($fp1) || empty($fp2)) return 0.0;
        $intersection = count(array_intersect_key($fp1, $fp2));
        $union = count($fp1) + count($fp2) - $intersection;
        return $union > 0 ? ($intersection / $union) * 100 : 0.0;
    }

    // ── Sentence-level matching ──

    private function extractSentences(string $text): array {
        $sents = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($sents, fn($s) => str_word_count(trim($s)) >= 5));
    }

    private function sentenceFingerprints(array $sentences): array {
        $fps = [];
        foreach ($sentences as $s) {
            $norm = $this->normalizeText($s);
            // Use hash for fast comparison
            $fps[] = [
                'hash' => crc32($norm),
                'text' => $norm,
                'words' => explode(' ', $norm),
            ];
        }
        return $fps;
    }

    private function sentenceSimilarity(array $fps1, array $fps2): array {
        if (empty($fps1) || empty($fps2)) return ['score' => 0.0, 'matched_count' => 0];

        $matched = 0;

        foreach ($fps1 as $s1) {
            foreach ($fps2 as $s2) {
                // Exact match
                if ($s1['hash'] === $s2['hash']) {
                    $matched++;
                    break;
                }

                // Near match: >80% word overlap
                if (count($s1['words']) >= 5 && count($s2['words']) >= 5) {
                    $common = count(array_intersect($s1['words'], $s2['words']));
                    $min_len = min(count($s1['words']), count($s2['words']));
                    if ($min_len > 0 && ($common / $min_len) > 0.80) {
                        $matched++;
                        break;
                    }
                }
            }
        }

        $score = count($fps1) > 0 ? ($matched / count($fps1)) * 100 : 0.0;
        return ['score' => $score, 'matched_count' => $matched];
    }

    // ── Weighted composite plagiarism score ──

    private function weightedPlagiarismScore(array $scores): float {
        $weights = [
            'ngram3'      => 0.08,  // Low weight – catches loose overlap
            'ngram4'      => 0.18,  // Standard
            'ngram5'      => 0.20,  // Strong phrase
            'ngram8'      => 0.12,  // Exact copy bonus
            'winnowing'   => 0.18,  // Robust fingerprint
            'sentence'    => 0.16,  // Sentence-level
            'containment' => 0.08,  // Containment ratio
        ];

        $total_weight = 0;
        $weighted_sum = 0;

        foreach ($weights as $key => $weight) {
            if (isset($scores[$key])) {
                $weighted_sum += $scores[$key] * $weight;
                $total_weight += $weight;
            }
        }

        $composite = $total_weight > 0 ? $weighted_sum / $total_weight : 0.0;

        // Boost if any single metric is very high (indicates clear copying)
        $max_single = !empty($scores) ? max($scores) : 0;
        if ($max_single > 60) {
            $composite = max($composite, $max_single * 0.85);
        }
        if ($max_single > 80) {
            $composite = max($composite, $max_single * 0.92);
        }

        return min(100.0, $composite);
    }

    // ── Common phrase exclusion ──

    private function excludeCommonPhrases(array $ngrams): array {
        foreach ($this->commonPhrases as $phrase) {
            $norm = $this->normalizeText($phrase);
            unset($ngrams[$norm]);
            // Also remove sub-ngrams of common phrases
            $words = explode(' ', $norm);
            for ($i = 0; $i <= count($words) - 4; $i++) {
                $sub = implode(' ', array_slice($words, $i, 4));
                unset($ngrams[$sub]);
            }
            for ($i = 0; $i <= count($words) - 5; $i++) {
                $sub = implode(' ', array_slice($words, $i, 5));
                unset($ngrams[$sub]);
            }
        }
        return $ngrams;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AI CONTENT DETECTION ENGINE
    // ═══════════════════════════════════════════════════════════════════════

    private function advancedAICheck(string $text): array {
        if (strlen($text) < 100) {
            return ['score' => 0.0, 'indicators' => [], 'details' => ['method' => 'insufficient_text']];
        }

        $indicators = [];
        $words = preg_split('/\s+/', $text);
        $words_lower = preg_split('/\s+/', mb_strtolower($text));
        $words_clean = array_values(array_filter($words_lower, fn($w) => strlen($w) > 2));
        $word_count = count($words);
        $sentences = $this->extractSentencesForAI($text);
        $paragraphs = array_values(array_filter(
            preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY),
            fn($p) => strlen(trim($p)) > 20
        ));
        $text_lower = mb_strtolower($text);

        // ─── 1. Sentence Length Uniformity (Burstiness) ───
        // Human text has "bursty" sentence lengths – AI is more uniform
        $sl_score = $this->sentenceLengthUniformity($sentences);
        if ($sl_score !== null) {
            $indicators['sentence_uniformity'] = ['score' => $sl_score, 'weight' => 0.12, 'label' => 'Sentence length uniformity'];
        }

        // ─── 2. Perplexity Estimation ───
        // Low perplexity = highly predictable text = likely AI
        $perp_score = $this->perplexityEstimation($words_clean);
        if ($perp_score !== null) {
            $indicators['perplexity'] = ['score' => $perp_score, 'weight' => 0.12, 'label' => 'Text predictability (perplexity)'];
        }

        // ─── 3. Vocabulary Sophistication (Hapax Legomena) ───
        // Hapax legomena = words used only once. Humans use more unique one-off words.
        $hapax_score = $this->hapaxLegomenaRatio($words_clean);
        if ($hapax_score !== null) {
            $indicators['vocabulary_sophistication'] = ['score' => $hapax_score, 'weight' => 0.08, 'label' => 'Vocabulary sophistication'];
        }

        // ─── 4. Type-Token Ratio ───
        $ttr_score = $this->typeTokenRatio($words_clean);
        if ($ttr_score !== null) {
            $indicators['type_token_ratio'] = ['score' => $ttr_score, 'weight' => 0.07, 'label' => 'Vocabulary diversity (TTR)'];
        }

        // ─── 5. Sentence Starter Diversity ───
        // AI tends to start sentences the same way
        $ssd_score = $this->sentenceStarterDiversity($sentences);
        if ($ssd_score !== null) {
            $indicators['sentence_starters'] = ['score' => $ssd_score, 'weight' => 0.08, 'label' => 'Sentence starter diversity'];
        }

        // ─── 6. Transition Word Density ───
        $tw_score = $this->transitionWordDensity($text_lower, $word_count);
        if ($tw_score !== null) {
            $indicators['transition_density'] = ['score' => $tw_score, 'weight' => 0.08, 'label' => 'Transition word overuse'];
        }

        // ─── 7. AI Phrase Pattern Detection ───
        $ap_score = $this->aiPhraseDetection($text, $word_count);
        if ($ap_score !== null) {
            $indicators['ai_phrases'] = ['score' => $ap_score, 'weight' => 0.10, 'label' => 'AI-typical phrase patterns'];
        }

        // ─── 8. Paragraph Structure Regularity ───
        $ps_score = $this->paragraphRegularity($paragraphs);
        if ($ps_score !== null) {
            $indicators['paragraph_regularity'] = ['score' => $ps_score, 'weight' => 0.06, 'label' => 'Paragraph structure regularity'];
        }

        // ─── 9. Punctuation Pattern Analysis ───
        // AI has very predictable comma/period ratios
        $pp_score = $this->punctuationPatterns($text, $sentences);
        if ($pp_score !== null) {
            $indicators['punctuation_patterns'] = ['score' => $pp_score, 'weight' => 0.05, 'label' => 'Punctuation pattern uniformity'];
        }

        // ─── 10. Entropy Analysis ───
        // Character-level entropy; AI text tends toward a specific range
        $ent_score = $this->entropyAnalysis($text);
        if ($ent_score !== null) {
            $indicators['entropy'] = ['score' => $ent_score, 'weight' => 0.06, 'label' => 'Character entropy analysis'];
        }

        // ─── 11. Zipf's Law Conformance ───
        // Natural language follows Zipf's distribution. AI may deviate.
        $zipf_score = $this->zipfConformance($words_clean);
        if ($zipf_score !== null) {
            $indicators['zipf_conformance'] = ['score' => $zipf_score, 'weight' => 0.05, 'label' => "Zipf's Law conformance"];
        }

        // ─── 12. Personal Pronoun & Contraction Usage ───
        // AI avoids first person and contractions
        $pc_score = $this->pronounContractionAnalysis($text_lower, $word_count);
        if ($pc_score !== null) {
            $indicators['pronoun_contraction'] = ['score' => $pc_score, 'weight' => 0.04, 'label' => 'Personal voice (pronouns/contractions)'];
        }

        // ─── 13. Readability Consistency ───
        // AI produces very consistent readability; humans vary across paragraphs
        $rc_score = $this->readabilityConsistency($paragraphs);
        if ($rc_score !== null) {
            $indicators['readability_consistency'] = ['score' => $rc_score, 'weight' => 0.05, 'label' => 'Readability uniformity'];
        }

        // ─── 14. Emotional Flatness ───
        // AI text lacks emotional variation
        $ef_score = $this->emotionalFlatness($sentences);
        if ($ef_score !== null) {
            $indicators['emotional_flatness'] = ['score' => $ef_score, 'weight' => 0.04, 'label' => 'Emotional tone flatness'];
        }

        // ── Weighted composite ──
        if (empty($indicators)) {
            return ['score' => 0.0, 'indicators' => [], 'details' => ['method' => 'no_data']];
        }

        $total_weight = 0;
        $weighted_sum = 0;
        foreach ($indicators as $ind) {
            $weighted_sum += $ind['score'] * $ind['weight'];
            $total_weight += $ind['weight'];
        }

        $composite = $total_weight > 0 ? $weighted_sum / $total_weight : 0;

        // If multiple strong signals, boost score
        $high_signals = count(array_filter($indicators, fn($i) => $i['score'] >= 70));
        if ($high_signals >= 5) {
            $composite = min(100, $composite * 1.15);
        } elseif ($high_signals >= 3) {
            $composite = min(100, $composite * 1.08);
        }

        $composite = min(100.0, max(0.0, round($composite, 1)));

        // Determine confidence level
        $checked_count = count($indicators);
        $confidence = $checked_count >= 10 ? 'high' : ($checked_count >= 6 ? 'medium' : 'low');

        return [
            'score' => $composite,
            'confidence' => $confidence,
            'indicators' => $indicators,
            'details' => [
                'method' => '14-metric weighted analysis (perplexity, burstiness, entropy, Zipf, hapax, TTR, sentence starters, transitions, AI phrases, paragraph regularity, punctuation, readability, pronouns, emotion)',
                'metrics_evaluated' => $checked_count,
                'high_signals' => $high_signals,
            ],
        ];
    }

    // ── AI Metric Implementations ──

    private function sentenceLengthUniformity(array $sentences): ?float {
        if (count($sentences) < 5) return null;

        $lengths = array_map(fn($s) => str_word_count(trim($s)), $sentences);
        $avg = array_sum($lengths) / count($lengths);
        if ($avg <= 0) return null;

        $variance = array_sum(array_map(fn($l) => ($l - $avg) ** 2, $lengths)) / count($lengths);
        $cv = sqrt($variance) / $avg;

        // Calculate burstiness: B = (σ - μ) / (σ + μ)
        $std_dev = sqrt($variance);
        $burstiness = ($std_dev - $avg) / ($std_dev + $avg + 0.001);

        // AI: CV < 0.35, burstiness close to -1
        // Human: CV > 0.5, burstiness closer to 0
        $cv_score = max(0, min(100, (1 - $cv / 0.6) * 100));

        // Also check consecutive sentence length differences (humans vary more consecutively)
        $diffs = [];
        for ($i = 1; $i < count($lengths); $i++) {
            $diffs[] = abs($lengths[$i] - $lengths[$i - 1]);
        }
        $avg_diff = count($diffs) > 0 ? array_sum($diffs) / count($diffs) : 0;
        $diff_score = max(0, min(100, (1 - $avg_diff / ($avg * 0.5 + 1)) * 80));

        return ($cv_score * 0.6 + $diff_score * 0.4);
    }

    private function perplexityEstimation(array $words): ?float {
        if (count($words) < 30) return null;

        // Bigram-based perplexity estimation
        $bigrams = [];
        $unigrams = [];
        for ($i = 0; $i < count($words); $i++) {
            $w = $words[$i];
            $unigrams[$w] = ($unigrams[$w] ?? 0) + 1;
            if ($i > 0) {
                $bg = $words[$i - 1] . ' ' . $w;
                $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1;
            }
        }

        if (empty($bigrams)) return null;

        // Calculate cross-entropy estimate
        $total_bigrams = array_sum($bigrams);
        $total_unigrams = array_sum($unigrams);
        $entropy = 0;
        $count = 0;

        foreach ($bigrams as $bg => $freq) {
            [$w1, $w2] = explode(' ', $bg, 2);
            $p_w1 = ($unigrams[$w1] ?? 1) / $total_unigrams;
            $p_bg = $freq / $total_bigrams;
            // Conditional probability estimate
            $p_cond = $freq / max(1, $unigrams[$w1] ?? 1);
            if ($p_cond > 0) {
                $entropy -= $p_bg * log($p_cond, 2);
                $count++;
            }
        }

        if ($count === 0) return null;

        $perplexity = pow(2, $entropy);

        // Low perplexity = predictable = AI-like
        // Typical AI text: perplexity 5-20, Human: 30-200+
        if ($perplexity < 10) return 85;
        if ($perplexity < 20) return 70;
        if ($perplexity < 35) return 50;
        if ($perplexity < 60) return 30;
        if ($perplexity < 100) return 15;
        return 5;
    }

    private function hapaxLegomenaRatio(array $words): ?float {
        if (count($words) < 30) return null;

        $freq = array_count_values($words);
        $hapax = count(array_filter($freq, fn($f) => $f === 1));
        $total_unique = count($freq);

        if ($total_unique === 0) return null;

        $ratio = $hapax / $total_unique;

        // Humans typically have hapax ratio 0.45-0.65 (many unique one-off words)
        // AI tends toward 0.25-0.40 (more systematic vocabulary reuse)
        if ($ratio < 0.25) return 80;
        if ($ratio < 0.35) return 60;
        if ($ratio < 0.42) return 40;
        if ($ratio < 0.50) return 25;
        return 10;
    }

    private function typeTokenRatio(array $words): ?float {
        if (count($words) < 20) return null;

        $unique = count(array_unique($words));
        $total = count($words);
        $ttr = $unique / $total;

        // For longer texts, use MATTR (Moving Average TTR) approach
        if ($total > 100) {
            $window = 50;
            $mattrs = [];
            for ($i = 0; $i <= $total - $window; $i += 10) {
                $chunk = array_slice($words, $i, $window);
                $mattrs[] = count(array_unique($chunk)) / count($chunk);
            }
            if (!empty($mattrs)) {
                $avg_mattr = array_sum($mattrs) / count($mattrs);
                // Check MATTR consistency (AI is very consistent)
                $mattr_var = array_sum(array_map(fn($m) => ($m - $avg_mattr) ** 2, $mattrs)) / count($mattrs);
                $mattr_cv = sqrt($mattr_var) / max(0.01, $avg_mattr);

                // Low MATTR variance = AI
                $consistency_penalty = max(0, min(40, (1 - $mattr_cv / 0.1) * 40));
                $base_score = max(0, min(60, ($ttr - 0.3) * 200));
                return min(100, $base_score + $consistency_penalty);
            }
        }

        $score = $total > 100
            ? max(0, min(100, ($ttr - 0.3) * 200))
            : max(0, min(100, ($ttr - 0.4) * 150));

        return $score;
    }

    private function sentenceStarterDiversity(array $sentences): ?float {
        if (count($sentences) < 8) return null;

        $starters = [];
        foreach ($sentences as $s) {
            $words = preg_split('/\s+/', trim($s));
            if (!empty($words[0])) {
                $starter = mb_strtolower($words[0]);
                $starters[] = $starter;
            }
        }

        if (count($starters) < 5) return null;

        // Check how many unique starters vs total
        $unique_starters = count(array_unique($starters));
        $total_starters = count($starters);
        $diversity = $unique_starters / $total_starters;

        // Check if any starter is used more than 25% of the time
        $freq = array_count_values($starters);
        $max_freq = max($freq);
        $max_pct = $max_freq / $total_starters;

        // Check category distribution (AI clusters into article/pronoun starts)
        $category_counts = [];
        foreach ($starters as $s) {
            foreach ($this->sentenceStarterCategories as $cat => $words) {
                if (in_array($s, $words)) {
                    $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;
                    break;
                }
            }
        }
        $top_category_pct = !empty($category_counts) ? max($category_counts) / $total_starters : 0;

        // Low diversity + high repetition = AI
        $div_score = max(0, min(100, (1 - $diversity) * 120));
        $rep_score = max(0, min(100, $max_pct * 200));
        $cat_score = max(0, min(100, $top_category_pct * 150));

        return ($div_score * 0.4 + $rep_score * 0.35 + $cat_score * 0.25);
    }

    private function transitionWordDensity(string $text_lower, int $word_count): ?float {
        if ($word_count < 30) return null;

        $count = 0;
        foreach ($this->aiTransitionWords as $tw) {
            $count += substr_count($text_lower, $tw);
        }

        $density = ($count / $word_count) * 100;

        // Human: typically 0.5-2% transition words
        // AI: often 3-6%+
        if ($density > 5) return 90;
        if ($density > 4) return 75;
        if ($density > 3) return 60;
        if ($density > 2.2) return 40;
        if ($density > 1.5) return 20;
        return 5;
    }

    private function aiPhraseDetection(string $text, int $word_count): ?float {
        if ($word_count < 50) return null;

        $total_hits = 0;
        foreach ($this->aiPhrasePatterns as $pattern) {
            $total_hits += preg_match_all($pattern, $text);
        }

        // Normalize by text length (per 500 words)
        $per_500 = ($total_hits / max(1, $word_count)) * 500;

        if ($per_500 > 8) return 95;
        if ($per_500 > 5) return 80;
        if ($per_500 > 3) return 65;
        if ($per_500 > 2) return 50;
        if ($per_500 > 1) return 30;
        if ($per_500 > 0.5) return 15;
        return 3;
    }

    private function paragraphRegularity(array $paragraphs): ?float {
        if (count($paragraphs) < 3) return null;

        $lengths = array_map(fn($p) => str_word_count(trim($p)), $paragraphs);
        $avg = array_sum($lengths) / count($lengths);
        if ($avg <= 0) return null;

        $variance = array_sum(array_map(fn($l) => ($l - $avg) ** 2, $lengths)) / count($lengths);
        $cv = sqrt($variance) / $avg;

        // AI: CV < 0.2 (very uniform paragraphs), Human: CV > 0.4
        return max(0, min(100, (1 - $cv / 0.5) * 80));
    }

    private function punctuationPatterns(string $text, array $sentences): ?float {
        if (count($sentences) < 5) return null;

        $comma_counts = [];
        foreach ($sentences as $s) {
            $wc = str_word_count($s);
            if ($wc > 3) {
                $commas = substr_count($s, ',');
                $comma_counts[] = $commas / $wc;
            }
        }

        if (count($comma_counts) < 5) return null;

        $avg_comma = array_sum($comma_counts) / count($comma_counts);
        if ($avg_comma <= 0) return 30;

        $variance = array_sum(array_map(fn($c) => ($c - $avg_comma) ** 2, $comma_counts)) / count($comma_counts);
        $cv = sqrt($variance) / $avg_comma;

        // AI has very consistent comma usage; humans vary widely
        $uniformity = max(0, min(100, (1 - $cv / 1.0) * 70));

        // Also check semicolon and colon usage (AI overuses these)
        $total_chars = strlen($text);
        $semicolons = substr_count($text, ';');
        $colons = substr_count($text, ':');
        $formal_punct = ($semicolons + $colons) / max(1, $total_chars) * 1000;
        $formal_score = max(0, min(30, $formal_punct * 20));

        return $uniformity + $formal_score;
    }

    private function entropyAnalysis(string $text): ?float {
        if (strlen($text) < 100) return null;

        // Character-level entropy
        $chars = str_split(mb_strtolower($text));
        $freq = array_count_values($chars);
        $total = count($chars);
        $entropy = 0;

        foreach ($freq as $f) {
            $p = $f / $total;
            if ($p > 0) $entropy -= $p * log($p, 2);
        }

        // English text typically has char entropy 4.0-4.5
        // AI-generated text tends to be in a narrow range around 4.1-4.3
        // Very human text can be 3.8-4.6+
        $deviation = abs($entropy - 4.2);
        if ($deviation < 0.1) return 65; // Very narrow = AI-like
        if ($deviation < 0.2) return 45;
        if ($deviation < 0.4) return 25;
        return 10;
    }

    private function zipfConformance(array $words): ?float {
        if (count($words) < 50) return null;

        $freq = array_count_values($words);
        arsort($freq);
        $freq_vals = array_values($freq);

        // Check if top-N word frequencies follow Zipf's law: f(r) ∝ 1/r
        $n = min(20, count($freq_vals));
        if ($n < 5) return null;

        // Calculate expected vs actual ratio
        $top_freq = $freq_vals[0];
        $deviations = [];
        for ($r = 1; $r < $n; $r++) {
            $expected = $top_freq / ($r + 1);
            $actual = $freq_vals[$r];
            if ($expected > 0) {
                $deviations[] = abs($actual - $expected) / $expected;
            }
        }

        if (empty($deviations)) return null;

        $avg_deviation = array_sum($deviations) / count($deviations);

        // Very close to Zipf (avg_deviation < 0.3) = natural
        // Large deviation suggests AI manipulation or unnatural distribution
        if ($avg_deviation < 0.3) return 20; // Follows Zipf well = likely natural
        if ($avg_deviation < 0.5) return 35;
        if ($avg_deviation < 0.7) return 50;
        if ($avg_deviation < 1.0) return 65;
        return 75; // Significant deviation from natural distribution
    }

    private function pronounContractionAnalysis(string $text_lower, int $word_count): ?float {
        if ($word_count < 50) return null;

        // First person pronouns
        $first_person = ['i ', 'my ', 'me ', 'mine ', 'myself ', 'we ', 'our ', 'us ', 'ours '];
        $fp_count = 0;
        foreach ($first_person as $p) {
            $fp_count += substr_count($text_lower, $p);
        }

        // Contractions
        $contractions = ["n't", "'m", "'re", "'ve", "'ll", "'d", "it's", "that's", "there's", "here's", "what's", "who's", "let's", "can't", "won't", "don't", "doesn't", "isn't", "aren't", "wasn't", "weren't", "couldn't", "wouldn't", "shouldn't"];
        $c_count = 0;
        foreach ($contractions as $c) {
            $c_count += substr_count($text_lower, $c);
        }

        $fp_density = ($fp_count / $word_count) * 100;
        $c_density = ($c_count / $word_count) * 100;

        // AI: very low first person (< 0.5%) and very low contractions (< 0.2%)
        // Human: variable, but typically higher
        $fp_score = $fp_density < 0.3 ? 70 : ($fp_density < 0.8 ? 40 : 15);
        $c_score = $c_density < 0.1 ? 65 : ($c_density < 0.3 ? 35 : 10);

        return ($fp_score * 0.5 + $c_score * 0.5);
    }

    private function readabilityConsistency(array $paragraphs): ?float {
        if (count($paragraphs) < 4) return null;

        $readability_scores = [];
        foreach ($paragraphs as $p) {
            $wc = str_word_count(trim($p));
            if ($wc < 10) continue;

            $sentences = preg_split('/[.!?]+/', trim($p), -1, PREG_SPLIT_NO_EMPTY);
            $sentences = array_filter($sentences, fn($s) => strlen(trim($s)) > 5);
            $sc = count($sentences);
            if ($sc === 0) continue;

            $syllables = $this->estimateSyllables($p);
            // Flesch-Kincaid Grade Level approximation
            $fk = 0.39 * ($wc / $sc) + 11.8 * ($syllables / $wc) - 15.59;
            $readability_scores[] = $fk;
        }

        if (count($readability_scores) < 3) return null;

        $avg = array_sum($readability_scores) / count($readability_scores);
        if (abs($avg) < 0.001) return null;

        $variance = array_sum(array_map(fn($r) => ($r - $avg) ** 2, $readability_scores)) / count($readability_scores);
        $cv = sqrt($variance) / max(0.1, abs($avg));

        // AI: very consistent readability (CV < 0.15)
        // Human: varies paragraph to paragraph (CV > 0.25)
        if ($cv < 0.10) return 80;
        if ($cv < 0.18) return 60;
        if ($cv < 0.25) return 40;
        if ($cv < 0.35) return 20;
        return 8;
    }

    private function emotionalFlatness(array $sentences): ?float {
        if (count($sentences) < 8) return null;

        $positive = ['great', 'excellent', 'wonderful', 'amazing', 'fantastic', 'love', 'enjoy', 'happy',
            'pleased', 'excited', 'thrilled', 'fortunate', 'grateful', 'passionate', 'inspiring',
            'remarkable', 'outstanding', 'impressive', 'brilliant', 'superb'];
        $negative = ['terrible', 'awful', 'horrible', 'hate', 'dislike', 'angry', 'frustrated',
            'disappointed', 'sad', 'unfortunate', 'poor', 'weak', 'fail', 'problem', 'difficult',
            'struggle', 'concern', 'worried', 'fear', 'danger', 'risk', 'threat'];
        $intensifiers = ['very', 'extremely', 'incredibly', 'absolutely', 'totally', 'completely',
            'utterly', 'deeply', 'highly', 'strongly', 'quite', 'rather'];

        $emotional_variation = [];
        foreach ($sentences as $s) {
            $sl = mb_strtolower(trim($s));
            $pos = 0;
            $neg = 0;
            foreach ($positive as $w) { if (str_contains($sl, $w)) $pos++; }
            foreach ($negative as $w) { if (str_contains($sl, $w)) $neg++; }
            $emotional_variation[] = $pos - $neg;
        }

        // Check variance in emotional tone
        $avg_emotion = array_sum($emotional_variation) / count($emotional_variation);
        $emotion_var = array_sum(array_map(fn($e) => ($e - $avg_emotion) ** 2, $emotional_variation)) / count($emotional_variation);

        // Also check intensifier usage (AI overuses formal modifiers)
        $text_lower = mb_strtolower(implode(' ', $sentences));
        $intensifier_count = 0;
        foreach ($intensifiers as $w) { $intensifier_count += substr_count($text_lower, $w); }
        $total_words = str_word_count($text_lower);
        $intensifier_density = $total_words > 0 ? ($intensifier_count / $total_words) * 100 : 0;

        // Low emotional variance + moderate intensifier use = AI
        $flat_score = max(0, min(80, (1 - sqrt($emotion_var) / 1.5) * 80));
        $int_score = max(0, min(20, ($intensifier_density > 1.5) ? 20 : ($intensifier_density * 13)));

        return $flat_score + $int_score;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  TEXT EXTRACTION HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    public function extractSubmissionText(array $row): string {
        $text = '';

        if (!empty($row['text_content'])) {
            $text .= strip_tags($row['text_content']) . "\n";
        }

        if (!empty($row['file_path'])) {
            $file_full = dirname(__DIR__) . '/uploads/submissions/' . $row['file_path'];
            if (file_exists($file_full)) {
                $ext = strtolower(pathinfo($file_full, PATHINFO_EXTENSION));
                if ($ext === 'txt') $text .= file_get_contents($file_full) . "\n";
                elseif ($ext === 'docx') $text .= $this->extractDocxText($file_full) . "\n";
                elseif ($ext === 'pdf') $text .= $this->extractPdfText($file_full) . "\n";
                elseif ($ext === 'doc') $text .= $this->extractDocText($file_full) . "\n";
                elseif ($ext === 'odt') $text .= $this->extractOdtText($file_full) . "\n";
            }
        }

        // Question answers
        if (!empty($row['student_id']) && !empty($row['a_id'])) {
            $ans_stmt = $this->conn->prepare("SELECT answer_text FROM vle_assignment_answers WHERE assignment_id = ? AND student_id = ?");
            if ($ans_stmt) {
                $ans_stmt->bind_param("is", $row['a_id'], $row['student_id']);
                $ans_stmt->execute();
                $ans_res = $ans_stmt->get_result();
                while ($ans = $ans_res->fetch_assoc()) {
                    if (!empty($ans['answer_text'])) {
                        $text .= strip_tags($ans['answer_text']) . "\n";
                    }
                }
            }
        }

        return trim($text);
    }

    public function extractDissertationText(array $row): string {
        $text = trim($row['submission_text'] ?? '');
        if (strlen($text) > 50) return $text;

        $file_path = $row['file_path'] ?? '';
        if (!$file_path) return $text;

        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
        if (!file_exists($abs)) return $text;

        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'docx': return $this->extractDocxText($abs);
            case 'odt': return $this->extractOdtText($abs);
            case 'txt': return preg_replace('/\s+/', ' ', trim(file_get_contents($abs)));
            case 'rtf':
                $raw = file_get_contents($abs);
                $raw = preg_replace('/\\\\[a-z]+\d*[ ]?/', '', $raw);
                return preg_replace('/\s+/', ' ', trim(preg_replace('/[{}]/', '', $raw)));
            case 'doc': return $this->extractDocText($abs);
            case 'pdf': return $this->extractPdfText($abs);
            default: return $text;
        }
    }

    public function extractDocxText(string $path): string {
        if (!file_exists($path) || !class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) return '';
        $text = strip_tags(str_replace(['</w:p>', '</w:r>'], ["\n", ' '], $xml));
        return preg_replace('/\s+/', ' ', trim($text));
    }

    public function extractPdfText(string $path): string {
        if (!file_exists($path)) return '';
        $content = file_get_contents($path);
        $text = '';

        if (preg_match_all('/stream\s*\n?(.*?)\n?endstream/s', $content, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = @gzuncompress($block);
                if ($decoded === false) $decoded = @gzinflate($block);
                if ($decoded === false) continue;

                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $decoded, $tj)) {
                    foreach ($tj[1] as $tjContent) {
                        if (preg_match_all('/\((.*?)\)/s', $tjContent, $strings)) {
                            $text .= implode('', $strings[1]);
                        }
                    }
                }
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $decoded, $tj2)) {
                    $text .= implode('', $tj2[1]);
                }
                if (preg_match_all('/BT\s*(.*?)\s*ET/s', $decoded, $bt)) {
                    foreach ($bt[1] as $btBlock) {
                        if (preg_match_all('/\((.*?)\)/s', $btBlock, $strings)) {
                            $text .= implode(' ', $strings[1]) . "\n";
                        }
                    }
                }
            }
        }

        if (strlen($text) < 50) {
            if (preg_match_all('/\(([\x20-\x7E]{4,})\)/', $content, $strings)) {
                $text = implode(' ', $strings[1]);
            }
        }

        return $text;
    }

    public function extractOdtText(string $path): string {
        if (!file_exists($path) || !class_exists('ZipArchive')) return '';
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return '';
        $xml = $zip->getFromName('content.xml');
        $zip->close();
        if (!$xml) return '';
        return preg_replace('/\s+/', ' ', trim(strip_tags(str_replace('<', ' <', $xml))));
    }

    public function extractDocText(string $path): string {
        if (!file_exists($path)) return '';
        $content = file_get_contents($path);
        $text = '';
        if (preg_match_all('/[\x20-\x7E]{5,}/', $content, $matches)) {
            $text = implode(' ', $matches[0]);
        }
        return $text;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  UTILITY METHODS
    // ═══════════════════════════════════════════════════════════════════════

    public function normalizeText(string $text): string {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    public function generateNgrams(string $text, int $n): array {
        $words = explode(' ', $text);
        $ngrams = [];
        $count = count($words);
        for ($i = 0; $i <= $count - $n; $i++) {
            $gram = implode(' ', array_slice($words, $i, $n));
            $ngrams[$gram] = true;
        }
        return $ngrams;
    }

    private function jaccardSimilarity(array $set1, array $set2): float {
        if (empty($set1) || empty($set2)) return 0.0;
        $intersection = count(array_intersect_key($set1, $set2));
        $union = count($set1) + count($set2) - $intersection;
        return $union > 0 ? ($intersection / $union) * 100 : 0.0;
    }

    private function extractSentencesForAI(string $text): array {
        $sents = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($sents, fn($s) => strlen(trim($s)) > 10));
    }

    private function estimateSyllables(string $text): int {
        $words = preg_split('/\s+/', strtolower(trim($text)));
        $total = 0;
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) === 0) continue;
            // Count vowel groups
            $syllables = preg_match_all('/[aeiouy]+/', $word);
            // Subtract silent e
            if (preg_match('/[^aeiou]e$/', $word) && $syllables > 1) $syllables--;
            // Every word has at least 1 syllable
            $total += max(1, $syllables);
        }
        return $total;
    }
}
