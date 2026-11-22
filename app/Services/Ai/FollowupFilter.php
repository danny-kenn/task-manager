<?php

namespace App\Services\Ai;

/**
 * Streams model text to the UI while hiding the trailing <followups>a|b|c</followups> tag, even when the tag
 * arrives split across chunks. split() pulls the suggestions out of the finished text.
 */
class FollowupFilter
{
    private const TAG = '<followups>';

    private string $pending = '';
    private bool $suppress = false;

    /** @param callable(string):void $emit */
    public function __construct(private $emit) {}

    public function push(string $d): void
    {
        if ($this->suppress) return;
        $this->pending .= $d;
        if (($p = stripos($this->pending, '<followups')) !== false) {
            $this->flush(substr($this->pending, 0, $p));
            $this->pending = '';
            $this->suppress = true;

            return;
        }
        // hold back a tail that could still become the tag
        $lt = strrpos($this->pending, '<');
        if ($lt !== false && str_starts_with(self::TAG, strtolower(substr($this->pending, $lt)))) {
            $this->flush(substr($this->pending, 0, $lt));
            $this->pending = substr($this->pending, $lt);

            return;
        }
        $this->flush($this->pending);
        $this->pending = '';
    }

    public function finish(): void
    {
        if (! $this->suppress && $this->pending !== '') $this->flush($this->pending);
        $this->pending = '';
    }

    private function flush(string $t): void
    {
        if ($t !== '') ($this->emit)($t);
    }

    /** @return array{0:string,1:string[]} [text without the tag, suggestions] */
    public static function split(string $text): array
    {
        $items = [];
        if (preg_match('/<followups>(.*?)(?:<\/followups>|$)/is', $text, $m)) {
            $items = array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, 70), preg_split('/[|\n]/', $m[1])))), 0, 4);
        }
        $clean = trim(preg_replace('/<followups>.*$/is', '', $text));

        return [$clean, $items];
    }
}
