<?php
/**
 * Fabonic Real Estate — Subdomain word filter
 * Blocks offensive / unethical subdomain slugs (profanity, nudity, slurs,
 * scam terms). Used by api/check-slug.php and api/send-trial-request.php.
 *
 * Slugs are lowercase [a-z0-9] with no separators, so we substring-match and
 * also check a leetspeak-normalised copy (pr0n -> porn, s3x -> sex).
 *
 * Two tiers to avoid the "Scunthorpe problem" (blocking Essex, Sexton, Peacock,
 * Dickson, Assam, analytics, asset, cocktail, titanium...):
 *   - STRONG list  : unambiguous terms, matched ANYWHERE in the slug.
 *   - AMBIGUOUS list: short fragments that also appear inside innocent words,
 *                     matched ONLY when they are the entire slug.
 *
 * NOTE: first-pass filter, not perfect. The operator can also suspend a tenant
 * in admin/. To adjust, edit the two lists below.
 */

// Matched anywhere in the slug (unambiguous — very low false-positive risk).
function blockedStrongList() {
    return [
        // sexual / nudity
        'porn', 'pron', 'xxx', 'nude', 'naked', 'boob', 'boobs', 'bigtits',
        'pussy', 'vagina', 'penis', 'blowjob', 'handjob', 'cumshot', 'orgasm',
        'horny', 'milf', 'hentai', 'incest', 'bdsm', 'fetish', 'escort',
        'hooker', 'brothel', 'camgirl', 'onlyfans', 'nsfw', 'erotic', 'pornhub',
        'xvideos', 'xnxx', 'sexcam', 'sexchat', 'sextape', 'sexshop', 'sexvid',
        'sexy', 'analsex',
        // profanity
        'fuck', 'fuk', 'bullshit', 'shit', 'bitch', 'bastard', 'asshole',
        'arsehole', 'dickhead', 'motherfucker', 'wanker', 'bollocks', 'prick',
        'twat', 'slut', 'whore', 'skank', 'cunt',
        // slurs / hate
        'nigger', 'nigga', 'faggot', 'retard', 'chink', 'kike', 'wetback',
        'tranny',
        // violence / illegal / abuse
        'rape', 'rapist', 'pedo', 'pedophile', 'paedo', 'childporn', 'molest',
        'nazi', 'hitler', 'terrorist', 'jihad', 'cocaine', 'heroin',
        // scam / fraud
        'scammer', 'phishing', 'darkweb', 'ponzi',
    ];
}

// Matched ONLY as the entire slug, so innocent words that merely contain them
// (Dickson, Assam, asset, analytics, cocktail, titanium, essex...) are allowed.
function blockedAmbiguousList() {
    return [
        'sex', 'anal', 'ass', 'arse', 'cum', 'cock', 'dick', 'tit', 'tits',
        'fag', 'coon', 'spic', 'meth', 'weed', 'piss', 'scam', 'fraud',
    ];
}

/**
 * Returns true if the slug contains a blocked term.
 */
function slugIsBlocked($slug) {
    $slug = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $slug));
    if ($slug === '') return false;

    // Leetspeak / homoglyph normalisation to catch evasions like pr0n, s3x, f4g.
    $normal = strtr($slug, [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
        '5' => 's', '7' => 't', '8' => 'b', '9' => 'g',
    ]);
    $variants = array_unique([$slug, $normal]);

    foreach (blockedStrongList() as $w) {
        foreach ($variants as $v) {
            if (strpos($v, $w) !== false) return true;
        }
    }
    foreach (blockedAmbiguousList() as $w) {
        foreach ($variants as $v) {
            if ($v === $w) return true; // whole slug only
        }
    }
    return false;
}
