<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wire-format normalization for the MCP `structuredContent` field
 * (issue #133).
 *
 * The MCP schema types `structuredContent` as `{ [key: string]: unknown }`:
 * a JSON *object*. The adapter assigns a tool's return value to that field
 * verbatim, so any tool that answers with a top-level JSON list produces a
 * response that strict clients reject outright with a dictionary-validation
 * error. The tool ran, the write happened, and the caller sees a protocol
 * failure. Our REST passthrough is the obvious exposure (plenty of core and
 * WooCommerce routes answer with a top-level array), but it is a hazard for
 * any tool that forwards another API's payload.
 *
 * Where this runs matters, and it is the point on which we deliberately
 * diverge from the competing implementation. Theirs wraps every ability's
 * execute_callback, so the *tool's own contract* changes: an ability
 * documented as returning a list now returns `{ data: [...] }` to every
 * caller, including this plugin's own internal call sites and its tests.
 * We normalize at the wire boundary instead, on the adapter's
 * mcp_adapter_tool_call_result filter. The ability contract stays exactly
 * what the ability declares, our meta-tools and internal dispatch keep
 * seeing the real shape, and only the JSON that leaves over MCP is
 * coerced. One place, no contract drift, and testable in isolation.
 *
 * The transform is deliberately minimal and idempotent:
 *  - string-keyed arrays and objects pass through untouched (the
 *    overwhelming majority of tool results);
 *  - WP_Error passes through untouched so the adapter's error handling
 *    still sees it as an error rather than a successful `{data: ...}`;
 *  - lists, scalars and null are wrapped in a `data` key.
 *
 * PHP cannot distinguish an empty list from an empty map, so `[]` is
 * wrapped too. That is the correct call: unwrapped it serializes to `[]`,
 * which is exactly the invalid shape this exists to prevent.
 */
class Structured_Result
{
    /** The key a non-object result is wrapped under. */
    public const WRAP_KEY = 'data';

    public function register(): void
    {
        add_filter('mcp_adapter_tool_call_result', [$this, 'filter_result'], 99);
    }

    /**
     * Callback for the MCP Adapter's mcp_adapter_tool_call_result filter.
     *
     * @param mixed $result The tool's return value.
     * @return mixed The same value, or an object-shaped wrapper around it.
     */
    public function filter_result($result)
    {
        return self::normalize($result);
    }

    /**
     * Coerce a tool result into something that serializes as a JSON object.
     * Idempotent: normalize(normalize($x)) === normalize($x).
     *
     * @param mixed $result
     * @return mixed
     */
    public static function normalize($result)
    {
        if (is_wp_error($result)) {
            return $result;
        }

        if (is_object($result)) {
            return $result;
        }

        if (is_array($result) && ! array_is_list($result)) {
            return $result;
        }

        return [self::WRAP_KEY => $result];
    }
}
