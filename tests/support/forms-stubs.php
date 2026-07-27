<?php
/**
 * Faithful global test doubles for the Formidable, Contact Form 7, and
 * WPForms read integrations. These plugins cannot all be installed from
 * wordpress.org in the harness (paid tiers, entry storage, heavy bootstraps),
 * so these reproduce the exact public API surface each integration calls,
 * verified against Formidable 6.x, Contact Form 7 5.x, and WPForms 1.8.x.
 * Live plugins remain production-verified. Real classes always win.
 */

// ---- Formidable: FrmForm / FrmEntry ----------------------------------------
if (! class_exists('FrmForm')) {
    class FrmForm
    {
        /** @var array<int,object> */
        public static array $forms = [];

        public static function getAll($where = [], $order_by = '', $limit = '')
        {
            return array_values(self::$forms);
        }

        public static function getOne($id)
        {
            return self::$forms[(int) $id] ?? false;
        }
    }
}
if (! class_exists('FrmEntry')) {
    class FrmEntry
    {
        /** @var array<int,object> */
        public static array $entries = [];

        public static function getAll($where = [], $order_by = '', $limit = '', $meta = false)
        {
            $form_id = (int) ($where['it.form_id'] ?? 0);
            return array_values(array_filter(self::$entries, static fn ($e) => (int) ($e->form_id ?? 0) === $form_id));
        }

        public static function getOne($id, $meta = false)
        {
            return self::$entries[(int) $id] ?? false;
        }
    }
}

// ---- Contact Form 7: WPCF7_ContactForm -------------------------------------
if (! class_exists('WPCF7_ContactForm')) {
    class WPCF7_ContactForm
    {
        /** @var array<int,\WPCF7_ContactForm> */
        public static array $registry = [];

        public int $_id = 0;
        public string $_title = '';
        public string $_name = '';
        public array $_props = [];

        public static function seed(int $id, string $title, string $name, array $props): void
        {
            $f = new self();
            $f->_id = $id;
            $f->_title = $title;
            $f->_name = $name;
            $f->_props = $props;
            self::$registry[$id] = $f;
        }

        public static function find($args = [])
        {
            return array_values(self::$registry);
        }

        public static function get_instance($id)
        {
            return self::$registry[(int) $id] ?? null;
        }

        public function id()
        {
            return $this->_id;
        }

        public function title()
        {
            return $this->_title;
        }

        public function name()
        {
            return $this->_name;
        }

        public function prop($name)
        {
            return $this->_props[$name] ?? '';
        }
    }
}

// ---- WPForms: wpforms()->form->get() ---------------------------------------
if (! function_exists('wpforms')) {
    class WPMCP_WPForms_Form_Stub
    {
        /** @var array<int,\WP_Post> */
        public array $forms = [];

        public function get($id = '', $args = [])
        {
            if ('' === $id || null === $id) {
                return array_values($this->forms);
            }
            return $this->forms[(int) $id] ?? null;
        }
    }
    class WPMCP_WPForms_Stub
    {
        public WPMCP_WPForms_Form_Stub $form;
        public function __construct()
        {
            $this->form = new WPMCP_WPForms_Form_Stub();
        }
    }
    $GLOBALS['wpmcp_wpforms_stub'] = new WPMCP_WPForms_Stub();
    function wpforms()
    {
        return $GLOBALS['wpmcp_wpforms_stub'];
    }
    function wpforms_decode($json)
    {
        return json_decode((string) $json, true);
    }
}
