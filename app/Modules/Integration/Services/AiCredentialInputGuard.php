<?php

namespace App\Modules\Integration\Services;

/** Deterministic credential rejection before chat persistence; never returns matched material. */
final class AiCredentialInputGuard
{
    private const PATTERNS = [
        '~-----BEGIN\s+(?:[A-Z0-9 ]+\s+)?PRIVATE KEY-----~i',
        '~\b(?:authorization|proxy-authorization)\s*["\']?\s*[:=]\s*["\']?\s*(?:Bearer|Basic)\s+\S+~i',
        '~\bBearer\s+[A-Za-z0-9._\~+/-]{8,}=*~i',
        '~\b(?:api[\s_-]*key|client[\s_-]*secret|access[\s_-]*token|refresh[\s_-]*token|app[\s_-]*password|app[\s_-]*passord|recovery[\s_-]*codes?|recovery[\s_-]*key|backup[\s_-]*codes?|password|passwd|pwd|passord|secret|token)\b["\']?\s*(?::|=|\b(?:is|er)\b(?!\s+(?:expired|missing|invalid|revoked|disabled|required|configured|empty|utløpt|ugyldig)\b(?:[.!?]|\s*$)))\s*["\']?[^\s"\',;}\]]{3,}~iu',
        '~\b(?:sk-(?:proj-|or-v1-)?[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[A-Z0-9]{16})\b~',
        '~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b~',
        '~[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s/@]+@~i',
    ];

    public function rejects(mixed $input): bool
    {
        $nodes = 0;

        return $this->inspect($input, $nodes, 0);
    }

    private function inspect(mixed $value, int &$nodes, int $depth): bool
    {
        // Malformed nested inputs fail closed without recursively copying their contents.
        if (++$nodes > 2000 || $depth > 12) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && $item !== null && $item !== '' && $item !== []
                    && $this->inspect($key.'=credential-placeholder', $nodes, $depth + 1)) {
                    return true;
                }
                if ($this->inspect($item, $nodes, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($value)) {
            return false;
        }
        if (! mb_check_encoding($value, 'UTF-8') || strlen($value) > 100000) {
            return true;
        }

        // Inspect equivalent common text encodings, without replacing or retaining input.
        for ($pass = 0; $pass < 3; $pass++) {
            $value = preg_replace('/[\p{Cf}]/u', '', $value) ?? '';
            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $value) !== 0) {
                    return true;
                }
            }
            $decoded = rawurldecode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $decoded = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', static fn (array $m): string => json_decode('"\\u'.$m[1].'"') ?? '', $decoded) ?? '';
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return false;
    }
}
