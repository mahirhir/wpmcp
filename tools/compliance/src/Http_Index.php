<?php

namespace WPMCP\Compliance;

/**
 * Where the plugin talks to the network, and to whom.
 *
 * Built once per run and shared by the external-service disclosure rule, the
 * privacy-claim rule and the readme rule, so all three agree on the host list.
 *
 * Classification:
 *  - loopback: the call target is built from home_url()/rest_url()/site_url()
 *    and friends, so nothing leaves the site. Not reportable.
 *  - external: a networked file contains a literal http(s) host.
 *  - dynamic: a call site whose destination cannot be resolved statically
 *    (caller-supplied URL, or a host stored in an option). Reportable as
 *    "verify by hand", never silently ignored.
 *
 * Hosts carry a second axis, role:
 *  - requested: the URL literal is an expression the code acts on (a const, an
 *    assignment, a call argument), so a request plausibly goes there.
 *  - linked: every occurrence of the host is an array-element value, e.g.
 *    'license_url' => 'https://www.pexels.com/license/'. That string is data
 *    handed back to the caller; no request is ever made to it. Calling such a
 *    host "contacted" would be untrue, so it is reported separately and more
 *    quietly. It still has to appear in the readme when it is the service's
 *    terms or licence page, which is why it is not dropped outright.
 */
final class Http_Index
{
    public const HTTP_FUNCTIONS = [
        'wp_remote_get',
        'wp_remote_post',
        'wp_remote_head',
        'wp_remote_request',
        'wp_safe_remote_get',
        'wp_safe_remote_post',
        'wp_safe_remote_head',
        'wp_safe_remote_request',
        'curl_init',
        'curl_exec',
        'curl_setopt',
        'fsockopen',
        'stream_socket_client',
        'file_get_contents',
    ];

    private const LOOPBACK_MARKERS = [
        'home_url',
        'site_url',
        'rest_url',
        'admin_url',
        'get_rest_url',
        'get_home_url',
        'get_site_url',
        'network_home_url',
        'network_site_url',
        'plugins_url',
        'content_url',
        'includes_url',
    ];

    /**
     * Hosts that are never a third-party disclosure concern.
     */
    private const NON_NETWORK_HOSTS = [
        'example.com',
        'example.org',
        'example.net',
        'localhost',
        '127.0.0.1',
        'www.w3.org',
        'schemas.xmlsoap.org',
    ];

    /** @var array<int,array{file:string,line:int,function:string,kind:string}>|null */
    private ?array $call_sites = null;
    /** @var array<string,array<int,array{file:string,line:int}>>|null */
    private ?array $hosts = null;

    /**
     * @param Source_File[] $files
     */
    public function __construct(private array $files)
    {
    }

    /**
     * @return array<int,array{file:string,line:int,function:string,kind:string}>
     */
    public function call_sites(): array
    {
        $this->build();
        return $this->call_sites;
    }

    /**
     * @return array<int,array{file:string,line:int,function:string,kind:string}>
     */
    public function dynamic_call_sites(): array
    {
        return array_values(array_filter($this->call_sites(), static fn ($site) => 'dynamic' === $site['kind']));
    }

    /**
     * Every external host reachable from a file that makes network calls.
     *
     * @return array<string,array<int,array{file:string,line:int,role:string}>> host => occurrences
     */
    public function external_hosts(): array
    {
        $this->build();
        return $this->hosts;
    }

    /**
     * Hosts the plugin plausibly sends a request to. Excludes hosts that only
     * ever appear as an array-element value, which are links, not endpoints.
     *
     * @return array<string,array<int,array{file:string,line:int,role:string}>>
     */
    public function requested_hosts(): array
    {
        return array_filter(
            $this->external_hosts(),
            static fn (array $occurrences) => 'linked' !== self::role_of($occurrences)
        );
    }

    /**
     * @param array<int,array{file:string,line:int,role:string}> $occurrences
     */
    public static function role_of(array $occurrences): string
    {
        foreach ($occurrences as $occurrence) {
            if ('requested' === $occurrence['role']) {
                return 'requested';
            }
        }
        return 'linked';
    }

