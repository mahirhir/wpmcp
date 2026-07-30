<?php
/**
 * Faithful global test double for the Fluent Forms integration, reproducing the
 * minimal wpFluent() query-builder surface the integration calls
 * (table()->get(), table()->where()->first()), verified against Fluent Forms
 * 5.x. Real wpFluent() always wins.
 */

class FF_Test_Query
{
    private ?int $where_id = null;

    /** @var array<int,object> */
    public static array $rows = [];

    public function where(string $col, $value): self
    {
        if ('id' === $col) {
            $this->where_id = (int) $value;
        }
        return $this;
    }

    /** @return array<int,object> */
    public function get(): array
    {
        return array_values(self::$rows);
    }

    public function first()
    {
        return self::$rows[(int) $this->where_id] ?? null;
    }
}

class FF_Test_DB
{
    public function table(string $name): FF_Test_Query
    {
        return new FF_Test_Query();
    }
}

if (! function_exists('wpFluent')) {
    function wpFluent(): FF_Test_DB
    {
        return new FF_Test_DB();
    }
}
