<?php

namespace SchemaMarkupManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Schema_Markup_Manager {

    private static $instance = null;

    private $page_slug = 'schema-markup-manager';
    private $option_name = 'schema_markup_custom';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( defined( 'SCHEMA_MARKUP_MANAGER_RUNTIME_LOCK' ) ) {
            return;
        }
        define( 'SCHEMA_MARKUP_MANAGER_RUNTIME_LOCK', 'plugin' );

        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
        add_action( 'admin_menu', [ $this, 'remove_duplicate_menu' ], 999 );
        add_action( 'current_screen', [ $this, 'ensure_single_admin_page_renderer' ], 1 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'init', [ $this, 'disable_plugins_markup' ], 1 );
    }

    public function ensure_single_admin_page_renderer( $screen ) {
        if ( ! is_object( $screen ) || empty( $screen->id ) ) {
            return;
        }

        $hook_name = 'tools_page_' . $this->page_slug;
        if ( $screen->id !== $hook_name ) {
            return;
        }

        // Some themes/plugins can register the same page slug and callback hook twice.
        // Keep only one renderer for this page hook to prevent duplicated UI output.
        remove_all_actions( $hook_name );
        add_action( $hook_name, [ $this, 'render_admin_page' ] );
    }

    public function remove_duplicate_menu() {
        global $submenu;
        if ( empty( $submenu['tools.php'] ) || ! is_array( $submenu['tools.php'] ) ) {
            return;
        }
        $seen = false;
        foreach ( $submenu['tools.php'] as $key => $item ) {
            if ( isset( $item[2] ) && $item[2] === $this->page_slug ) {
                if ( $seen ) {
                    unset( $submenu['tools.php'][ $key ] );
                } else {
                    $seen = true;
                }
            }
        }
    }

    public function disable_plugins_markup() {
        if ( get_option( 'schema_markup_disable_plugins', false ) ) {
            add_filter( 'wpseo_json_ld_output', '__return_false', 999 );
            add_filter( 'rank_math/json_ld', '__return_false', 999 );
            add_filter( 'wpseo_schema_graph_pieces', '__return_empty_array', 999 );
            return;
        }

        if ( get_option( 'schema_markup_disable_yoast_except_breadcrumbs', false ) ) {
            add_filter( 'wpseo_schema_graph', [ $this, 'filter_yoast_graph_keep_breadcrumbs' ], 999, 2 );
        }
    }

    public function filter_yoast_graph_keep_breadcrumbs( $graph ) {
        if ( ! is_array( $graph ) ) {
            return [];
        }

        $is_breadcrumb_item = static function ( $item ) {
            if ( ! is_array( $item ) || empty( $item['@type'] ) ) {
                return false;
            }

            $type = $item['@type'];
            if ( is_string( $type ) ) {
                return $type === 'BreadcrumbList';
            }

            if ( is_array( $type ) ) {
                return in_array( 'BreadcrumbList', $type, true );
            }

            return false;
        };

        return array_values( array_filter( $graph, $is_breadcrumb_item ) );
    }

    public function add_admin_page() {
        add_management_page(
            'Управление микроразметкой',
            'Schema Markup Manager',
            'manage_options',
            $this->page_slug,
            [ $this, 'render_admin_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'schema_markup_settings_group', $this->option_name, [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_schema_markup' ],
            'default'           => '',
        ] );

        register_setting( 'schema_markup_settings_group', 'schema_markup_disable_old', [
            'type'    => 'boolean',
            'default' => false,
        ] );

        register_setting( 'schema_markup_settings_group', 'schema_markup_disable_plugins', [
            'type'    => 'boolean',
            'default' => false,
        ] );

        register_setting( 'schema_markup_settings_group', 'schema_markup_disable_yoast_except_breadcrumbs', [
            'type'    => 'boolean',
            'default' => false,
        ] );

        register_setting( 'schema_markup_settings_group', 'schema_markup_per_page', [
            'type'    => 'array',
            'default' => [],
        ] );

        register_setting( 'schema_markup_settings_group', 'schema_markup_enable_fallback_organization', [
            'type'    => 'boolean',
            'default' => true,
        ] );
    }

    public function sanitize_schema_markup( $value ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $decoded = json_decode( $value, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            add_settings_error(
                $this->option_name,
                'invalid_json',
                'Ошибка: Неверный формат JSON. ' . json_last_error_msg(),
                'error'
            );
            return get_option( $this->option_name, '' );
        }

        return $value;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'У вас нет прав доступа к этой странице.' );
        }

        if ( isset( $_POST['delete_markup'] ) && check_admin_referer( 'schema_markup_delete', 'schema_markup_delete_nonce' ) ) {
            delete_option( $this->option_name );
            $current_markup = '';
            echo '<div class="notice notice-success is-dismissible"><p>Микроразметка успешно удалена!</p></div>';
        }

        if ( isset( $_POST['delete_old_markup'] ) && check_admin_referer( 'schema_markup_delete_old', 'schema_markup_delete_old_nonce' ) ) {
            $options = get_option( 'mytheme_options', [] );
            if ( isset( $options['schema_markup'] ) ) {
                unset( $options['schema_markup'] );
                global $wpdb;
                $serialized = maybe_serialize( $options );
                $wpdb->update(
                    $wpdb->options,
                    [ 'option_value' => $serialized ],
                    [ 'option_name' => 'mytheme_options' ],
                    [ '%s' ],
                    [ '%s' ]
                );
                wp_cache_delete( 'mytheme_options', 'options' );
            }
            echo '<div class="notice notice-success is-dismissible"><p>Старая микроразметка из настроек темы успешно удалена!</p></div>';
            $old_markup   = '';
            $has_old_markup = false;
        }

        if ( isset( $_POST['delete_all_markup'] ) && check_admin_referer( 'schema_markup_delete_all', 'schema_markup_delete_all_nonce' ) ) {
            delete_option( $this->option_name );
            $options = get_option( 'mytheme_options', [] );
            if ( isset( $options['schema_markup'] ) ) {
                unset( $options['schema_markup'] );
                global $wpdb;
                $serialized = maybe_serialize( $options );
                $wpdb->update(
                    $wpdb->options,
                    [ 'option_value' => $serialized ],
                    [ 'option_name' => 'mytheme_options' ],
                    [ '%s' ],
                    [ '%s' ]
                );
                wp_cache_delete( 'mytheme_options', 'options' );
            }
            $current_markup = '';
            $old_markup     = '';
            $has_old_markup = false;
            echo '<div class="notice notice-success is-dismissible"><p>Вся микроразметка (новая и старая) успешно удалена!</p></div>';
        }

        if ( isset( $_POST['submit'] ) && check_admin_referer( 'schema_markup_save', 'schema_markup_nonce' ) ) {
            $schema_markup = isset( $_POST['schema_markup'] ) ? wp_unslash( $_POST['schema_markup'] ) : '';
            update_option( $this->option_name, $schema_markup );

            $disable_old     = isset( $_POST['schema_markup_disable_old'] ) ? 1 : 0;
            $disable_plugins = isset( $_POST['schema_markup_disable_plugins'] ) ? 1 : 0;
            $disable_yoast_except_breadcrumbs = isset( $_POST['schema_markup_disable_yoast_except_breadcrumbs'] ) ? 1 : 0;
            $enable_fallback_organization = isset( $_POST['schema_markup_enable_fallback_organization'] ) ? 1 : 0;
            update_option( 'schema_markup_disable_old', $disable_old );
            update_option( 'schema_markup_disable_plugins', $disable_plugins );
            update_option( 'schema_markup_disable_yoast_except_breadcrumbs', $disable_yoast_except_breadcrumbs );
            update_option( 'schema_markup_enable_fallback_organization', $enable_fallback_organization );

            $per_page_map = get_option( 'schema_markup_per_page', [] );
            if ( ! is_array( $per_page_map ) ) {
                $per_page_map = [];
            }

            $page_id   = isset( $_POST['schema_markup_page_id'] ) ? (int) $_POST['schema_markup_page_id'] : 0;
            $page_json = isset( $_POST['schema_markup_page_json'] ) ? wp_unslash( $_POST['schema_markup_page_json'] ) : '';

            if ( $page_id > 0 ) {
                if ( trim( $page_json ) === '' ) {
                    if ( isset( $per_page_map[ $page_id ] ) ) {
                        unset( $per_page_map[ $page_id ] );
                    }
                } else {
                    $decoded_page = json_decode( $page_json, true );
                    if ( json_last_error() !== JSON_ERROR_NONE ) {
                        add_settings_error(
                            'schema_markup_per_page',
                            'invalid_page_json',
                            'Ошибка: Неверный формат JSON для выбранной страницы. ' . json_last_error_msg(),
                            'error'
                        );
                    } else {
                        $per_page_map[ $page_id ] = $page_json;
                    }
                }
                update_option( 'schema_markup_per_page', $per_page_map );
            }

            echo '<div class="notice notice-success is-dismissible"><p>Настройки микроразметки успешно сохранены!</p></div>';
        }

        $current_markup   = get_option( $this->option_name, '' );
        $is_valid         = true;
        $validation_error = '';

        if ( ! empty( $current_markup ) ) {
            $decoded = json_decode( $current_markup, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $is_valid         = false;
                $validation_error = json_last_error_msg();
            }
        }

        $options        = get_option( 'mytheme_options', [] );
        $old_markup     = $options['schema_markup'] ?? '';
        $has_old_markup = ! empty( $old_markup );

        $per_page_map = get_option( 'schema_markup_per_page', [] );
        if ( ! is_array( $per_page_map ) ) {
            $per_page_map = [];
        }

        $current_page_id = 0;
        if ( isset( $_GET['schema_page'] ) ) {
            $current_page_id = (int) $_GET['schema_page'];
        }
        if ( isset( $_POST['schema_markup_page_id'] ) ) {
            $current_page_id = (int) $_POST['schema_markup_page_id'];
        }

        $current_page_json = '';
        if ( $current_page_id && isset( $per_page_map[ $current_page_id ] ) ) {
            $current_page_json = $per_page_map[ $current_page_id ];
        }

        ?>
        <div class="wrap">
            <h1>Управление микроразметкой (Schema.org JSON-LD)</h1>

            <div class="card" style="max-width: 1200px; margin-top: 20px;">
                <h2>Добавить микроразметку в head сайта</h2>

                <?php if ( ! $is_valid && ! empty( $current_markup ) ) : ?>
                    <div class="notice notice-error">
                        <p><strong>Ошибка валидации JSON:</strong> <?php echo esc_html( $validation_error ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field( 'schema_markup_save', 'schema_markup_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="schema_markup">JSON-LD разметка</label>
                            </th>
                            <td>
                                <textarea
                                    id="schema_markup"
                                    name="schema_markup"
                                    rows="20"
                                    cols="100"
                                    class="large-text code"
                                    style="font-family: 'Courier New', monospace; font-size: 13px;"
                                    placeholder='{"@context":"https://schema.org","@type":"Organization","name":"Название организации","url":"https://example.com"}'
                                ><?php echo esc_textarea( $current_markup ); ?></textarea>
                                <p class="description">
                                    Сюда бабахай джисон в формате щемы
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3 style="margin-top: 30px; margin-bottom: 15px;">Индивидуальная микроразметка для страницы</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_page_id">Страница</label>
                            </th>
                            <td>
                                <?php
                                wp_dropdown_pages( [
                                    'name'             => 'schema_markup_page_id',
                                    'id'               => 'schema_markup_page_id',
                                    'show_option_none' => '— Не выбрано —',
                                    'option_none_value' => '0',
                                    'selected'         => $current_page_id,
                                ] );
                                ?>
                                <p class="description">
                                    Выбери страницу, для которой хочешь задать свою микроразметку. Она перекроет общую.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_page_json">JSON-LD для выбранной страницы</label>
                            </th>
                            <td>
                                <textarea
                                    id="schema_markup_page_json"
                                    name="schema_markup_page_json"
                                    rows="10"
                                    cols="100"
                                    class="large-text code"
                                    style="font-family: 'Courier New', monospace; font-size: 13px;"
                                ><?php echo esc_textarea( $current_page_json ); ?></textarea>
                                <p class="description">
                                    Если оставить поле пустым и сохранить — индивидуальная разметка для этой страницы будет удалена.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3 style="margin-top: 30px; margin-bottom: 15px;">Управление другими источниками микроразметки</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_disable_old">Отключить старую микроразметку</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="schema_markup_disable_old"
                                           name="schema_markup_disable_old"
                                           value="1"
                                        <?php checked( get_option( 'schema_markup_disable_old', false ), true ); ?>>
                                    Отключить вывод старой микроразметки из настроек темы (класс <code>schema-markup-theme</code>)
                                </label>
                                <p class="description">
                                    <?php if ( $has_old_markup ) : ?>
                                        <strong>Внимание:</strong> Старая микроразметка найдена в настройках темы.
                                        При включении этой опции она не будет выводиться на сайте.
                                    <?php else : ?>
                                        Вообще тестовая фигня была, рубим нашу же разметку которая в настройках, но в целом можно не тыкать
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_disable_plugins">Отключить микроразметку плагинов</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="schema_markup_disable_plugins"
                                           name="schema_markup_disable_plugins"
                                           value="1"
                                        <?php checked( get_option( 'schema_markup_disable_plugins', false ), true ); ?>>
                                    Отключить вывод микроразметки от плагинов (Yoast SEO, Rank Math и др.)
                                </label>
                                <p class="description">
                                    Рубим все разметки кроме нашей если нужно
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_disable_yoast_except_breadcrumbs">Отключить Yoast, кроме breadcrumbs</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="schema_markup_disable_yoast_except_breadcrumbs"
                                           name="schema_markup_disable_yoast_except_breadcrumbs"
                                           value="1"
                                        <?php checked( get_option( 'schema_markup_disable_yoast_except_breadcrumbs', false ), true ); ?>>
                                    Отключить микроразметку Yoast SEO, но оставить BreadcrumbList (хлебные крошки)
                                </label>
                                <p class="description">
                                    Полезно, когда нужна только навигационная цепочка от Yoast, а остальную его schema нужно вырубить.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="schema_markup_enable_fallback_organization">Резервная Organization + aggregateRating</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           id="schema_markup_enable_fallback_organization"
                                           name="schema_markup_enable_fallback_organization"
                                           value="1"
                                        <?php checked( get_option( 'schema_markup_enable_fallback_organization', true ), true ); ?>>
                                    Включить автоматический вывод минимальной <code>Organization</code> с рейтингом из отзывов, если другая разметка плагина на странице не сработала
                                </label>
                                <p class="description">
                                    По умолчанию включено. Снимите галочку, чтобы не выводить этот запасной блок (например, только главная с полной разметкой, а на внутренних — без дублирующей Organization).
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <?php submit_button( 'Сохранить микроразметку', 'primary', 'submit', false ); ?>
                        <?php if ( ! empty( $current_markup ) ) : ?>
                            <button type="button"
                                    class="button button-secondary"
                                    id="delete-markup-btn"
                                    style="margin-left: 10px;"
                                    onclick="if(confirm('Вы уверены, что хотите удалить микроразметку? Это действие нельзя отменить.')) { document.getElementById('delete-form').submit(); }">
                                Удалить микроразметку
                            </button>
                        <?php endif; ?>
                    </p>
                </form>

                <?php if ( ! empty( $current_markup ) ) : ?>
                    <form method="post" action="" id="delete-form" style="display: none;">
                        <?php wp_nonce_field( 'schema_markup_delete', 'schema_markup_delete_nonce' ); ?>
                        <input type="hidden" name="delete_markup" value="1">
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( $has_old_markup ) :
                $old_disabled = get_option( 'schema_markup_disable_old', false );
                ?>
                <div class="card" style="max-width: 1200px; margin-top: 20px; border-left: 4px solid <?php echo $old_disabled ? '#d63638' : '#f0b849'; ?>;">
                    <h2><?php echo $old_disabled ? '🚫 Старая микроразметка отключена' : '⚠️ Обнаружена старая микроразметка'; ?></h2>
                    <p class="description">
                        На сайте найдена старая микроразметка из настроек темы. Она выводится с классом <code>schema-markup-theme</code>.
                        <?php if ( $old_disabled ) : ?>
                            <strong style="color: #d63638;">Старая микроразметка отключена через настройки и не выводится на сайте.</strong>
                        <?php elseif ( ! empty( $current_markup ) ) : ?>
                            <strong>Приоритет имеет новая микроразметка</strong> (выводится первой), но старая всё ещё активна.
                        <?php else : ?>
                            <strong>Сейчас выводится только старая микроразметка.</strong>
                        <?php endif; ?>
                    </p>
                    <p>
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field( 'schema_markup_delete_old', 'schema_markup_delete_old_nonce' ); ?>
                            <input type="hidden" name="delete_old_markup" value="1">
                            <button type="submit"
                                    class="button button-secondary"
                                    onclick="return confirm('Вы уверены, что хотите удалить старую микроразметку из настроек темы?');">
                                Удалить старую микроразметку
                            </button>
                        </form>
                        <?php if ( ! empty( $current_markup ) ) : ?>
                            <form method="post" action="" style="display: inline; margin-left: 10px;">
                                <?php wp_nonce_field( 'schema_markup_delete_all', 'schema_markup_delete_all_nonce' ); ?>
                                <input type="hidden" name="delete_all_markup" value="1">
                                <button type="submit"
                                        class="button button-secondary"
                                        onclick="return confirm('Вы уверены, что хотите удалить ВСЮ микроразметку (и новую, и старую)?');">
                                    Удалить всю микроразметку
                                </button>
                            </form>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $plugins_disabled = get_option( 'schema_markup_disable_plugins', false );
            if ( $plugins_disabled ) :
                ?>
                <div class="card" style="max-width: 1200px; margin-top: 20px; border-left: 4px solid #00a32a;">
                    <h2>✅ Микроразметка плагинов отключена</h2>
                    <p class="description">
                        Вы включили опцию отключения микроразметки от плагинов.
                        Микроразметка от Yoast SEO, Rank Math и других плагинов не выводится на сайте.
                        <?php if ( ! empty( $current_markup ) ) : ?>
                            <strong>На сайте выводится только ваша микроразметка.</strong>
                        <?php else : ?>
                            <strong>Внимание:</strong> У вас нет сохраненной микроразметки. Добавьте её в поле выше.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $yoast_partially_disabled = get_option( 'schema_markup_disable_yoast_except_breadcrumbs', false );
            if ( $yoast_partially_disabled && ! $plugins_disabled ) :
                ?>
                <div class="card" style="max-width: 1200px; margin-top: 20px; border-left: 4px solid #2271b1;">
                    <h2>✅ Yoast schema ограничена</h2>
                    <p class="description">
                        Включен выборочный режим для Yoast SEO: его микроразметка отключена, но <code>BreadcrumbList</code> (хлебные крошки) остается активной.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $current_markup ) ) :
                $decoded = json_decode( $current_markup, true );
                $preview_content = '';
                if ( $decoded !== null && json_last_error() === JSON_ERROR_NONE ) {
                    $formatted       = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                    $preview_content = esc_html( $formatted );
                } else {
                    $preview_content = esc_html( $current_markup );
                }
                ?>
                <div class="card" style="max-width: 1200px; margin-top: 20px;">
                    <h2>Предпросмотр</h2>
                    <p class="description">Как будет выглядеть микроразметка в коде:</p>
                    <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto;"><code>&lt;script type="application/ld+json"&gt;
<?php echo $preview_content; ?>
&lt;/script&gt;</code></pre>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $per_page_map ) ) : ?>
                <div class="card" style="max-width: 1200px; margin-top: 20px;">
                    <h2>Страницы с индивидуальной микроразметкой</h2>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th>Страница</th>
                            <th>URL</th>
                            <th>Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $per_page_map as $page_id => $json ) :
                            $page_id = (int) $page_id;
                            $page   = get_post( $page_id );
                            if ( ! $page ) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( get_the_title( $page_id ) ); ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank">
                                        <?php echo esc_url( get_permalink( $page_id ) ); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . $this->page_slug . '&schema_page=' . $page_id ) ); ?>" class="button">
                                        Редактировать
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
