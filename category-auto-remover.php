<?php
/**
 * Plugin Name: Category Auto Remover
 * Description: Rimuove automaticamente categorie da un articolo quando è presente una specifica categoria “trigger”. Include: regole globali (impostazioni) e metabox per scegliere regole per singolo post. Pagina impostazioni con tab separati.
 * Version: 1.1.0
 * Author: DWAY SRL
 * Author URI: https://dway.agency
 * License: GPLv2 or later
 * Text Domain: category-auto-remover
 */

if (!defined('ABSPATH')) {
    exit;
}

class Category_Auto_Remover {
    const OPTION_KEY = 'car_rules'; // Regole globali
    const OPTION_PREFS = 'car_prefs'; // Preferenze varie

    // Meta keys per metabox
    const META_ENABLED = '_car_enabled';
    const META_TRIGGER = '_car_trigger';
    const META_REMOVE  = '_car_remove'; // array di term_id

    public function __construct() {
        // Admin
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Metabox per post
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_post_metabox'], 10, 2);

        // Applica regole su salvataggio (dopo aver salvato il metabox)
        add_action('save_post', [$this, 'maybe_remove_categories'], 20, 3);
    }

    /**
     * Pagina impostazioni con tab: Regole globali / Preferenze
     */
    public function add_settings_page() {
        add_options_page(
            __('Category Auto Remover', 'category-auto-remover'),
            __('Category Auto Remover', 'category-auto-remover'),
            'manage_options',
            'category-auto-remover',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            'category_auto_remover_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_rules'],
                'default' => [],
            ]
        );

        register_setting(
            'category_auto_remover_group',
            self::OPTION_PREFS,
            [
                'type' => 'array',
                'sanitize_callback' => function($input){
                    return [
                        'enable_metabox' => !empty($input['enable_metabox']) ? 1 : 0,
                        'post_types'     => isset($input['post_types']) && is_array($input['post_types']) ? array_map('sanitize_text_field', $input['post_types']) : ['post'],
                    ];
                },
                'default' => [
                    'enable_metabox' => 1,
                    'post_types' => ['post'],
                ],
            ]
        );
    }

    public function sanitize_rules($input) {
        $rules = [];
        if (is_array($input)) {
            foreach ($input as $rule) {
                $trigger = isset($rule['trigger']) ? intval($rule['trigger']) : 0;
                $remove  = isset($rule['remove']) && is_array($rule['remove'])
                    ? array_values(array_unique(array_filter(array_map('intval', $rule['remove']))))
                    : [];
                if ($trigger > 0 && !empty($remove)) {
                    $remove = array_diff($remove, [$trigger]);
                    if (!empty($remove)) {
                        $rules[] = [
                            'trigger' => $trigger,
                            'remove'  => array_values($remove),
                        ];
                    }
                }
            }
        }
        return $rules;
    }

    /**
     * Impostazioni (con tab)
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'rules';
        $rules = get_option(self::OPTION_KEY, []);
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post'],
        ]);

        $all_categories = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if (is_wp_error($all_categories)) $all_categories = [];

        $post_types = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Category Auto Remover', 'category-auto-remover'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=category-auto-remover&tab=rules')); ?>" class="nav-tab <?php echo ($active_tab === 'rules') ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Regole globali', 'category-auto-remover'); ?></a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=category-auto-remover&tab=prefs')); ?>" class="nav-tab <?php echo ($active_tab === 'prefs') ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Preferenze / Metabox', 'category-auto-remover'); ?></a>
            </h2>

            <?php if ($active_tab === 'rules') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('category_auto_remover_group'); ?>
                    <table class="form-table" id="car-rules-table" role="presentation">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Categoria trigger', 'category-auto-remover'); ?></th>
                                <th><?php esc_html_e('Categorie da rimuovere', 'category-auto-remover'); ?></th>
                                <th><?php esc_html_e('Azione', 'category-auto-remover'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rules)) { $rules = [['trigger' => 0, 'remove' => []]]; }
                        foreach ($rules as $index => $rule) :
                            $trigger_val = intval($rule['trigger']);
                            $remove_vals = array_map('intval', (array) ($rule['remove'] ?? []));
                        ?>
                            <tr class="car-rule-row">
                                <td style="vertical-align: top; min-width: 260px;">
                                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($index); ?>][trigger]" class="car-trigger" style="min-width:260px;">
                                        <option value="0">— <?php esc_html_e('Seleziona categoria trigger', 'category-auto-remover'); ?> —</option>
                                        <?php foreach ($all_categories as $cat): ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($trigger_val, $cat->term_id); ?>>
                                                <?php echo esc_html($cat->name . ' (ID: ' . $cat->term_id . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td style="vertical-align: top;">
                                    <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($index); ?>][remove][]" class="car-remove" multiple size="10" style="min-width:320px;">
                                        <?php foreach ($all_categories as $cat): ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>" <?php echo in_array($cat->term_id, $remove_vals, true) ? 'selected' : ''; ?>>
                                                <?php echo esc_html($cat->name . ' (ID: ' . $cat->term_id . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e('Tieni premuto CTRL (o CMD su Mac) per selezionare più categorie.', 'category-auto-remover'); ?></p>
                                </td>
                                <td style="vertical-align: top;">
                                    <button type="button" class="button button-link-delete car-remove-row"><?php esc_html_e('Rimuovi regola', 'category-auto-remover'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="car-add-rule"><?php esc_html_e('Aggiungi regola', 'category-auto-remover'); ?></button>
                    </p>
                    <?php submit_button(__('Salva impostazioni', 'category-auto-remover')); ?>
                </form>

                <script>
                (function(){
                    const table = document.getElementById('car-rules-table').getElementsByTagName('tbody')[0];
                    const addBtn = document.getElementById('car-add-rule');

                    function onRemoveClick(e){
                        const row = e.target.closest('.car-rule-row');
                        if(row){
                            if (table.querySelectorAll('.car-rule-row').length > 1) {
                                row.remove();
                                renumberRows();
                            } else {
                                row.querySelector('.car-trigger').value = '0';
                                const multi = row.querySelector('.car-remove');
                                if (multi) {
                                    for (let i=0; i<multi.options.length; i++) multi.options[i].selected = false;
                                }
                            }
                        }
                    }

                    function renumberRows(){
                        const rows = table.querySelectorAll('.car-rule-row');
                        rows.forEach((row, idx) => {
                            const trigger = row.querySelector('.car-trigger');
                            const remove = row.querySelector('.car-remove');
                            if(trigger){ trigger.name = '<?php echo esc_js(self::OPTION_KEY); ?>['+idx+'][trigger]'; }
                            if(remove){ remove.name = '<?php echo esc_js(self::OPTION_KEY); ?>['+idx+'][remove][]'; }
                        });
                    }

                    table.addEventListener('click', function(e){
                        if (e.target && e.target.classList.contains('car-remove-row')) onRemoveClick(e);
                    });

                    addBtn.addEventListener('click', function(){
                        const last = table.querySelector('.car-rule-row:last-child');
                        const clone = last.cloneNode(true);
                        const trigger = clone.querySelector('.car-trigger');
                        const remove = clone.querySelector('.car-remove');
                        if (trigger) trigger.value = '0';
                        if (remove) {
                            for (let i=0; i<remove.options.length; i++) remove.options[i].selected = false;
                        }
                        table.appendChild(clone);
                        renumberRows();
                    });
                })();
                </script>

            <?php else : // prefs tab ?>
                <form method="post" action="options.php">
                    <?php settings_fields('category_auto_remover_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Abilita metabox per post', 'category-auto-remover'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_PREFS); ?>[enable_metabox]" value="1" <?php checked(1, (int)$prefs['enable_metabox']); ?> />
                                    <?php esc_html_e('Mostra un metabox nell’editor per scegliere regole personalizzate sul singolo post.', 'category-auto-remover'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Tipi di post abilitati al metabox', 'category-auto-remover'); ?></th>
                            <td>
                                <?php foreach ($post_types as $pt) : ?>
                                    <label style="display:inline-block;margin-right:12px;">
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_PREFS); ?>[post_types][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, (array)$prefs['post_types'], true)); ?> />
                                        <?php echo esc_html($pt->labels->singular_name . ' (' . $pt->name . ')'); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e('Per default solo “post”.', 'category-auto-remover'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Salva preferenze', 'category-auto-remover')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Metabox per selezionare regole sul singolo post
     */
    public function register_metabox() {
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post'],
        ]);
        if (empty($prefs['enable_metabox'])) return;
        $post_types = (array) $prefs['post_types'];
        foreach ($post_types as $pt) {
            add_meta_box(
                'car_metabox',
                __('Category Auto Remover', 'category-auto-remover'),
                [$this, 'render_metabox'],
                $pt,
                'side',
                'default'
            );
        }
    }

    public function render_metabox($post) {
        wp_nonce_field('car_metabox_nonce', 'car_metabox_nonce_field');
        $enabled = (int) get_post_meta($post->ID, self::META_ENABLED, true);
        $trigger = (int) get_post_meta($post->ID, self::META_TRIGGER, true);
        $remove  = (array) get_post_meta($post->ID, self::META_REMOVE, true);
        $remove  = array_map('intval', $remove);

        $all_categories = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if (is_wp_error($all_categories)) $all_categories = [];
        ?>
        <p>
            <label>
                <input type="checkbox" name="car_enabled" value="1" <?php checked(1, $enabled); ?> />
                <?php esc_html_e('Attiva regola personalizzata per questo post', 'category-auto-remover'); ?>
            </label>
        </p>
        <p>
            <label for="car_trigger"><strong><?php esc_html_e('Categoria trigger', 'category-auto-remover'); ?></strong></label><br />
            <select id="car_trigger" name="car_trigger" style="width:100%;">
                <option value="0">— <?php esc_html_e('Seleziona categoria trigger', 'category-auto-remover'); ?> —</option>
                <?php foreach ($all_categories as $cat): ?>
                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($trigger, $cat->term_id); ?>>
                        <?php echo esc_html($cat->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="car_remove"><strong><?php esc_html_e('Categorie da rimuovere', 'category-auto-remover'); ?></strong></label><br />
            <select id="car_remove" name="car_remove[]" multiple size="8" style="width:100%;">
                <?php foreach ($all_categories as $cat): ?>
                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php echo in_array($cat->term_id, $remove, true) ? 'selected' : ''; ?>>
                        <?php echo esc_html($cat->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small><?php esc_html_e('Tieni premuto CTRL (o CMD su Mac) per selezionare più categorie.', 'category-auto-remover'); ?></small>
        </p>
        <?php
    }

    public function save_post_metabox($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['car_metabox_nonce_field']) || !wp_verify_nonce($_POST['car_metabox_nonce_field'], 'car_metabox_nonce')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $enabled = !empty($_POST['car_enabled']) ? 1 : 0;
        $trigger = isset($_POST['car_trigger']) ? intval($_POST['car_trigger']) : 0;
        $remove  = isset($_POST['car_remove']) && is_array($_POST['car_remove']) ? array_map('intval', $_POST['car_remove']) : [];
        $remove  = array_values(array_unique(array_diff($remove, [$trigger])));

        update_post_meta($post_id, self::META_ENABLED, $enabled);
        update_post_meta($post_id, self::META_TRIGGER, $trigger);
        update_post_meta($post_id, self::META_REMOVE, $remove);
    }

    /**
     * Applica regole globali + (eventualmente) regola personalizzata del post
     */
    public function maybe_remove_categories($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        // Considera solo post_type pubblici (potrebbe includere CPT)
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post']
        ]);
        $allowed_types = (array) $prefs['post_types'];
        if (!in_array($post->post_type, $allowed_types, true)) return;

        // Categorie attualmente assegnate
        $assigned_set = wp_get_post_categories($post_id, ['fields' => 'ids']);
        $assigned_set = array_map('intval', (array)$assigned_set);
        if (empty($assigned_set)) return;

        $modified = false;

        // 1) Applica regole globali
        $rules = get_option(self::OPTION_KEY, []);
        if (!empty($rules) && is_array($rules)) {
            foreach ($rules as $rule) {
                $trigger = intval($rule['trigger']);
                $remove  = array_map('intval', (array) ($rule['remove'] ?? []));
                if ($trigger && in_array($trigger, $assigned_set, true)) {
                    $before = $assigned_set;
                    $assigned_set = array_values(array_diff($assigned_set, $remove));
                    if (!in_array($trigger, $assigned_set, true)) $assigned_set[] = $trigger;
                    if ($before !== $assigned_set) $modified = true;
                }
            }
        }

        // 2) Applica regola del singolo post se abilitata
        $enabled = (int) get_post_meta($post_id, self::META_ENABLED, true);
        if ($enabled) {
            $trigger = (int) get_post_meta($post_id, self::META_TRIGGER, true);
            $remove  = (array) get_post_meta($post_id, self::META_REMOVE, true);
            $remove  = array_map('intval', $remove);
            if ($trigger && in_array($trigger, $assigned_set, true)) {
                $before = $assigned_set;
                $assigned_set = array_values(array_diff($assigned_set, $remove));
                if (!in_array($trigger, $assigned_set, true)) $assigned_set[] = $trigger;
                if ($before !== $assigned_set) $modified = true;
            }
        }

        if ($modified) {
            // Evita recursion del save_post
            remove_action('save_post', [$this, 'maybe_remove_categories'], 20);
            wp_set_post_categories($post_id, $assigned_set, false);
            add_action('save_post', [$this, 'maybe_remove_categories'], 20, 3);
        }
    }
}

new Category_Auto_Remover();
