<?php
/**
 * Faithful global test double for the Ninja Forms integration, reproducing the
 * Ninja_Forms()->form() model accessor surface the integration calls (verified
 * against Ninja Forms 3.x). Real Ninja_Forms() always wins.
 */

class NF_Test_Field
{
    public function __construct(private int $id, private array $settings)
    {
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_setting($key)
    {
        return $this->settings[$key] ?? '';
    }
}

class NF_Test_Form
{
    public function __construct(private int $id, private array $settings, private array $fields = [])
    {
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_setting($key)
    {
        return $this->settings[$key] ?? '';
    }

    /** @return NF_Test_Field[] */
    public function get_fields(): array
    {
        return $this->fields;
    }
}

class NF_Test_FormHandler
{
    /** @var array<int,NF_Test_Form> */
    public static array $forms = [];

    private ?int $current;

    public function __construct(?int $id = null)
    {
        $this->current = $id;
    }

    /** @return NF_Test_Form[] */
    public function get_forms(): array
    {
        return array_values(self::$forms);
    }

    public function get_form(): ?NF_Test_Form
    {
        return self::$forms[(int) $this->current] ?? null;
    }

    /** @return NF_Test_Field[] */
    public function get_fields(): array
    {
        $form = $this->get_form();
        return $form ? $form->get_fields() : [];
    }
}

class NF_Test_Container
{
    public function form(?int $id = null): NF_Test_FormHandler
    {
        return new NF_Test_FormHandler($id);
    }
}

if (! function_exists('Ninja_Forms')) {
    function Ninja_Forms(): NF_Test_Container
    {
        return new NF_Test_Container();
    }
}
