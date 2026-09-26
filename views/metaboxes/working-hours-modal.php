<?php if (!defined('ABSPATH')) exit; ?>
<div id="working-hours-modal" style="display:none;">
    <div class="ns-modal-overlay"></div>

    <div class="ns-modal-content">
        <div class="ns-modal-header">
            <h3>⏰ تنظیم ساعات کاری</h3>
            <button type="button" class="close-modal">✕</button>
        </div>

        <div class="ns-modal-body">
            <p>ساعات کاری <strong id="wh-item-name"></strong>:</p>

            <table class="ns-wh-table">
                <?php
                $days = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
                $day_names = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

                foreach ($days as $i => $day) :
                ?>
                    <tr>
                        <td><?= $day_names[$i]; ?></td>
                        <td><input type="time" class="wh-start" data-day="<?= $day; ?>"></td>
                        <td>تا</td>
                        <td><input type="time" class="wh-end" data-day="<?= $day; ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <input type="hidden" id="wh-current-key">
        </div>

        <div class="ns-modal-footer">
            <button type="button" class="button" id="save-working-hours">ذخیره</button>
            <button type="button" class="button close-modal">انصراف</button>
        </div>
    </div>
</div>