    public function has_network_activity(): bool
    {
        foreach ($this->call_sites() as $site) {
            if ('loopback' !== $site['kind']) {
                return true;
            }
        }
        return [] !== $this->external_hosts();
    }

    private function build(): void
    {
        if (null !== $this->call_sites) {
            return;
        }
        $this->call_sites = [];
        $this->hosts = [];

        foreach ($this->files as $file) {
            $sites = $this->call_sites_in($file);
            if ([] === $sites) {
                continue;
            }
            $this->call_sites = array_merge($this->call_sites, $sites);

            $has_outbound = false;
            foreach ($sites as $site) {
                if ('loopback' !== $site['kind']) {
                    $has_outbound = true;
                    break;
                }
            }
            if (! $has_outbound) {
                continue;
            }
            foreach ($this->host_literals($file) as $host => $occurrences) {
                foreach ($occurrences as $occurrence) {
                    $this->hosts[$host][] = [
                        'file' => $file->relative_path(),
                        'line' => $occurrence['line'],
                        'role' => $occurrence['role'],
                    ];
                }
            }
        }
        ksort($this->hosts);
    }

    /**
     * @return array<int,array{file:string,line:int,function:string,kind:string}>
     */
    private function call_sites_in(Source_File $file): array
    {
        $sites = [];
        foreach ($file->find_calls(self::HTTP_FUNCTIONS) as $call) {
            $window = $this->window($file, $call['line']);
            if ('file_get_contents' === $call['name'] && ! preg_match('#https?://#i', $window)) {
                // Local filesystem read, not a network call.
                continue;
            }
            if ('curl_setopt' === $call['name'] && ! preg_match('#https?://#i', $window)) {
                continue;
            }
            $sites[] = [
                'file' => $file->relative_path(),
                'line' => $call['line'],
                'function' => $call['name'],
                'kind' => $this->classify($file, $window),
            ];
        }
        return $sites;
    }

    private function classify(Source_File $file, string $window): string
    {
        foreach (self::LOOPBACK_MARKERS as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\s*\(/i', $window)) {
                return 'loopback';
            }
        }
        if (preg_match('#https?://#i', $window)) {
            return 'external';
        }
        foreach ($this->url_constants($file) as $name => $host) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $window)) {
                return 'external';
            }
        }
        return 'dynamic';
    }

    /**
     * The call line plus the three lines above it: enough to catch a URL
     * assembled immediately before the request.
     */
    private function window(Source_File $file, int $line): string
    {
        $text = '';
        for ($i = max(1, $line - 3); $i <= $line + 1; $i++) {
            $text .= $file->line($i) . "\n";
        }
        return $text;
    }

    /**
     * @return array<string,array<int,array{line:int,role:string}>> host => occurrences
     */
    private function host_literals(Source_File $file): array
    {
        $hosts = [];
        $seen = [];
        foreach ($file->string_literals() as $literal) {
            if (! preg_match_all('#https?://([A-Za-z0-9._\-]+)#i', $literal['value'], $matches)) {
                continue;
            }
            $role = ($literal['array_value'] ?? false) ? 'linked' : 'requested';
            foreach ($matches[1] as $host) {
                $host = strtolower(rtrim($host, '.'));
                if (in_array($host, self::NON_NETWORK_HOSTS, true)) {
                    continue;
                }
                $key = $host . ':' . $literal['line'] . ':' . $role;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $hosts[$host][] = ['line' => $literal['line'], 'role' => $role];
            }
        }
        return $hosts;
    }

    /**
     * Constants whose value is a URL, so a call site that references the
     * constant is still recognised as external.
     *
     * @return array<string,string> constant name => host
     */
    private function url_constants(Source_File $file): array
    {
        $constants = [];
        $pattern = '/(?:const\s+([A-Z_][A-Z0-9_]*)\s*=|define\s*\(\s*[\'"]([A-Z_][A-Z0-9_]*)[\'"]\s*,)'
            . '\s*[\'"](https?:\/\/([A-Za-z0-9._\-]+))/i';
        if (preg_match_all($pattern, $file->contents(), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = '' !== $match[1] ? $match[1] : $match[2];
                $constants[$name] = strtolower($match[4]);
            }
        }
        return $constants;
    }
}
