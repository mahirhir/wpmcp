<?php

namespace WPMCP\Compliance;

/**
 * The main plugin file's header block, parsed the way WordPress parses it
 * (first 8 KB, "Field: value" per line).
 */
final class Plugin_Header
{
    /** @var array<string,string> lowercased field name => value */
    private array $fields = [];
    private int $header_line = 0;

    public function __construct(private ?Source_File $file)
    {
        if (null !== $this->file) {
            $this->parse();
        }
    }

    public function file(): ?Source_File
    {
        return $this->file;
    }

    public function relative_path(): string
    {
        return null === $this->file ? '' : $this->file->relative_path();
    }

    public function get(string $field): ?string
    {
        return $this->fields[strtolower($field)] ?? null;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[strtolower($field)]);
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->fields;
    }

    /**
     * Line of a given header field, or the first line of the header block.
     */
    public function line_of(string $field): int
    {
        if (null === $this->file) {
            return 0;
        }
        $hits = $this->file->grep('/^[\s*#\/]*' . preg_quote($field, '/') . '\s*:/i');
        return $hits[0]['line'] ?? $this->header_line;
    }

    public function name(): string
    {
        return (string) $this->get('plugin name');
    }

    public function version(): string
    {
        return (string) $this->get('version');
    }

    public function text_domain(): string
    {
        $declared = $this->get('text domain');
        if (null !== $declared && '' !== $declared) {
            return $declared;
        }
        return $this->slug();
    }

    /**
     * wp.org derives the slug from the plugin folder; for a single-file scan
     * the main file's basename is the best available proxy.
     */
    public function slug(): string
    {
        if (null === $this->file) {
            return '';
        }
        return pathinfo($this->file->relative_path(), PATHINFO_FILENAME);
    }

    private function parse(): void
    {
        $head = substr($this->file->contents(), 0, 8192);
        foreach (preg_split('/\r\n|\r|\n/', $head) ?: [] as $index => $line) {
            if (! preg_match('/^[\s*#\/]*([A-Za-z][A-Za-z \-]*?)\s*:\s*(.*?)\s*$/', $line, $matches)) {
                continue;
            }
            $field = strtolower(trim($matches[1]));
            if (isset($this->fields[$field])) {
                continue;
            }
            $this->fields[$field] = $matches[2];
            if (0 === $this->header_line) {
                $this->header_line = $index + 1;
            }
        }
    }
}
