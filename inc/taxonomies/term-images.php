<?php

namespace NextSafar\Taxonomies;

class TermImages {

    /* ========================================================================
       Get Supported Taxonomies
       ======================================================================== */
    public static function get_supported_taxonomies() {
        return apply_filters('nextsafar_term_images_taxonomies', [
            'hotel_category', 'destination_category', 'restaurant_category',
            'hospital_category', 'airport_category', 'travelnews_category',
        ]);
    }

    /* ========================================================================
       Initialization
       ======================================================================== */
    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue_media']);

        foreach (self::get_supported_taxonomies() as $tax) {
            add_action("{$tax}_add_form_fields", [__CLASS__, 'add_form_fields']);
            add_action("{$tax}_edit_form_fields", [__CLASS__, 'edit_form_fields'], 10, 2);
            add_action("created_{$tax}", [__CLASS__, 'save_term'], 10, 2);
            add_action("edited_{$tax}", [__CLASS__, 'save_term'], 10, 2);

            /* ✅ Image column for each supported taxonomy */
            add_filter("manage_edit-{$tax}_columns", [__CLASS__, 'add_columns']);
            add_filter("manage_{$tax}_custom_column", [__CLASS__, 'render_columns'], 10, 3);
        }

        add_action('admin_head', [__CLASS__, 'admin_styles']);
    }

    /* ========================================================================
       Enqueue Media on Taxonomy Screens
       ======================================================================== */
    public static function maybe_enqueue_media($hook) {
        if (in_array($hook, ['edit-tags.php', 'term.php'])) {
            wp_enqueue_media();
        }
    }

    /* ========================================================================
       Add Form Fields
       ======================================================================== */
    public static function add_form_fields($taxonomy) {
        wp_nonce_field('nextsafar_term_images', 'nextsafar_term_images_nonce');
        ?>
        <div class="form-field term-ns-images-wrap">
            <label>تصاویر دسته‌بندی</label>
            <div class="ns-term-images-grid">
                <?php self::render_image_field('flag_image', 'پرچم کشور', ''); ?>
                <?php self::render_image_field('category_image', 'تصویر دسته', ''); ?>
                <?php self::render_image_field('category_banner', 'بنر دسته', ''); ?>
            </div>
        </div>
        <?php
    }

    /* ========================================================================
       Edit Form Fields
       ======================================================================== */
    public static function edit_form_fields($term, $taxonomy) {
        wp_nonce_field('nextsafar_term_images', 'nextsafar_term_images_nonce');

        $flag    = get_term_meta($term->term_id, 'flag_image', true);
        $cat_img = get_term_meta($term->term_id, 'category_image', true);
        $banner  = get_term_meta($term->term_id, 'category_banner', true);
        ?>
        <tr class="form-field term-ns-images-wrap">
            <th scope="row"><label>تصاویر دسته‌بندی</label></th>
            <td>
                <div class="ns-term-images-grid">
                    <?php self::render_image_field('flag_image', 'پرچم کشور', $flag); ?>
                    <?php self::render_image_field('category_image', 'تصویر دسته', $cat_img); ?>
                    <?php self::render_image_field('category_banner', 'بنر دسته', $banner); ?>
                </div>
            </td>
        </tr>
        <?php
    }

    /* ========================================================================
       Render Image Field Helper
       ======================================================================== */
    private static function render_image_field($name, $label, $value) {
        $preview = $value ? '<img src="' . esc_url($value) . '" style="max-width:120px;">' : '';
        ?>
        <div class="ns-term-image-field">
            <label><?= esc_html($label); ?></label>
            <div class="ns-term-image-controls">
                <button type="button" class="button ns-term-upload-btn" data-target="<?= esc_attr($name); ?>"
                    <?= $value ? 'style="display:none"' : ''; ?>>
                    📁 انتخاب
                </button>
                <button type="button" class="button ns-term-remove-btn" data-target="<?= esc_attr($name); ?>"
                    <?= $value ? '' : 'style="display:none"'; ?>>
                    ✕
                </button>
                <input type="hidden" name="<?= esc_attr($name); ?>"
                    class="ns-term-image-input"
                    value="<?= esc_attr($value); ?>">
                <div class="ns-term-image-preview" data-target="<?= esc_attr($name); ?>">
                    <?= $preview; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ========================================================================
       Save Term
       ======================================================================== */
    public static function save_term($term_id, $tt_id) {
        if (!isset($_POST['nextsafar_term_images_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_term_images_nonce'], 'nextsafar_term_images')) {
            return;
        }

        $fields = ['flag_image', 'category_image', 'category_banner'];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $url = esc_url_raw($_POST[$field]);

                if ($url) {
                    update_term_meta($term_id, $field, $url);
                } else {
                    delete_term_meta($term_id, $field);
                }
            }
        }
    }

    /* ========================================================================
       Image Column in Admin Table
       ======================================================================== */
    public static function add_columns($columns) {
        $new = [];

        foreach ($columns as $key => $val) {
            /* ✅ Image column, right before the name */
            if ($key === 'name') {
                $new['ns_cat_image'] = 'تصویر دسته';
            }

            $new[$key] = $val;
        }

        unset($new['posts']); // Remove default column

        return $new;
    }

    public static function render_columns($content, $column_name, $term_id) {
        /* ✅ Only the category image is displayed.
           Flag (flag_image) and banner (category_banner) remain in the form and database,
           they just don't have a column in the table. */
        if ($column_name === 'ns_cat_image') {
            /* ⚠️ Note: In this class, values are stored as "direct URL"
               not Attachment ID — so we use the value itself as the image address. */
            $img_url = get_term_meta($term_id, 'category_image', true);

            if (!empty($img_url) && filter_var($img_url, FILTER_VALIDATE_URL)) {
                return '<img src="' . esc_url($img_url) . '" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">';
            }

            return '—';
        }

        return $content;
    }

    /* ========================================================================
       Admin Styles and Scripts
       ======================================================================== */
    public static function admin_styles() {
        $screen = get_current_screen();

        if (!$screen || !in_array($screen->taxonomy, self::get_supported_taxonomies())) return;
        ?>
        <style>
        .ns-term-images-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }
        .ns-term-image-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .ns-term-image-controls {
            display: flex;
            gap: 5px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .ns-term-image-preview img {
            max-width: 120px;
            border-radius: 4px;
            margin-top: 8px;
            display: block;
        }
        @media (max-width: 768px) {
            .ns-term-images-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <script>
        jQuery(function($){
            /* ========== Image Upload ========== */
            $(document).on('click', '.ns-term-upload-btn', function(e){
                e.preventDefault();
                var target = $(this).data('target');
                var frame = wp.media({ title: 'انتخاب تصویر', multiple: false });

                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('input.ns-term-image-input[name="'+target+'"]').val(att.url);
                    $('.ns-term-image-preview[data-target="'+target+'"]').html('<img src="'+att.url+'">');

                    /* ✅ After adding image:
                       the "📁 Select" button is hidden and the "✕" button is shown */
                    $('.ns-term-upload-btn[data-target="'+target+'"]').hide();
                    $('.ns-term-remove-btn[data-target="'+target+'"]').show();
                });

                frame.open();
            });

            /* ========== Remove Image ========== */
            $(document).on('click', '.ns-term-remove-btn', function(e){
                e.preventDefault();
                var target = $(this).data('target');
                $('input.ns-term-image-input[name="'+target+'"]').val('');
                $('.ns-term-image-preview[data-target="'+target+'"]').empty();

                /* ✅ After removing image:
                   the "✕" button is hidden and the "📁 Select" button is shown again */
                $(this).hide();
                $('.ns-term-upload-btn[data-target="'+target+'"]').show();
            });
        });
        </script>
        <?php
    }
}