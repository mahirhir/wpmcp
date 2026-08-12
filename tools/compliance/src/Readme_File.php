<?php

namespace WPMCP\Compliance;

/**
 * readme.txt in the wp.org format: "=== Title ===", a header block of
 * "Field: value" lines, a short description, then "== Section ==" bodies.
 */
final class Readme_File
{
    private string $title = '';
    private int $title_line = 0;
    /** @var array<string,array{value:string,line:int}> */
    private array $headers = [];
    private string $short_description = '';
    private int $short_description_line = 0;
    /** @var array<string,array{body:string,line:int}> lowercased section name */
    private array $sections = [];

    public function __construct(private ?Source_File $file)
    {
        if (null !== $this->file) {
            $this->parse();
        }
    }

    public function exists(): bool
    {
        return null !== $this->file;
    }

    public function file(): ?Source_File
    {
        return $this->file;
    }

    public function relative_path(): string
    {
        return null === $this->file ? 'readme.txt' : $this->file->relative_path();
    }

    public function contents(): string
    {
        return null === $this->file ? '' : $this->file->contents();
    }

    public function size(): int
    {
        return strlen($this->contents());
    }

    public function title(): string
    {
        return $this->title;
    }

    public function title_line(): int
    {
        return $this->title_line;
    }

    public function header(string $field): ?string
    {
        return $this->headers[strtolower($field)]['value'] ?? null;
    }

    public function header_line(string $field): int
    {
        return $this->headers[strtolower($field)]['line'] ?? $this->title_line;
    }

    /**
     * @return array<string,array{value:string,line:int}>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return string[]
     */
    public function tags(): array
    {
        $raw = (string) $this->header('tags');
        if ('' === trim($raw)) {
            return [];
        }
        $tags = array_map('trim', explode(',', $raw));
        return array_values(array_filter($tags, static fn ($tag) => '' !== $tag));
    }

    public function short_description(): string
    {
        return $this->short_description;
    }

    public function short_description_line(): int
    {
        return $this->short_description_line;
    }

    public function has_section(string $name): bool
    {
        return isset($this->sections[strtolower($name)]);
    }

    public function section(string $name): ?string
    {
        return $this->sections[strtolower($name)]['body'] ?? null;
    }

    public function section_line(string $name): int
    {
        return $this->sections[strtolower($name)]['line'] ?? 0;
    }

    /**
     * @return string[]
     */
    public function section_names(): array
    {
        return array_keys($this->sections);
    }

    /**
     * 1-indexed line of the first occurrence of $needle, or 0.
     */
    public function line_of(string $needle): int
    {
        if (null === $this->file) {
            return 0;
        }
        foreach ($this->file->lines() as $number => $text) {
            if (false !== stripos($text, $needle)) {
                return $number;
            }
        }
        return 0;
    }

    public function mentions(string $needle): bool
    {
        return '' !== $needle && false !== stripos($this->contents(), $needle);
    }

    private function parse(): void
    {
        $lines = $this->file->lines();
        $current_section = null;
        $in_header_block = false;

        foreach ($lines as $number => $text) {
            $trimmed = trim($text);

            if (preg_match('/^===\s*(.+?)\s*===$/', $trimmed, $matches)) {
                $this->title = $matches[1];
                $this->title_line = $number;
                $in_header_block = true;
                continue;
            }

            if (preg_match('/^==\s*(.+?)\s*==$/', $trimmed, $matches)) {
                $current_section = strtolower($matches[1]);
                $this->sections[$current_section] = ['body' => '', 'line' => $number];
                $in_header_block = false;
                continue;
            }

            if (null !== $current_section) {
                $this->sections[$current_section]['body'] .= $text . "\n";
                continue;
            }

            if ($in_header_block && preg_match('/^([A-Za-z][A-Za-z \-]*?)\s*:\s*(.*?)\s*$/', $trimmed, $matches)) {
                $this->headers[strtolower(trim($matches[1]))] = [
                    'value' => $matches[2],
                    'line' => $number,
                ];
                continue;
            }

            if ($in_header_block && '' !== $trimmed) {
                // First non-header, non-blank line after the header block is
                // the short description.
                if ('' === $this->short_description) {
                    $this->short_description = $trimmed;
                    $this->short_description_line = $number;
                }
            }
        }
    }
}
