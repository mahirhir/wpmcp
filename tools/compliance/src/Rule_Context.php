<?php

namespace WPMCP\Compliance;

/**
 * Everything a rule is allowed to look at, with the expensive parses done
 * once per run.
 */
final class Rule_Context
{
    private ?Plugin_Header $header = null;
    private ?Readme_File $readme = null;
    private ?Http_Index $http_index = null;

    public function __construct(private Plugin_Source $source, private Profile $profile)
    {
    }

    public function source(): Plugin_Source
    {
        return $this->source;
    }

    public function profile(): Profile
    {
        return $this->profile;
    }

    /**
     * @return Source_File[]
     */
    public function php_files(): array
    {
        return $this->source->source_files();
    }

    public function header(): Plugin_Header
    {
        if (null === $this->header) {
            $this->header = new Plugin_Header($this->source->main_file());
        }
        return $this->header;
    }

    public function readme(): Readme_File
    {
        if (null === $this->readme) {
            $this->readme = new Readme_File($this->source->readme());
        }
        return $this->readme;
    }

    public function http_index(): Http_Index
    {
        if (null === $this->http_index) {
            $this->http_index = new Http_Index($this->php_files());
        }
        return $this->http_index;
    }

    /**
     * The wp.org slug: the plugin directory name, which for a scanned tree is
     * the main file's basename.
     */
    public function slug(): string
    {
        return $this->header()->slug();
    }

    public function text_domain(): string
    {
        return $this->header()->text_domain();
    }

    /**
     * Convenience factory used by the runner and by the tests.
     */
    public static function for_path(string $path, Profile $profile, ?array $excludes = null): self
    {
        return new self(
            new Plugin_Source($path, $excludes ?? Plugin_Source::DEFAULT_EXCLUDES),
            $profile
        );
    }
}
