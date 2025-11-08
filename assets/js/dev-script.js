jQuery(document).ready(function ($) {
    const $interval = $('#ff_3_billing_interval');
    const $hiddenStart = $('input[name="billing_start_date"]'); // hidden input

    // Create new select inside the same container as hidden field
    let $visibleSelect = $('#ff_3_billing_start_date_select');
    if (!$visibleSelect.length && $hiddenStart.length) {
        $hiddenStart.after(`
            <div class="ff-el-input--label asterisk-right"><label for="ff_3_billing_start_date_select" aria-label="Billing Interval">Billing Start Date</label></div>
            <div class="ff-el-input--content">
                <select id="ff_3_billing_start_date_select" class="ff-el-form-control">
                    <option value="">Select</option>
                </select>
            </div>
        `);
        $visibleSelect = $('#ff_3_billing_start_date_select');
    }

    // Format date helper
    const formatDate = (d) =>
        d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    function updateBillingStartOptions() {
        const val = $interval.val();
        const today = new Date();

        // Clear old options
        $visibleSelect.empty().append('<option value="">Select</option>');

        // Add "Today"
        $visibleSelect.append(
            `<option value="${formatDate(today)}">Today (${formatDate(today)})</option>`
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
                    `<option value="${formatDate(d)}">${label} (${formatDate(d)})</option>`
                );
            }
        }
    }

    // On interval change, repopulate options
    $interval.on('change', updateBillingStartOptions);

    // When user selects start date → update hidden field
    $(document).on('change', '#ff_3_billing_start_date_select', function () {
        const selected = $(this).val();
        $hiddenStart.val(selected).trigger('change');
    });
});
