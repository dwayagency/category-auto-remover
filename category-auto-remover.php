<?php
/**
 * Plugin Name: Category Auto Remover
 * Description: Rimuove automaticamente categorie da un articolo quando è presente una specifica categoria "trigger". Include: regole globali (impostazioni) e metabox per scegliere regole per singolo post. Pagina impostazioni con tab separati.
 * Version: 1.2.0
 * Author: DWAY SRL
 * Author URI: https://dway.agency
 * License: GPLv2 or later
 * Text Domain: category-auto-remover
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent direct access
if (!function_exists('add_action')) {
    exit;
}

class Category_Auto_Remover {
    const OPTION_KEY = 'car_rules'; // Regole globali
    const OPTION_PREFS = 'car_prefs'; // Preferenze varie
    const VERSION = '1.2.0';

    // Meta keys per metabox
    const META_ENABLED = '_car_enabled';
    const META_TRIGGER = '_car_trigger';
    const META_REMOVE  = '_car_remove'; // array di term_id

    /**
     * Cache per evitare query multiple
     */
    private static $categories_cache = null;

    public function __construct() {
        // Debug: verifica che il plugin si stia caricando
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Category Auto Remover: Plugin loaded successfully');
        }
        
        // Admin
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Metabox per post
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('save_post', [$this, 'save_post_metabox'], 10, 2);

        // Applica regole su salvataggio (dopo aver salvato il metabox)
        add_action('save_post', [$this, 'maybe_remove_categories'], 20, 3);

        // Hook per cleanup quando necessario
        add_action('delete_category', [$this, 'cleanup_deleted_category']);
        
        // AJAX per applicazione bulk
        add_action('wp_ajax_car_apply_bulk_rules', [$this, 'ajax_apply_bulk_rules']);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'category-auto-remover') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_add_inline_style('wp-admin', '
            .car-rule-row { border-bottom: 1px solid #ddd; }
            .car-rule-row:hover { background-color: #f9f9f9; }
            .car-remove-row { color: #a00; }
            .car-remove-row:hover { color: #dc3232; }
            .car-trigger, .car-remove { margin-bottom: 5px; }
            .car-add-rule { margin-top: 10px; }
            .version { font-size: 0.6em; color: #666; font-weight: normal; }
            .card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin: 20px 0; padding: 20px; }
            .card h2 { margin-top: 0; }
            .progress-bar { margin: 10px 0; }
        ');
    }

    /**
     * Ottieni categorie con cache
     */
    private function get_categories() {
        if (self::$categories_cache === null) {
            $categories = get_terms([
                'taxonomy'   => 'category',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);
            
            if (is_wp_error($categories)) {
                self::$categories_cache = [];
            } else {
                self::$categories_cache = $categories;
            }
        }
        
        return self::$categories_cache;
    }

    /**
     * Cleanup quando una categoria viene eliminata
     */
    public function cleanup_deleted_category($term_id) {
        $rules = get_option(self::OPTION_KEY, []);
        $updated = false;
        
        foreach ($rules as $index => $rule) {
            if ($rule['trigger'] == $term_id || in_array($term_id, $rule['remove'])) {
                unset($rules[$index]);
                $updated = true;
            }
        }
        
        if ($updated) {
            update_option(self::OPTION_KEY, array_values($rules));
        }
        
        // Clear cache
        self::$categories_cache = null;
    }

    /**
     * Pagina impostazioni con tab: Regole globali / Preferenze
     */
    public function add_settings_page() {
        // Debug: verifica che la funzione venga chiamata
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Category Auto Remover: Adding settings page');
        }
        
        // Menu principale separato
        add_menu_page(
            __('Category Auto Remover', 'category-auto-remover'),
            __('Category Auto Remover', 'category-auto-remover'),
            'manage_options',
            'category-auto-remover',
            [$this, 'render_settings_page'],
            'dashicons-admin-tools',
            30
        );
        
        // Sottomenu per le impostazioni
        add_submenu_page(
            'category-auto-remover',
            __('Impostazioni', 'category-auto-remover'),
            __('Impostazioni', 'category-auto-remover'),
            'manage_options',
            'category-auto-remover',
            [$this, 'render_settings_page']
        );
        
        // Sottomenu per applicare regole ai post esistenti
        add_submenu_page(
            'category-auto-remover',
            __('Applica a Post Esistenti', 'category-auto-remover'),
            __('Applica a Post Esistenti', 'category-auto-remover'),
            'manage_options',
            'category-auto-remover-bulk',
            [$this, 'render_bulk_page']
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
        if (!is_array($input)) {
            return $rules;
        }
        
        foreach ($input as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            
            $trigger = isset($rule['trigger']) ? intval($rule['trigger']) : 0;
            $remove  = isset($rule['remove']) && is_array($rule['remove'])
                ? array_values(array_unique(array_filter(array_map('intval', $rule['remove']))))
                : [];
            
            // Validazione: trigger deve essere valido e remove non può essere vuoto
            if ($trigger > 0 && !empty($remove)) {
                // Rimuovi il trigger dalla lista delle categorie da rimuovere
                $remove = array_diff($remove, [$trigger]);
                if (!empty($remove)) {
                    // Verifica che le categorie esistano ancora
                    $valid_categories = wp_list_pluck($this->get_categories(), 'term_id');
                    $trigger_valid = in_array($trigger, $valid_categories, true);
                    $remove_valid = array_intersect($remove, $valid_categories);
                    
                    if ($trigger_valid && !empty($remove_valid)) {
                        $rules[] = [
                            'trigger' => $trigger,
                            'remove'  => array_values($remove_valid),
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
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.', 'category-auto-remover'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'rules';
        $rules = get_option(self::OPTION_KEY, []);
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post'],
        ]);

        $all_categories = $this->get_categories();
        $post_types = get_post_types(['public' => true], 'objects');
        
        // Controlla se ci sono categorie disponibili
        if (empty($all_categories)) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Category Auto Remover', 'category-auto-remover'); ?> <span class="version">v<?php echo esc_html(self::VERSION); ?></span></h1>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Nessuna categoria trovata. Crea almeno una categoria per utilizzare questo plugin.', 'category-auto-remover'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Category Auto Remover', 'category-auto-remover'); ?> <span class="version">v<?php echo esc_html(self::VERSION); ?></span></h1>
            <p class="description"><?php esc_html_e('Configura le regole per rimuovere automaticamente categorie quando è presente una categoria trigger specifica.', 'category-auto-remover'); ?></p>
            
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=category-auto-remover&tab=rules')); ?>" class="nav-tab <?php echo ($active_tab === 'rules') ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Regole globali', 'category-auto-remover'); ?></a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=category-auto-remover&tab=prefs')); ?>" class="nav-tab <?php echo ($active_tab === 'prefs') ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Preferenze / Metabox', 'category-auto-remover'); ?></a>
            </h2>

            <?php if ($active_tab === 'rules') : ?>
                <div class="notice notice-info">
                    <p><strong><?php esc_html_e('Come funziona:', 'category-auto-remover'); ?></strong></p>
                    <ul>
                        <li><?php esc_html_e('Seleziona una categoria "trigger" che attiverà la regola', 'category-auto-remover'); ?></li>
                        <li><?php esc_html_e('Scegli le categorie da rimuovere automaticamente quando il trigger è presente', 'category-auto-remover'); ?></li>
                        <li><?php esc_html_e('La categoria trigger rimane sempre assegnata al post', 'category-auto-remover'); ?></li>
                    </ul>
                </div>
                
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
        if (!$post || !isset($post->ID)) {
            return;
        }
        
        wp_nonce_field('car_metabox_nonce', 'car_metabox_nonce_field');
        $enabled = (int) get_post_meta($post->ID, self::META_ENABLED, true);
        $trigger = (int) get_post_meta($post->ID, self::META_TRIGGER, true);
        $remove  = (array) get_post_meta($post->ID, self::META_REMOVE, true);
        $remove  = array_map('intval', $remove);

        $all_categories = $this->get_categories();
        
        // Se non ci sono categorie, mostra un messaggio
        if (empty($all_categories)) {
            echo '<p>' . esc_html__('Nessuna categoria disponibile.', 'category-auto-remover') . '</p>';
            return;
        }
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
        // Controlli di sicurezza
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['car_metabox_nonce_field']) || !wp_verify_nonce($_POST['car_metabox_nonce_field'], 'car_metabox_nonce')) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!$post || !isset($post->post_type)) return;

        $enabled = !empty($_POST['car_enabled']) ? 1 : 0;
        $trigger = isset($_POST['car_trigger']) ? intval($_POST['car_trigger']) : 0;
        $remove  = isset($_POST['car_remove']) && is_array($_POST['car_remove']) ? array_map('intval', $_POST['car_remove']) : [];
        
        // Validazione: rimuovi il trigger dalla lista delle categorie da rimuovere
        $remove = array_values(array_unique(array_diff($remove, [$trigger])));
        
        // Verifica che le categorie esistano ancora
        if ($trigger > 0 || !empty($remove)) {
            $valid_categories = wp_list_pluck($this->get_categories(), 'term_id');
            
            if ($trigger > 0 && !in_array($trigger, $valid_categories, true)) {
                $trigger = 0;
            }
            
            $remove = array_intersect($remove, $valid_categories);
        }

        update_post_meta($post_id, self::META_ENABLED, $enabled);
        update_post_meta($post_id, self::META_TRIGGER, $trigger);
        update_post_meta($post_id, self::META_REMOVE, $remove);
    }

    /**
     * Applica regole globali + (eventualmente) regola personalizzata del post
     */
    public function maybe_remove_categories($post_id, $post, $update) {
        // Controlli di sicurezza
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || !isset($post->post_type)) return;

        // Considera solo post_type pubblici (potrebbe includere CPT)
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post']
        ]);
        $allowed_types = (array) $prefs['post_types'];
        if (!in_array($post->post_type, $allowed_types, true)) return;

        // Categorie attualmente assegnate
        $assigned_set = wp_get_post_categories($post_id, ['fields' => 'ids']);
        if (is_wp_error($assigned_set) || empty($assigned_set)) return;
        
        $assigned_set = array_map('intval', (array)$assigned_set);
        $original_set = $assigned_set;
        $modified = false;

        // 1) Applica regole globali
        $rules = get_option(self::OPTION_KEY, []);
        if (!empty($rules) && is_array($rules)) {
            foreach ($rules as $rule) {
                if (!isset($rule['trigger']) || !isset($rule['remove'])) continue;
                
                $trigger = intval($rule['trigger']);
                $remove  = array_map('intval', (array) ($rule['remove'] ?? []));
                
                if ($trigger && in_array($trigger, $assigned_set, true)) {
                    $before = $assigned_set;
                    $assigned_set = array_values(array_diff($assigned_set, $remove));
                    // Mantieni sempre il trigger
                    if (!in_array($trigger, $assigned_set, true)) {
                        $assigned_set[] = $trigger;
                    }
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
                // Mantieni sempre il trigger
                if (!in_array($trigger, $assigned_set, true)) {
                    $assigned_set[] = $trigger;
                }
                if ($before !== $assigned_set) $modified = true;
            }
        }

        // Applica le modifiche se necessario
        if ($modified && $original_set !== $assigned_set) {
            // Evita recursion del save_post
            remove_action('save_post', [$this, 'maybe_remove_categories'], 20);
            
            $result = wp_set_post_categories($post_id, $assigned_set, false);
            
            // Log per debug (opzionale)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Category Auto Remover: Post {$post_id} categories updated from " . implode(',', $original_set) . " to " . implode(',', $assigned_set));
            }
            
            add_action('save_post', [$this, 'maybe_remove_categories'], 20, 3);
        }
    }

    /**
     * Pagina per applicare regole ai post esistenti
     */
    public function render_bulk_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.', 'category-auto-remover'));
        }

        $rules = get_option(self::OPTION_KEY, []);
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'enable_metabox' => 1,
            'post_types' => ['post'],
        ]);

        if (empty($rules)) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Applica Regole a Post Esistenti', 'category-auto-remover'); ?></h1>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Nessuna regola globale configurata. Vai alle Impostazioni per creare delle regole.', 'category-auto-remover'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Conta i post che potrebbero essere interessati
        $post_types = (array) $prefs['post_types'];
        $total_posts = 0;
        foreach ($post_types as $post_type) {
            $count = wp_count_posts($post_type);
            if ($count && isset($count->publish)) {
                $total_posts += $count->publish;
            }
        }
        
        // Gestisci filtro per data
        $date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Applica Regole a Post Esistenti', 'category-auto-remover'); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php esc_html_e('Attenzione:', 'category-auto-remover'); ?></strong></p>
                <ul>
                    <li><?php esc_html_e('Questa operazione applicherà le regole globali a tutti i post pubblicati', 'category-auto-remover'); ?></li>
                    <li><?php esc_html_e('I post con regole personalizzate (metabox) non verranno modificati', 'category-auto-remover'); ?></li>
                    <li><?php esc_html_e('L\'operazione potrebbe richiedere del tempo per siti con molti post', 'category-auto-remover'); ?></li>
                </ul>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Filtri Data', 'category-auto-remover'); ?></h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="category-auto-remover-bulk" />
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Filtra per data', 'category-auto-remover'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="date_filter" value="" <?php checked($date_filter, ''); ?> />
                                    <?php esc_html_e('Tutti i post', 'category-auto-remover'); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="date_filter" value="from" <?php checked($date_filter, 'from'); ?> />
                                    <?php esc_html_e('Post pubblicati dal', 'category-auto-remover'); ?>
                                </label><br />
                                <label>
                                    <input type="radio" name="date_filter" value="range" <?php checked($date_filter, 'range'); ?> />
                                    <?php esc_html_e('Post pubblicati tra', 'category-auto-remover'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr id="date-from-row" style="<?php echo ($date_filter === 'from' || $date_filter === 'range') ? '' : 'display: none;'; ?>">
                            <th scope="row"><?php esc_html_e('Data inizio', 'category-auto-remover'); ?></th>
                            <td>
                                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
                                <p class="description"><?php esc_html_e('Solo post pubblicati da questa data in poi', 'category-auto-remover'); ?></p>
                            </td>
                        </tr>
                        <tr id="date-to-row" style="<?php echo ($date_filter === 'range') ? '' : 'display: none;'; ?>">
                            <th scope="row"><?php esc_html_e('Data fine', 'category-auto-remover'); ?></th>
                            <td>
                                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
                                <p class="description"><?php esc_html_e('Solo post pubblicati fino a questa data', 'category-auto-remover'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Applica Filtri', 'category-auto-remover'), 'secondary', 'apply_filters'); ?>
                </form>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Statistiche', 'category-auto-remover'); ?></h2>
                <?php
                // Calcola post filtrati per data
                $filtered_posts = $total_posts;
                if ($date_filter === 'from' && $date_from) {
                    $filtered_posts = $this->count_posts_by_date($post_types, $date_from, '');
                } elseif ($date_filter === 'range' && $date_from && $date_to) {
                    $filtered_posts = $this->count_posts_by_date($post_types, $date_from, $date_to);
                }
                ?>
                <p><?php printf(__('Post totali: <strong>%d</strong>', 'category-auto-remover'), $total_posts); ?></p>
                <p><?php printf(__('Post da processare: <strong>%d</strong>', 'category-auto-remover'), $filtered_posts); ?></p>
                <p><?php printf(__('Regole globali configurate: <strong>%d</strong>', 'category-auto-remover'), count($rules)); ?></p>
                <p><?php printf(__('Tipi di post interessati: <strong>%s</strong>', 'category-auto-remover'), implode(', ', $post_types)); ?></p>
                <?php if ($date_filter): ?>
                    <p><strong><?php esc_html_e('Filtro attivo:', 'category-auto-remover'); ?></strong> 
                    <?php 
                    if ($date_filter === 'from') {
                        printf(__('Post dal %s', 'category-auto-remover'), $date_from);
                    } elseif ($date_filter === 'range') {
                        printf(__('Post dal %s al %s', 'category-auto-remover'), $date_from, $date_to);
                    }
                    ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Regole che verranno applicate', 'category-auto-remover'); ?></h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Categoria Trigger', 'category-auto-remover'); ?></th>
                            <th><?php esc_html_e('Categorie da Rimuovere', 'category-auto-remover'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $rule): 
                            $trigger_cat = get_term($rule['trigger'], 'category');
                            $remove_cats = array_map(function($id) { return get_term($id, 'category'); }, $rule['remove']);
                        ?>
                        <tr>
                            <td>
                                <?php if ($trigger_cat && !is_wp_error($trigger_cat)): ?>
                                    <strong><?php echo esc_html($trigger_cat->name); ?></strong> (ID: <?php echo esc_html($trigger_cat->term_id); ?>)
                                <?php else: ?>
                                    <em><?php esc_html_e('Categoria non trovata', 'category-auto-remover'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $valid_cats = array_filter($remove_cats, function($cat) { return $cat && !is_wp_error($cat); });
                                if (!empty($valid_cats)):
                                    $cat_names = array_map(function($cat) { return $cat->name . ' (ID: ' . $cat->term_id . ')'; }, $valid_cats);
                                    echo esc_html(implode(', ', $cat_names));
                                else:
                                    echo '<em>' . esc_html__('Nessuna categoria valida', 'category-auto-remover') . '</em>';
                                endif;
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Avvia Processo', 'category-auto-remover'); ?></h2>
                <p><?php esc_html_e('Clicca il pulsante qui sotto per iniziare ad applicare le regole ai post esistenti.', 'category-auto-remover'); ?></p>
                
                <button type="button" id="car-start-bulk" class="button button-primary button-large">
                    <?php esc_html_e('Applica Regole ai Post Esistenti', 'category-auto-remover'); ?>
                </button>
                
                <div id="car-progress" style="display: none; margin-top: 20px;">
                    <div class="progress-bar" style="width: 100%; background-color: #f0f0f0; border-radius: 3px;">
                        <div id="car-progress-bar" style="width: 0%; height: 20px; background-color: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
                    </div>
                    <p id="car-progress-text"><?php esc_html_e('Preparazione...', 'category-auto-remover'); ?></p>
                </div>
                
                <div id="car-results" style="display: none; margin-top: 20px;">
                    <h3><?php esc_html_e('Risultati', 'category-auto-remover'); ?></h3>
                    <div id="car-results-content"></div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Gestione filtri data
            $('input[name="date_filter"]').on('change', function() {
                var value = $(this).val();
                if (value === 'from') {
                    $('#date-from-row').show();
                    $('#date-to-row').hide();
                } else if (value === 'range') {
                    $('#date-from-row').show();
                    $('#date-to-row').show();
                } else {
                    $('#date-from-row').hide();
                    $('#date-to-row').hide();
                }
            });
            
            $('#car-start-bulk').on('click', function() {
                var button = $(this);
                var progress = $('#car-progress');
                var progressBar = $('#car-progress-bar');
                var progressText = $('#car-progress-text');
                var results = $('#car-results');
                var resultsContent = $('#car-results-content');
                
                button.prop('disabled', true);
                progress.show();
                results.hide();
                
                var processed = 0;
                var total = <?php echo $filtered_posts; ?>;
                var updated = 0;
                var skipped = 0;
                
                function processBatch() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'car_apply_bulk_rules',
                            processed: processed,
                            date_filter: '<?php echo esc_js($date_filter); ?>',
                            date_from: '<?php echo esc_js($date_from); ?>',
                            date_to: '<?php echo esc_js($date_to); ?>',
                            nonce: '<?php echo wp_create_nonce('car_bulk_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                processed += response.data.processed;
                                updated += response.data.updated;
                                skipped += response.data.skipped;
                                
                                var percentage = Math.round((processed / total) * 100);
                                progressBar.css('width', percentage + '%');
                                progressText.text('Processati: ' + processed + '/' + total + ' (' + percentage + '%)');
                                
                                if (response.data.finished) {
                                    button.prop('disabled', false);
                                    progressText.text('<?php esc_html_e('Completato!', 'category-auto-remover'); ?>');
                                    
                                    resultsContent.html(
                                        '<p><strong><?php esc_html_e('Post processati:', 'category-auto-remover'); ?></strong> ' + processed + '</p>' +
                                        '<p><strong><?php esc_html_e('Post aggiornati:', 'category-auto-remover'); ?></strong> ' + updated + '</p>' +
                                        '<p><strong><?php esc_html_e('Post saltati:', 'category-auto-remover'); ?></strong> ' + skipped + '</p>'
                                    );
                                    results.show();
                                } else {
                                    setTimeout(processBatch, 100);
                                }
                            } else {
                                alert('Errore: ' + response.data);
                                button.prop('disabled', false);
                            }
                        },
                        error: function() {
                            alert('<?php esc_html_e('Errore durante il processo', 'category-auto-remover'); ?>');
                            button.prop('disabled', false);
                        }
                    });
                }
                
                processBatch();
            });
        });
        </script>
        <?php
    }

    /**
     * Conta i post per data
     */
    private function count_posts_by_date($post_types, $date_from = '', $date_to = '') {
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
        ];
        
        if ($date_from) {
            $args['date_query'] = [
                'after' => $date_from . ' 00:00:00',
                'inclusive' => true,
            ];
        }
        
        if ($date_to) {
            if (!isset($args['date_query'])) {
                $args['date_query'] = [];
            }
            $args['date_query']['before'] = $date_to . ' 23:59:59';
            $args['date_query']['inclusive'] = true;
        }
        
        $posts = get_posts($args);
        return count($posts);
    }

    /**
     * AJAX handler per applicazione bulk delle regole
     */
    public function ajax_apply_bulk_rules() {
        check_ajax_referer('car_bulk_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }

        $processed = intval($_POST['processed']);
        $batch_size = 10; // Processa 10 post alla volta
        
        // Parametri di filtro data
        $date_filter = sanitize_text_field($_POST['date_filter'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        $rules = get_option(self::OPTION_KEY, []);
        $prefs = wp_parse_args(get_option(self::OPTION_PREFS, []), [
            'post_types' => ['post'],
        ]);
        
        $post_types = (array) $prefs['post_types'];
        $updated = 0;
        $skipped = 0;
        
        // Ottieni i post da processare con filtri data
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'numberposts' => $batch_size,
            'offset' => $processed,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC'
        ];
        
        // Applica filtri data
        if ($date_filter === 'from' && $date_from) {
            $args['date_query'] = [
                'after' => $date_from . ' 00:00:00',
                'inclusive' => true,
            ];
        } elseif ($date_filter === 'range' && $date_from && $date_to) {
            $args['date_query'] = [
                'after' => $date_from . ' 00:00:00',
                'before' => $date_to . ' 23:59:59',
                'inclusive' => true,
            ];
        }
        
        $posts = get_posts($args);
        
        foreach ($posts as $post_id) {
            // Controlla se il post ha regole personalizzate
            $has_custom_rule = (int) get_post_meta($post_id, self::META_ENABLED, true);
            
            if ($has_custom_rule) {
                $skipped++;
                continue;
            }
            
            // Applica le regole globali
            $assigned_set = wp_get_post_categories($post_id, ['fields' => 'ids']);
            if (is_wp_error($assigned_set) || empty($assigned_set)) {
                $skipped++;
                continue;
            }
            
            $assigned_set = array_map('intval', (array)$assigned_set);
            $original_set = $assigned_set;
            $modified = false;
            
            foreach ($rules as $rule) {
                if (!isset($rule['trigger']) || !isset($rule['remove'])) continue;
                
                $trigger = intval($rule['trigger']);
                $remove  = array_map('intval', (array) ($rule['remove'] ?? []));
                
                if ($trigger && in_array($trigger, $assigned_set, true)) {
                    $before = $assigned_set;
                    $assigned_set = array_values(array_diff($assigned_set, $remove));
                    if (!in_array($trigger, $assigned_set, true)) {
                        $assigned_set[] = $trigger;
                    }
                    if ($before !== $assigned_set) $modified = true;
                }
            }
            
            if ($modified && $original_set !== $assigned_set) {
                wp_set_post_categories($post_id, $assigned_set, false);
                $updated++;
            } else {
                $skipped++;
            }
        }
        
        $finished = count($posts) < $batch_size;
        
        wp_send_json_success([
            'processed' => count($posts),
            'updated' => $updated,
            'skipped' => $skipped,
            'finished' => $finished
        ]);
    }
}

// Hook di attivazione/disattivazione
register_activation_hook(__FILE__, function() {
    // Verifica requisiti minimi
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Category Auto Remover richiede PHP 7.4 o superiore.', 'category-auto-remover'));
    }
    
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('Category Auto Remover richiede WordPress 5.0 o superiore.', 'category-auto-remover'));
    }
});

register_deactivation_hook(__FILE__, function() {
    // Cleanup opzionale se necessario
    // Per ora non facciamo nulla, manteniamo i dati
});

new Category_Auto_Remover();
