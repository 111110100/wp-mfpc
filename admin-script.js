jQuery(document).ready(function($) {

    // Use the localized variable instead of embedded PHP
    // const optionName = '<?php echo esc_js(MFPC_OPTION_NAME); ?>'; // REMOVE THIS LINE
    const optionName = mfpcConfigData.optionName; // Use the object passed by wp_localize_script
    const noCacheText = mfpcConfigData.noCacheText; // Use localized text

    console.log('MFPC Admin Script (Localized) Loaded. Option Name:', optionName);

    // --- Server Management ---
    $('#mfpc-add-server').on('click', function() {
        console.log("Add Server clicked");
        const template = $('#mfpc-server-template').html();
        if (!template) { console.error("Server template not found!"); return; }
        const newRow = $(template);
        const $tbody = $('#mfpc-servers-body');

        // Remove placeholder if it's the only row
        const $placeholder = $tbody.find('tr.mfpc-hidden-template');
        if ($placeholder.length && $tbody.find('tr').length === 1) {
            $placeholder.remove();
        }

        // Calculate index based on *current* number of rows before adding
        const newIndex = $tbody.find('tr').length;
        console.log("Calculated new server index:", newIndex);

        // Update name attributes using the localized optionName
        newRow.find('input.regular-text').attr('name', `${optionName}[servers][${newIndex}][host]`);
        newRow.find('input.small-text').attr('name', `${optionName}[servers][${newIndex}][port]`);
        console.log(`Setting server names for index ${newIndex}`);

        $tbody.append(newRow);
    });

    // --- Rule Management ---
     $('#mfpc-add-rule').on('click', function() {
        console.log("Add Rule clicked");
        const template = $('#mfpc-rule-template').html();
        if (!template) { console.error("Rule template not found!"); return; }
        const newRow = $(template);
        const $tbody = $('#mfpc-rules-body');

        // Remove placeholder if it's the only row
        const $placeholder = $tbody.find('tr.mfpc-hidden-template');
        if ($placeholder.length && $tbody.find('tr').length === 1) {
            $placeholder.remove();
        }

        // Calculate index based on *current* number of rows before adding
        const newIndex = $tbody.find('tr').length;
        console.log("Calculated new rule index:", newIndex);

        // Update name attributes using the localized optionName
        newRow.find('input.regular-text').attr('name', `${optionName}[rules][${newIndex}][path]`);
        newRow.find('input.small-text.mfpc-time-input').attr('name', `${optionName}[rules][${newIndex}][time]`);
        console.log(`Setting rule names for index ${newIndex}`);

        // Update human time display
        newRow.find('.mfpc-human-time').text(secondsToHumanTime(0));

        $tbody.append(newRow);
    });


    // --- Generic Remove Row (No Re-indexing) ---
    $('body').on('click', '.mfpc-remove-row', function() {
        console.log("Remove Row clicked");
        const $row = $(this).closest('tr');
        const $tbody = $row.closest('tbody');
        const section = $tbody.attr('id').includes('servers') ? 'servers' : 'rules';

        $row.remove();

        // If last row removed, add back a hidden template row
        if ($tbody.find('tr').length === 0) {
             console.log("Table empty, adding hidden placeholder for section:", section);
             const templateId = section === 'servers' ? '#mfpc-server-template' : '#mfpc-rule-template';
             const template = $(templateId).html();
             if (!template) { console.error("Cannot find template to add placeholder:", templateId); return; }
             const blankRow = $(template).addClass('mfpc-hidden-template');

             // Set index 0 for the placeholder row names using the localized optionName
             blankRow.find('input.regular-text').attr('name', `${optionName}[${section}][0][${section === 'servers' ? 'host' : 'path'}]`).val('');
             blankRow.find('input.small-text').attr('name', `${optionName}[${section}][0][${section === 'servers' ? 'port' : 'time'}]`).val('');
             if (section === 'rules') {
                 blankRow.find('.mfpc-human-time').text(secondsToHumanTime(0));
             }
             console.log(`Setting placeholder names for index 0`);
             $tbody.append(blankRow);
        }
        // No call to updateRowIndices here
    });

    // --- Live update for human-readable time ---
    function secondsToHumanTime(seconds) {
        seconds = parseInt(seconds, 10);
        // Use localized text for "No cache"
        if (isNaN(seconds) || seconds <= 0) { return noCacheText; }
        const periods = { day: 86400, hour: 3600, minute: 60, second: 1 };
        let remainingSeconds = seconds;
        const parts = [];
        for (const name in periods) {
            if (remainingSeconds >= periods[name]) {
                const count = Math.floor(remainingSeconds / periods[name]);
                parts.push(count + ' ' + name + (count > 1 ? 's' : ''));
                remainingSeconds %= periods[name];
            }
        }
        return parts.length > 0 ? parts.join(', ') : '0 seconds';
    }

    $('#mfpc-rules-body').on('input change', '.mfpc-time-input', function() {
        const seconds = $(this).val();
        const $humanTimeCell = $(this).closest('tr').find('.mfpc-human-time');
        $humanTimeCell.text(secondsToHumanTime(seconds));
    });

     // Initial setup on page load
     console.log("Running initial setup on page load (Localized).");
     // Remove initial hidden template if rows already exist
    if ($('#mfpc-servers-body tr:not(.mfpc-hidden-template)').length > 0) {
        $('#mfpc-servers-body .mfpc-hidden-template').remove();
    }
     if ($('#mfpc-rules-body tr:not(.mfpc-hidden-template)').length > 0) {
        $('#mfpc-rules-body .mfpc-hidden-template').remove();
    }

    // Initial calculation for existing rows' human time
    $('#mfpc-rules-body .mfpc-time-input').trigger('change');

    console.log("MFPC Admin Script (Localized) Initialized.");
});
