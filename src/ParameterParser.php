<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Parses a directive parameter blob into key/value pairs.
 *
 * Unlike the legacy `(.*?)` capture, this consumes the blob with an explicit scanner, so a
 * parameter value can never swallow a `}}` and run on into unrelated template text.
 */
final class ParameterParser
{
    /** @return array<string,string> */
    public function parse(string $params): array
    {
        $out = [];
        $len = strlen($params);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ctype_space($params[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $keyStart = $i;
            while ($i < $len && !ctype_space($params[$i]) && $params[$i] !== '=') {
                $i++;
            }
            $key = substr($params, $keyStart, $i - $keyStart);
            if ($key === '') {
                $i++;
                continue;
            }

            while ($i < $len && ctype_space($params[$i])) {
                $i++;
            }

            if ($i >= $len || $params[$i] !== '=') {
                $out[$key] = '';           // bare flag
                continue;
            }
            $i++;                          // consume '='
            while ($i < $len && ctype_space($params[$i])) {
                $i++;
            }

            $out[$key] = $this->readValue($params, $i, $len);
        }

        return $out;
    }

    private function readValue(string $s, int &$i, int $len): string
    {
        if ($i < $len && ($s[$i] === '"' || $s[$i] === "'")) {
            $quote = $s[$i];
            $i++;
            $buf = '';
            while ($i < $len) {
                if ($s[$i] === '\\' && $i + 1 < $len && $s[$i + 1] === $quote) {
                    $buf .= $quote;
                    $i += 2;
                    continue;
                }
                if ($s[$i] === $quote) {
                    $i++;
                    return $buf;
                }
                $buf .= $s[$i];
                $i++;
            }
            return $buf;                   // unterminated quote: take the rest
        }

        $start = $i;
        while ($i < $len && !ctype_space($s[$i])) {
            $i++;
        }
        return substr($s, $start, $i - $start);
    }
}
