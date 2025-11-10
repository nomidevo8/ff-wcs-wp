jQuery(document).ready(function ($) {
    const $interval = $('#ff_2_billing_interval');
    const $hiddenStart = $('input[name="billing_start_date"]'); // hidden input

    // Create new select inside the same container as hidden field
    let $visibleSelect = $('#ff_2_billing_start_date_select');
    if (!$visibleSelect.length && $hiddenStart.length) {
        $hiddenStart.after(`
            <div class="ff-el-input--label asterisk-right">
                <label for="ff_2_billing_start_date_select" aria-label="Billing Start Date">
                    Billing Start Date
                </label>
            </div>
            <div class="ff-el-input--content">
                <select id="ff_2_billing_start_date_select" class="ff-el-form-control">
                    <option value="">Select</option>
                </select>
            </div>
        `);
        $visibleSelect = $('#ff_2_billing_start_date_select');
    }

    // Format date for display (human-readable)
    const formatDateDisplay = (d) =>
        d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    // Format date for value (MySQL-compatible: YYYY-MM-DD)
    const formatDateValue = (d) => {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    function updateBillingStartOptions() {
        const val = $interval.val();
        const today = new Date();
        today.setHours(0, 0, 0, 0); // Normalize to midnight

        // Clear old options
        $visibleSelect.empty().append('<option value="">Select</option>');

        // Add "Today"
        $visibleSelect.append(
            `<option value="${formatDateValue(today)}">Today (${formatDateDisplay(today)})</option>`
        );

        let stepDays = 0,
            stepMonths = 0,
            stepYears = 0;

        switch (val) {
            case 'daily':
                stepDays = 1;
                break;
            case 'weekly':
                stepDays = 7;
                break;
            case 'every_two_week':
                stepDays = 14;
                break;
            case 'monthly':
                stepMonths = 1;
                break;
            case 'yearly':
                stepYears = 1;
                break;
        }

        if (stepDays || stepMonths || stepYears) {
            for (let i = 1; i <= 3; i++) {
                const d = new Date(today);
                if (stepDays) d.setDate(today.getDate() + stepDays * i);
                if (stepMonths) d.setMonth(today.getMonth() + stepMonths * i);
                if (stepYears) d.setFullYear(today.getFullYear() + stepYears * i);
                
                const label = `Next ${i} ${val.replace(/_/g, ' ')}${i > 1 ? 's' : ''}`;
                $visibleSelect.append(
                    `<option value="${formatDateValue(d)}">${label} (${formatDateDisplay(d)})</option>`
                );
            }
        }

        // Debug: Log the hidden field value
        console.log('Hidden field name:', $hiddenStart.attr('name'));
        console.log('Hidden field value:', $hiddenStart.val());
    }

    // On interval change, repopulate options
    $interval.on('change', updateBillingStartOptions);

    // When user selects start date → update hidden field
    $(document).on('change', '#ff_2_billing_start_date_select', function () {
        const selected = $(this).val();
        $hiddenStart.val(selected).trigger('change');
        
        // Debug logging
        console.log('Start date selected:', selected);
        console.log('Hidden field updated to:', $hiddenStart.val());
    });

    // Initialize on page load if interval already has a value
    if ($interval.val()) {
        updateBillingStartOptions();
    }
});



jQuery(document).ready(function($) {
    const $cardNumber = $('#ff_2_donor_card_number');
    const $expiry = $('#ff_2_donor_expiry_date');
    const $cvc = $('#ff_2_donor_security_code');

    // --- Card Number: digits only, max 16 ---
    $cardNumber.on('input', function() {
        let val = $(this).val();
        val = val.replace(/\D/g, '').slice(0, 16);
        $(this).val(val);
    });

    // --- Expiry: format MM/YY ---
    $expiry.on('input', function() {
        let val = $(this).val();
        val = val.replace(/\D/g, '').slice(0,4);
        if(val.length >= 3) {
            val = val.slice(0,2) + '/' + val.slice(2);
        }
        $(this).val(val);
    });

    $expiry.on('blur', function() {
        let parts = $(this).val().split('/');
        if(parts.length === 2) {
            let month = parseInt(parts[0], 10);
            if(month < 1) month = '01';
            if(month > 12) month = '12';
            let year = parts[1];
            $(this).val((month < 10 ? '0' + month : month) + '/' + year);
        }
    });

    // --- CVC: digits only, max 4 ---
    $cvc.on('input', function() {
        let val = $(this).val();
        val = val.replace(/\D/g, '').slice(0, 4);
        $(this).val(val);
    });
});
