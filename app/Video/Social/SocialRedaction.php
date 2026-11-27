<?php

declare(strict_types=1);

namespace App\Video\Social;

/**
 * What a message about a request to a platform may say. A transport error names the URL it failed on, and a URL of
 * Facebook or of an OAuth exchange carries a token, an app secret or a code in its query; an upload session URL is a
 * key of its own. Every message that can reach a posting, the screen, a flash message or a log goes through here first.
 *
 * Hiding is the same however often it is done — a message hidden once and hidden again reads the same — and only what
 * is a secret is hidden: "Invalid OAuth access token" keeps its words, a header's token does not.
 */
final class SocialRedaction
{
    /** @var list<string> the query keys whose value is a secret or a key */
    private const KEYS = ['access_token', 'client_secret', 'code', 'fb_exchange_token', 'refresh_token', 'appsecret_proof', 'input_token', 'upload_id'];

    public const HIDDEN = '[đã ẩn]';

    public static function text(string $text): string
    {
        // A value of one of the keys, unless it is already the mark. Matched byte by byte (no /u), so a message that is not UTF-8 is still hidden, never dropped.
        $text = (string) preg_replace('/([?&](?:'.implode('|', self::KEYS).')=)(?!'.preg_quote(self::HIDDEN, '/').')[^&\s"\'<>]*/i', '$1'.self::HIDDEN, $text);

        // A token after an authorization scheme: tokens are long runs of token characters, the words of a message are not.
        return (string) preg_replace('/\b(Bearer|OAuth)\s+[A-Za-z0-9._~+\/=-]{20,}/i', '$1 '.self::HIDDEN, $text);
    }
}
