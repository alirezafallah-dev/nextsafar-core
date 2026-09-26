<?php
if (!defined('ABSPATH')) exit;

// Internal keys remain Persian for backward compatibility with saved data.
$week_days = [
    'شنبه'      => 'Saturday',
    'یکشنبه'    => 'Sunday',
    'دوشنبه'    => 'Monday',
    'سه‌شنبه'   => 'Tuesday',
    'چهارشنبه'  => 'Wednesday',
    'پنج‌شنبه'  => 'Thursday',
    'جمعه'      => 'Friday',
];

$work_time = get_post_meta($post->ID, '_restaurant_work_time', true);
if (!is_array($work_time)) $work_time = [];
?>
<div class="nextsafar-hours-metabox">
    <style>
        .ns-hours-day { margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
        .ns-hours-day-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-weight: bold; }
        .ns-hours-day-name { min-width: 80px; }
        .ns-hours-slots { padding-left: 20px; }
        .ns-hours-slot { display: flex; gap: 10px; margin-bottom: 5px; align-items: center; }
        .ns-hours-slot input[type="time"] { padding: 4px; }
        .ns-hours-slot .remove-slot { color: #a00; cursor: pointer; padding: 2px 8px; }
        .ns-add-slot { margin-top: 5px; padding: 3px 10px; font-size: 12px; }
    </style>

    <p class="description">⏰ برای هر روز می‌توانید چند بازه زمانی تعریف کنید (مثلاً صبح و عصر)</p>

    <?php foreach ($week_days as $day) :
        $slots = $work_time[$day] ?? [];
        
        // تعیین option پیش‌فرض
        $option = 'custom';
        if (empty($slots)) {
            $option = 'custom';
        } elseif (count($slots) === 1) {
            if (($slots[0]['from'] ?? '') === 'off') $option = 'off';
            elseif (($slots[0]['from'] ?? '') === '24h') $option = '24h';
        }
    ?>
    <div class="ns-hours-day" data-day="<?= esc_attr($day); ?>">
        <div class="ns-hours-day-header">
            <span class="ns-hours-day-name"><?= esc_html($day); ?></span>
            
            <label>
                <input type="radio" name="restaurant_work_time_option[<?= esc_attr($day); ?>]" 
                       value="custom" <?= checked($option, 'custom'); ?> class="ns-hours-option">
                فعال
            </label>
            
            <label>
                <input type="radio" name="restaurant_work_time_option[<?= esc_attr($day); ?>]" 
                       value="off" <?= checked($option, 'off'); ?> class="ns-hours-option">
                تعطیل
            </label>
            
            <label>
                <input type="radio" name="restaurant_work_time_option[<?= esc_attr($day); ?>]" 
                       value="24h" <?= checked($option, '24h'); ?> class="ns-hours-option">
                ۲۴ ساعته
            </label>
        </div>

        <div class="ns-hours-slots" style="<?= $option !== 'custom' ? 'display:none;' : ''; ?>">
            <?php if (empty($slots)) : ?>
                <div class="ns-hours-slot">
                    <input type="time" name="restaurant_work_time[<?= esc_attr($day); ?>][from][]" value="">
                    <span>تا</span>
                    <input type="time" name="restaurant_work_time[<?= esc_attr($day); ?>][to][]" value="">
                    <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
                </div>
            <?php else : ?>
                <?php foreach ($slots as $slot) : ?>
                    <div class="ns-hours-slot">
                        <input type="time" name="restaurant_work_time[<?= esc_attr($day); ?>][from][]" 
                               value="<?= esc_attr($slot['from'] ?? ''); ?>">
                        <span>تا</span>
                        <input type="time" name="restaurant_work_time[<?= esc_attr($day); ?>][to][]" 
                               value="<?= esc_attr($slot['to'] ?? ''); ?>">
                        <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <button type="button" class="button ns-add-slot" onclick="addRestaurantTimeSlot(this)">
                ➕ افزودن بازه زمانی
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
function addRestaurantTimeSlot(button) {
    const slotsContainer = button.parentElement;
    const day = slotsContainer.closest('.ns-hours-day').dataset.day;
    
    const newSlot = document.createElement('div');
    newSlot.className = 'ns-hours-slot';
    newSlot.innerHTML = `
        <input type="time" name="restaurant_work_time[${day}][from][]">
        <span>تا</span>
        <input type="time" name="restaurant_work_time[${day}][to][]">
        <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
    `;
    
    slotsContainer.insertBefore(newSlot, button);
}

// Toggle slot visibility based on selected option
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ns-hours-option').forEach(radio => {
        radio.addEventListener('change', function() {
            const day = this.closest('.ns-hours-day');
            const slots = day.querySelector('.ns-hours-slots');
            
            if (this.value === 'custom' && this.checked) {
                slots.style.display = '';
            } else if (this.checked) {
                slots.style.display = 'none';
            }
        });
    });
});
</script>